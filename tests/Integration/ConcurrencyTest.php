<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\CancellationRefusedException;
use Sattleme\PrestaShop\Payment\Creator;
use Sattleme\PrestaShop\Payment\Lock;
use Sattleme\PrestaShop\Payment\LockTimeoutException;
use Sattleme\PrestaShop\Payment\Orders;
use Sattleme\PrestaShop\Tests\Support\ShopTestCase;

/**
 * Two requests at once (a double click, two PHP workers): the cart's lock in the database makes one
 * invoice and one order, and is released whatever way its holder ends.
 */
final class ConcurrencyTest extends ShopTestCase
{
    /**
     * Runs PHP scripts of tests/Integration/bin at once; their outputs.
     *
     * @param list<list<string>> $commands
     *
     * @return list<string>
     */
    private static function runAtOnce(array $commands): array
    {
        $processes = [];
        foreach ($commands as $arguments) {
            $pipes = [];
            $process = proc_open(array_merge([PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__) . '/Integration/bin/' . array_shift($arguments)], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $outputs = [];
        foreach ($processes as [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $output . $errors);
            $outputs[] = $output;
        }

        return $outputs;
    }

    public function testTwoPlaceOrdersAtOnceMakeOneInvoiceAndOneOrder(): void
    {
        $cart = $this->cart();
        // The invoice takes three seconds to make: the second request comes meanwhile.
        $this->fake->setCreate(['delayMs' => 3000]);
        $start = (string) (microtime(true) + 10);
        $outputs = self::runAtOnce([
            ['checkout.php', (string) (int) $cart->id, $start],
            ['checkout.php', (string) (int) $cart->id, $start],
        ]);
        $results = array_map(static fn (string $output): array => (array) json_decode($output, true), $outputs);
        self::assertArrayHasKey('order', $results[0], $outputs[0]);
        self::assertArrayHasKey('order', $results[1], $outputs[1]);
        self::assertSame($results[0]['order'], $results[1]['order']);
        // They ran at once: each started before the other ended.
        self::assertLessThan($results[1]['ended'], $results[0]['started']);
        self::assertLessThan($results[0]['ended'], $results[1]['started']);

        self::assertCount(1, $this->fake->calls('create'));
        self::assertCount(1, self::orders((int) $cart->id));
        self::assertCount(1, (new AttemptRepository())->allForCart((int) $cart->id));
    }

    /**
     * A cancellation while a new invoice of the order is being made (the payment page's "Get a new
     * invoice", which holds the cart's lock from its check that the order may have one to the
     * invoice stored): it waits for the lock, then finds the invoice, and is refused. The order is
     * never canceled with a payable invoice.
     */
    public function testACancellationWhileANewInvoiceIsBeingMadeWaitsAndIsRefused(): void
    {
        $order = $this->checkout();
        $first = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($first->ref, ['state' => 'expired', 'received' => '0']);
        self::assertTrue($this->module->checker()->check($first->id, 0, 5));
        // The new invoice takes four seconds to make.
        $this->fake->setCreate(['delayMs' => 4000]);
        $pipes = [];
        $renew = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__) . '/Integration/bin/renew.php', (string) (int) $order->id_cart], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($renew);
        self::assertSame("started\n", fgets($pipes[1]));
        // Its attempt stored, its invoice not yet: the cancellation comes now.
        $deadline = microtime(true) + 10;
        while ($this->attempt((int) $order->id_cart)->id === $first->id && microtime(true) < $deadline) {
            usleep(50000);
        }
        self::assertSame(Attempt::NEW, $this->attempt((int) $order->id_cart)->state, 'the new invoice is being made');
        $started = microtime(true);
        $history = new \OrderHistory();
        $history->id_order = (int) $order->id;
        try {
            $history->changeIdOrderState(self::os('PS_OS_CANCELED'), self::reload($order));
            self::fail('canceled while its new invoice was being made');
        } catch (CancellationRefusedException $e) {
            self::assertStringContainsString('its bitcoin invoice can still be paid', $e->getMessage());
        }
        self::assertGreaterThan(1.0, microtime(true) - $started, 'it waited for the invoice');
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($renew), $output . $errors);
        self::assertSame(['state' => Attempt::OPEN], json_decode($output, true));
        self::assertSame(Config::awaitingState(), self::state($order));
    }

    /**
     * A cancellation let through, and a new invoice asked while PrestaShop has not written the
     * order's new state yet (another module reacting slowly to the change, after this one: the
     * tests' module sattlemefailing): the invoice's making finds the cart marked as being canceled,
     * then the order canceled. No invoice is made for it.
     */
    public function testANewInvoiceAskedWhileACancellationIsBeingSavedIsNotMade(): void
    {
        $order = $this->checkout();
        $first = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($first->ref, ['state' => 'expired', 'received' => '0']);
        self::assertTrue($this->module->checker()->check($first->id, 0, 5));
        $slow = $this->fake->dir() . '/' . \Sattlemefailing::SLOW;
        file_put_contents($slow, '6');
        $pipes = [];
        try {
            // The new invoice is asked two seconds into the six the change takes.
            $renew = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__) . '/Integration/bin/renew.php', (string) (int) $order->id_cart, (string) (microtime(true) + 2)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($renew);
            self::assertSame("started\n", fgets($pipes[1]));
            $started = microtime(true);
            $history = new \OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState(self::os('PS_OS_CANCELED'), self::reload($order));
            self::assertTrue($history->add());
            self::assertGreaterThan(5.0, microtime(true) - $started, 'the slow module reacted');
        } finally {
            unlink($slow);
        }
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($renew), $output . $errors);
        self::assertSame(['state' => Attempt::EXPIRED], json_decode($output, true), 'no new invoice: the order was canceled');
        self::assertSame(self::os('PS_OS_CANCELED'), self::state($order));
        self::assertCount(1, (new AttemptRepository())->allForCart((int) $order->id_cart));
        // The cart's lock released: another process takes it at once.
        $outputs = self::runAtOnce([['hold-lock.php', 'cart', (string) (int) $order->id_cart, '0']]);
        self::assertSame("held\n", $outputs[0], 'the cart\'s lock released');
    }

    /**
     * Aborts the order's cancellation in this process, which lives on (a worker, a synchronisation
     * daemon): another module, after this one, throws what PrestaShop rethrows.
     */
    private function abortCancellation(\Order $order): void
    {
        $abort = $this->fake->dir() . '/' . \Sattlemefailing::ABORT;
        file_put_contents($abort, '1');
        $history = new \OrderHistory();
        $history->id_order = (int) $order->id;
        try {
            $history->changeIdOrderState(self::os('PS_OS_CANCELED'), self::reload($order));
            self::fail('the cancellation went through');
        } catch (\SattlemefailingAbort) {
            // Aborted by the other module, after this one let it through.
        } finally {
            unlink($abort);
        }
        self::assertSame(Config::awaitingState(), self::state($order));
    }

    /** The order as another process changed it: this one's object cache has it as it was loaded. */
    private static function changedElsewhere(\Order $order): \Order
    {
        \Cache::clean('objectmodel_Order_' . (int) $order->id . '_*');

        return self::reload($order);
    }

    /**
     * Runs a script of tests/Integration/bin, which must end within $seconds: its output.
     *
     * @param list<string> $command
     */
    private static function runWithin(float $seconds, array $command): string
    {
        $started = microtime(true);
        [$output] = self::runAtOnce([$command]);
        self::assertLessThan($seconds, microtime(true) - $started, $command[0] . ' waited');

        return $output;
    }

    /**
     * A cancellation that another module aborts, in a process that lives on: no lock stays held.
     * From other processes, at once: a new invoice is refused while the cart is marked as being
     * canceled, then made once the mark ended (Cancellation::WRITE_SECONDS: its end here written
     * in the database), and its payment checked and recorded.
     */
    public function testACancellationAbortedByAnotherModuleLeavesNoLockHeld(): void
    {
        $order = $this->checkout();
        $first = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($first->ref, ['state' => 'expired', 'received' => '0']);
        self::assertTrue($this->module->checker()->check($first->id, 0, 5));
        $this->abortCancellation($order);
        $cart = (string) (int) $order->id_cart;

        self::assertSame("held\n", self::runWithin(5.0, ['hold-lock.php', 'cart', $cart, '0']), 'the cart\'s lock free');
        self::assertSame("started\n" . json_encode(['state' => Attempt::EXPIRED]) . "\n", self::runWithin(5.0, ['renew.php', $cart]), 'no new invoice while marked');
        self::assertCount(1, (new AttemptRepository())->allForCart((int) $order->id_cart));

        Database::execute('UPDATE `' . AttemptRepository::table() . '` SET `canceling_until` = UNIX_TIMESTAMP() - 1 WHERE `id_cart` = ' . $cart);
        self::assertSame("started\n" . json_encode(['state' => Attempt::OPEN]) . "\n", self::runWithin(5.0, ['renew.php', $cart]), 'a new invoice once the mark ended');
        $second = $this->attempt((int) $order->id_cart);
        self::assertNotSame($first->id, $second->id);

        $this->fake->setStatus($second->ref, ['state' => 'paid', 'method' => 'lightning', 'received' => (string) $second->sats()]);
        self::assertSame(json_encode(['checked' => true, 'state' => Attempt::PAID, 'error' => '']) . "\n", self::runWithin(5.0, ['check.php', (string) $second->id]), 'the payment checked');
        self::assertTrue(Orders::isPaid(self::changedElsewhere($order)), 'the order paid');
    }

    /** A cancellation that another module aborts, in a process that lives on: a later one works, from another process. */
    public function testACancellationAfterOneAbortedByAnotherModuleWorks(): void
    {
        $order = $this->checkout();
        $first = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($first->ref, ['state' => 'expired', 'received' => '0']);
        self::assertTrue($this->module->checker()->check($first->id, 0, 5));
        $this->abortCancellation($order);

        $output = self::runWithin(5.0, ['cancel.php', (string) (int) $order->id]);
        self::assertSame(json_encode(['state' => self::os('PS_OS_CANCELED')]) . "\n", $output);
        self::assertSame(self::os('PS_OS_CANCELED'), (int) self::changedElsewhere($order)->current_state);
    }

    /** A cart's lock held longer than a cancellation waits: refused, the order as it was. */
    public function testACancellationRefusedWhenTheCartStaysLocked(): void
    {
        $order = $this->checkout();
        $first = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($first->ref, ['state' => 'expired', 'received' => '0']);
        self::assertTrue($this->module->checker()->check($first->id, 0, 5));
        $pipes = [];
        $holder = proc_open([PHP_BINARY, dirname(__DIR__) . '/Integration/bin/hold-lock.php', 'cart', (string) (int) $order->id_cart, (string) (Creator::LOCK_WAIT + 5)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($holder);
        self::assertSame("held\n", fgets($pipes[1]));
        $history = new \OrderHistory();
        $history->id_order = (int) $order->id;
        try {
            $history->changeIdOrderState(self::os('PS_OS_CANCELED'), self::reload($order));
            self::fail('canceled while its cart was held');
        } catch (CancellationRefusedException $e) {
            self::assertStringContainsString('a bitcoin invoice of it is being made right now. Try again in a moment.', $e->getMessage());
        } finally {
            proc_terminate($holder, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($holder);
        }
        self::assertSame(Config::awaitingState(), self::state($order));
    }

    public function testTheLockIsTheDatabasesAcrossProcesses(): void
    {
        $cart = $this->cart();
        $pipes = [];
        $holder = proc_open([PHP_BINARY, dirname(__DIR__) . '/Integration/bin/hold-lock.php', 'cart', (string) (int) $cart->id, '4'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($holder);
        self::assertSame("held\n", fgets($pipes[1]));

        self::assertNull(Lock::ifFree('cart', (int) $cart->id, static fn (): bool => true));
        try {
            Lock::with('cart', (int) $cart->id, 1, static fn (): bool => true);
            self::fail('Two holders of one lock');
        } catch (LockTimeoutException) {
            // Another request holds the cart.
        }
        // Another cart's lock is free.
        self::assertTrue(Lock::ifFree('cart', (int) $cart->id + 100000, static fn (): bool => true));

        // Killed while it holds the lock: its connection ends, the lock is released.
        proc_terminate($holder, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($holder);
        self::assertTrue(Lock::with('cart', (int) $cart->id, 10, static fn (): bool => true));
    }

    public function testTheLocksNameIsThisShopsAndFitsTheServersLimit(): void
    {
        $name = Lock::name('cart', 2147483647);
        self::assertLessThanOrEqual(64, strlen($name));
        self::assertMatchesRegularExpression('/^sattleme:[0-9a-f]{16}:cart:2147483647$/', $name);
        self::assertNotSame(Lock::name('cart', 1), Lock::name('cron', 1));
    }
}
