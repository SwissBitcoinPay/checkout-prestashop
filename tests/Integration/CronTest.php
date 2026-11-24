<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Cron\Runner;
use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\Checker;
use Sattleme\PrestaShop\Payment\Schedule;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Tests\Support\ShopTestCase;

/**
 * The background checks (the cron URL's run): the invoices due, the longest due first, as many as
 * fit in the run's time and number; given up after 90 days with a note; one run at a time.
 */
final class CronTest extends ShopTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::putOff();
    }

    /** Puts off the checks of every invoice not final: a test makes its own due. */
    private static function putOff(): void
    {
        Database::execute('UPDATE `' . AttemptRepository::table() . '` SET `next_check_at` = 2000000000 WHERE `final` = 0');
    }

    /** Makes an attempt due since $seconds. */
    private static function due(Attempt $attempt, int $seconds): void
    {
        Database::execute('UPDATE `' . AttemptRepository::table() . '` SET `next_check_at` = ' . (Clock::now() - $seconds) . ' WHERE `id_sattleme_payment` = ' . $attempt->id);
    }

    public function testTheDueInvoicesAreCheckedTheLongestDueFirstAndScheduledAgain(): void
    {
        // One second for the whole run: what it schedules is exact.
        Clock::freeze(time());
        $orders = [$this->checkout(), $this->checkout(), $this->checkout()];
        $attempts = array_map(fn (\Order $order): Attempt => $this->attempt((int) $order->id_cart), $orders);
        self::putOff();
        self::due($attempts[0], 10);
        self::due($attempts[1], 300);
        $this->fake->setStatus($attempts[1]->ref, ['state' => 'paid', 'method' => 'lightning']);

        $result = $this->module->runner()->run(Platform::idShop());
        self::assertSame(['busy' => false, 'checked' => 2, 'skipped' => 0, 'due' => 2, 'abandoned' => 0], $result);
        self::assertSame([$attempts[1]->ref, $attempts[0]->ref], array_column($this->fake->calls('status'), 'ref'));
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($orders[1]));
        $open = $this->attempt((int) $orders[0]->id_cart);
        self::assertSame(Clock::now() + 60 - Schedule::SLACK, $open->nextCheckAt, 'every minute while it can be paid');
        self::assertSame(Config::lastCronRun(Platform::idShop()), Clock::now());

        // Nothing due: nothing checked.
        $again = $this->module->runner()->run(Platform::idShop());
        self::assertSame(0, $again['checked']);
        self::assertCount(2, $this->fake->calls('status'));
    }

    /**
     * A check that takes most of a run is due at the next run all the same, a minute after this
     * one: its next check counts from the run's start, not from its end (else, with a cron every
     * minute, it would wait two).
     */
    public function testASlowCheckIsDueAtTheNextRunAMinuteLater(): void
    {
        $start = time();
        Clock::freeze($start);
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        self::putOff();
        self::due($attempt, 1);
        $this->fake->setStatus($attempt->ref, ['state' => 'open', 'takesSeconds' => 18]);

        self::assertSame(1, $this->module->runner()->run(Platform::idShop())['checked']);
        $checked = $this->attempt((int) $order->id_cart);
        self::assertSame($start + 18, $checked->checkedAt, 'the check ended 18 s into the run');
        self::assertSame($start + 60 - Schedule::SLACK, $checked->nextCheckAt);

        // The next run, a minute after the first started (a second early, as a cron may be).
        Clock::freeze($start + 59);
        self::assertSame(1, $this->module->runner()->run(Platform::idShop())['checked']);
        self::assertCount(2, $this->fake->calls('status'));
    }

    public function testARunStartsNoCheckThatWouldEndAfterItsTime(): void
    {
        $attempts = [];
        foreach ([$this->checkout(), $this->checkout(), $this->checkout()] as $i => $order) {
            $attempts[$i] = $this->attempt((int) $order->id_cart);
        }
        self::putOff();
        foreach ($attempts as $i => $attempt) {
            self::due($attempt, 100 - $i);
        }
        // Each check takes as long as it may (CRON_TIMEOUT): the budget fits two.
        $clock = 0.0;
        $monotonic = static function () use (&$clock): float {
            $now = $clock;
            $clock += Checker::CRON_TIMEOUT / 2;

            return $now;
        };
        $result = $this->module->runner()->run(Platform::idShop(), $monotonic, 2 * Checker::CRON_TIMEOUT);
        self::assertSame(2, $result['checked']);
        self::assertSame(3, $result['due']);
        // The one left, due the least long, is checked at the next run.
        self::assertSame([$attempts[0]->ref, $attempts[1]->ref], array_column($this->fake->calls('status'), 'ref'));
        self::assertNull($this->attempt($attempts[2]->idCart)->checkedAt);
        $next = $this->module->runner()->run(Platform::idShop());
        self::assertSame(1, $next['checked']);
        self::assertSame($attempts[2]->ref, array_column($this->fake->calls('status'), 'ref')[2]);
    }

    public function testAtMostSoManyChecksPerRun(): void
    {
        $attempts = [];
        for ($i = 0; $i < Runner::MAX_CHECKS + 2; ++$i) {
            $attempts[] = $this->attempt((int) $this->checkout()->id_cart);
        }
        self::putOff();
        foreach ($attempts as $attempt) {
            self::due($attempt, 50);
        }
        $result = $this->module->runner()->run(Platform::idShop());
        self::assertSame(Runner::MAX_CHECKS, $result['checked']);
        self::assertSame(2, $this->module->runner()->run(Platform::idShop())['checked']);
    }

    public function testOneRunAtATime(): void
    {
        $pipes = [];
        $holder = proc_open([PHP_BINARY, dirname(__DIR__) . '/Integration/bin/hold-lock.php', 'cron', (string) Platform::idShop(), '3'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($holder);
        self::assertSame("held\n", fgets($pipes[1]));
        $order = $this->checkout();
        self::putOff();
        self::due($this->attempt((int) $order->id_cart), 50);
        self::assertSame(['busy' => true, 'checked' => 0, 'skipped' => 0, 'due' => 0, 'abandoned' => 0], $this->module->runner()->run(Platform::idShop()));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($holder);
        self::assertSame(1, $this->module->runner()->run(Platform::idShop())['checked']);
    }

    public function testAnInvoiceWithoutFinalStatusIsGivenUpAfterNinetyDays(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'unconfirmed', 'received' => '23800', 'confirmations' => 1]);
        $this->module->checker()->check($attempt->id, 0, 5);
        self::assertSame(Config::confirmingState(), self::state($order));

        // Made 90 days ago and a second.
        Database::execute('UPDATE `' . AttemptRepository::table() . '` SET `created_at` = ' . (Clock::now() - Schedule::GIVE_UP_AFTER - 1) . ' WHERE `id_sattleme_payment` = ' . $attempt->id);
        $result = $this->module->runner()->run(Platform::idShop());
        self::assertSame(1, $result['abandoned']);
        $abandoned = $this->attempt((int) $order->id_cart);
        self::assertSame(Attempt::ABANDONED, $abandoned->state);
        self::assertTrue($abandoned->final);
        self::assertStringContainsString('no final status after 90 days', self::note($order));
        self::assertStringContainsString($attempt->ref, self::note($order));
        // Never checked again.
        self::assertFalse($this->module->checker()->check($abandoned->id, 0, 5));
        self::assertSame(0, $this->module->runner()->run(Platform::idShop())['abandoned']);
    }
}
