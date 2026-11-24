<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\Creator;
use Sattleme\PrestaShop\Payment\Orders;
use Sattleme\PrestaShop\Payment\Phase;
use Sattleme\PrestaShop\Sdk\Errors;
use Sattleme\PrestaShop\Tests\Support\ShopTestCase;

/**
 * A paid invoice whose recording in PrestaShop fails: another module of the shop fails while it
 * reacts to the payment, with a PHP Error, which PrestaShop's Hook::exec lets through (the tests'
 * module sattlemefailing, tests/shop/sattlemefailing, which tests/shop/shop.sh installs). The
 * invoice is never offered to pay again, and a later check records it, exactly once.
 */
final class CompletionTest extends ShopTestCase
{
    private string $switch;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(\Module::isInstalled('sattlemefailing'), 'the tests\' module that fails on a payment (tests/shop/shop.sh setup installs it)');
        self::assertInstanceOf(\Sattlemefailing::class, \Module::getInstanceByName('sattlemefailing'));
        $this->switch = $this->fake->dir() . '/' . \Sattlemefailing::SWITCH;
    }

    protected function tearDown(): void
    {
        $this->failing(false);
        parent::tearDown();
    }

    /** Whether the module sattlemefailing fails while reacting to a payment. */
    private function failing(bool $failing): void
    {
        if ($failing) {
            touch($this->switch);
        } elseif (is_file($this->switch)) {
            unlink($this->switch);
        }
    }

    /**
     * What the payment page shows of the order, as its controller has it.
     *
     * @return array<string, mixed>
     */
    private function page(\Order $order): array
    {
        [$phase, $attempt] = $this->module->phaseOf(self::reload($order));
        $mayRenew = Creator::mayRenew($attempt, Orders::forCart((int) $order->id_cart));

        return $this->module->payPage()->view((string) $order->reference, $attempt, $phase, $mayRenew, Clock::now(), true);
    }

    /** @return list<int> the order's states, oldest first */
    private static function history(\Order $order): array
    {
        return array_map(
            static fn (array $row): int => Database::int($row['id_order_state'] ?? null),
            Database::rows('SELECT `id_order_state` FROM `' . _DB_PREFIX_ . 'order_history` WHERE `id_order` = ' . (int) $order->id . ' ORDER BY `id_order_history`'),
        );
    }

    /** What the customer is told while the payment is not recorded: received, nothing to pay. */
    private function assertReceivedNothingToPay(\Order $order): void
    {
        $view = $this->page($order);
        self::assertSame(Phase::RECORDING, $view['phase']);
        self::assertFalse($view['payable'], 'an invoice found paid is never offered to pay again');
        self::assertFalse($view['may_renew']);
        $payment = $view['payment'];
        self::assertIsArray($payment);
        foreach (['uri', 'qr', 'lightning', 'address'] as $shown) {
            self::assertNull($payment[$shown], $shown);
        }
        self::assertSame(10, $payment['poll_interval'], 'the page asks for news, and its checks record the payment');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function paidStatuses(): iterable
    {
        yield 'in Lightning' => [['state' => 'paid', 'method' => 'lightning']];
        yield 'on-chain, its amount in full' => [['state' => 'paid', 'method' => 'onchain', 'received' => '23800']];
    }

    /** @param array<string, mixed> $status */
    #[\PHPUnit\Framework\Attributes\DataProvider('paidStatuses')]
    public function testAPaidInvoiceWhoseRecordingFailsIsRecordedOnceByALaterCheck(array $status): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, $status);
        $this->failing(true);
        self::assertTrue($this->module->checker()->check($attempt->id, 0, 5));
        $failed = $this->attempt((int) $order->id_cart);
        self::assertSame('paid', $failed->statusState(), 'the server\'s check found it paid');
        self::assertFalse($failed->final);
        self::assertSame(Errors::APPLY, $failed->error);
        self::assertSame(Config::awaitingState(), self::state($order));
        $this->assertReceivedNothingToPay($order);

        // The next checks (the page's, the background's) record it, once.
        self::assertTrue($this->module->checker()->check($attempt->id, 0, 5));
        self::assertSame(Errors::APPLY, $this->attempt((int) $order->id_cart)->error, 'still failing');
        $this->failing(false);
        self::assertTrue($this->module->checker()->check($attempt->id, 0, 5));
        $paid = $this->attempt((int) $order->id_cart);
        self::assertSame(Attempt::PAID, $paid->state);
        self::assertTrue($paid->final);
        self::assertSame('', $paid->error);
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
        self::assertCount(1, self::payments($order));
        self::assertSame($attempt->ref, self::payments($order)[0]['transaction_id']);
        self::assertSame([Config::awaitingState(), self::os('PS_OS_PAYMENT')], self::history($order));
        self::assertSame(Phase::PAID, $this->page($order)['phase']);
        self::assertFalse($this->module->checker()->check($attempt->id, 0, 5), 'final: never checked again');
        self::assertCount(1, self::payments($order));
    }

    public function testAnOrderPlacedWhileItsReferenceWasPaidIsMadeEvenIfItsRecordingFails(): void
    {
        // "Place order" again after a call whose answer was lost: the SDK reports the reference's
        // invoice paid (OrderPaid), and its recording fails.
        $this->fake->setCreate(['mode' => 'paid']);
        $this->failing(true);
        $order = $this->checkout();
        self::assertTrue(\Validate::isLoadedObject($order), 'the order is made, and the customer goes to its page');
        $attempt = $this->attempt((int) $order->id_cart);
        self::assertSame(Attempt::ORIGIN_PAID, $attempt->origin);
        self::assertSame('paid', $attempt->statusState());
        self::assertSame(Errors::APPLY, $attempt->error);
        self::assertSame(Config::awaitingState(), self::state($order));
        $this->assertReceivedNothingToPay($order);
        self::assertSame($attempt->id, $this->module->creator()->renew((int) $order->id_cart)?->id, 'no new invoice');
        self::assertCount(1, $this->fake->calls('create'));

        // The SDK reports it paid at each check, as it reported it paid at "Place order".
        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'lightning']);
        $this->failing(false);
        self::assertTrue($this->module->checker()->check($attempt->id, 0, 5));
        self::assertSame(Attempt::PAID, $this->attempt((int) $order->id_cart)->state);
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
        self::assertCount(1, self::payments($order));
        self::assertSame([Config::awaitingState(), self::os('PS_OS_PAYMENT')], self::history($order));
    }
}
