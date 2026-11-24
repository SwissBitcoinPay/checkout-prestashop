<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\Checker;
use Sattleme\PrestaShop\Payment\Phase;
use Sattleme\PrestaShop\Payment\Schedule;
use Sattleme\PrestaShop\Tests\Support\ShopTestCase;

/**
 * What a check of the server finds, applied to the order: only a paid status of getStatus marks it
 * paid, exactly once, through PrestaShop's own path, with the reference as the transaction id.
 */
final class ChecksTest extends ShopTestCase
{
    private function check(\Order $order): Attempt
    {
        $attempt = $this->attempt((int) $order->id_cart);
        self::assertTrue($this->module->checker()->check($attempt->id, 0, 5), 'checked');

        return $this->attempt((int) $order->id_cart);
    }

    /** @return list<int> the order's states, oldest first */
    private static function history(\Order $order): array
    {
        return array_map(
            static fn (array $row): int => Database::int($row['id_order_state'] ?? null),
            Database::rows('SELECT `id_order_state` FROM `' . _DB_PREFIX_ . 'order_history` WHERE `id_order` = ' . (int) $order->id . ' ORDER BY `id_order_history`'),
        );
    }

    public function testAnOpenInvoiceChangesNothing(): void
    {
        $order = $this->checkout();
        $attempt = $this->check($order);
        self::assertSame(Attempt::OPEN, $attempt->state);
        self::assertSame('open', $attempt->statusState());
        self::assertSame(Config::awaitingState(), self::state($order));
        self::assertNotNull($attempt->checkedAt);
        self::assertSame('', $attempt->error);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function paidStatuses(): iterable
    {
        yield 'lightning' => [['state' => 'paid', 'method' => 'lightning'], 'lightning'];
        yield 'on-chain' => [['state' => 'paid', 'method' => 'onchain', 'received' => '23800'], 'onchain'];
        yield 'mixed' => [['state' => 'paid', 'method' => 'mixed', 'received' => '10000'], 'mixed'];
    }

    /** @param array<string, mixed> $status */
    #[\PHPUnit\Framework\Attributes\DataProvider('paidStatuses')]
    public function testAPaidStatusPaysTheOrderOnce(array $status, string $method): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, $status);
        $paid = $this->check($order);

        self::assertSame(Attempt::PAID, $paid->state);
        self::assertTrue($paid->final);
        self::assertNotNull($paid->completedAt);
        self::assertSame($method, $paid->method());
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
        $payments = self::payments($order);
        self::assertCount(1, $payments);
        self::assertSame($attempt->ref, $payments[0]['transaction_id']);
        self::assertSame(\Sattleme::PAYMENT_NAME, $payments[0]['payment_method']);
        self::assertSame(0, \Sattleme\PrestaShop\Payment\Amount::compare($attempt->amount, Database::string($payments[0]['amount'])));
        // PrestaShop's own path: the invoice made, the payment tied to it, nothing left to pay.
        $reloaded = self::reload($order);
        self::assertGreaterThan(0, (int) $reloaded->invoice_number);
        $invoices = $reloaded->getInvoicesCollection()->getResults();
        self::assertCount(1, $invoices);
        foreach ($invoices as $invoice) {
            self::assertInstanceOf(\OrderInvoice::class, $invoice);
            self::assertEqualsWithDelta(0.0, (float) $invoice->getRestPaid(), 0.001);
        }

        // Checked again, by the page, the background, the merchant: nothing more.
        self::assertFalse($this->module->checker()->check($paid->id, 0, 5), 'a final attempt is not checked again');
        $this->module->settler()->apply($paid, \Sattleme\PrestaShop\Tests\Fake\FakeGateway::status($status, $paid->ref, 0), Clock::now());
        self::assertCount(1, self::payments($order));
        self::assertSame([Config::awaitingState(), self::os('PS_OS_PAYMENT')], self::history($order));
    }

    public function testWhatCameOtherThanTheInvoicesAmountIsNoted(): void
    {
        // Less, which the merchant's tolerance accepted: paid, and noted.
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $less = (string) ((int) $attempt->sats() - 300);
        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'onchain', 'received' => $less]);
        $this->check($order);
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
        self::assertStringContainsString('Bitcoin payment ' . $attempt->ref . ': ' . $less . ' of ' . $attempt->sats() . ' sats came on-chain, which the sattle.me app accepted as the payment (your tolerance, or your acceptance of what came).', self::note($order));

        // More, on-chain and by the rest's invoices: noted too.
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $more = (string) ((int) $attempt->sats() + 1500);
        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'mixed', 'received' => $more]);
        $this->check($order);
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
        self::assertStringContainsString('Bitcoin payment ' . $attempt->ref . ': ' . $more . ' sats came on-chain and in Lightning (the rest of an underpayment) for an invoice of ' . $attempt->sats() . ' sats, 1500 sats more, which reached your wallet', self::note($order));

        // The amount, or Lightning (an invoice is paid for its amount): nothing to note.
        foreach ([['method' => 'onchain', 'received' => null], ['method' => 'lightning']] as $paid) {
            $order = $this->checkout();
            $attempt = $this->attempt((int) $order->id_cart);
            $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => $paid['method'], 'received' => $paid['received'] ?? $attempt->sats()]);
            $this->check($order);
            self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
            self::assertSame('', self::note($order));
        }
    }

    public function testAnUnderpaymentWaitsForTheRest(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'open', 'received' => '10000', 'rest' => ['sats' => '13800']]);
        $checked = $this->check($order);
        self::assertSame(Attempt::OPEN, $checked->state);
        self::assertSame('10000', $checked->received());
        self::assertSame('13800', $checked->rest()['sats'] ?? null);
        self::assertSame(Config::awaitingState(), self::state($order));
        [$phase] = $this->module->phaseOf($order);
        self::assertSame(Phase::UNDERPAID, $phase);
    }

    public function testBeingConfirmedThenPaid(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'unconfirmed', 'received' => '23800', 'confirmations' => 1]);
        $confirming = $this->check($order);
        self::assertSame(Attempt::UNCONFIRMED, $confirming->state);
        self::assertFalse($confirming->final);
        self::assertSame(Config::confirmingState(), self::state($order));
        self::assertSame([], self::payments($order));

        // Its transaction replaced or dropped: back to awaiting payment.
        $this->fake->setStatus($attempt->ref, ['state' => 'open']);
        self::assertSame(Attempt::OPEN, $this->check($order)->state);
        self::assertSame(Config::awaitingState(), self::state($order));

        $this->fake->setStatus($attempt->ref, ['state' => 'unconfirmed', 'received' => '23800', 'confirmations' => 2]);
        $this->check($order);
        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'onchain', 'received' => '23800']);
        self::assertSame(Attempt::PAID, $this->check($order)->state);
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
        self::assertSame(
            [Config::awaitingState(), Config::confirmingState(), Config::awaitingState(), Config::confirmingState(), self::os('PS_OS_PAYMENT')],
            self::history($order),
        );
    }

    public function testAnOrderTheMerchantAcceptedUnderpaidIsPaid(): void
    {
        // The merchant's acceptance (SPEC.md §31): being confirmed, then paid, received far below
        // the invoice's sats; the order paid as any other, at the invoice's price, never "to check".
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $accepted = (string) intdiv((int) $attempt->sats() * 6, 10);
        $this->fake->setStatus($attempt->ref, ['state' => 'unconfirmed', 'received' => $accepted, 'confirmations' => 1]);
        self::assertSame(Attempt::UNCONFIRMED, $this->check($order)->state);
        self::assertSame(Config::confirmingState(), self::state($order));

        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'onchain', 'received' => $accepted]);
        $paid = $this->check($order);
        self::assertSame(Attempt::PAID, $paid->state);
        self::assertTrue($paid->final);
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
        self::assertSame([Config::awaitingState(), Config::confirmingState(), self::os('PS_OS_PAYMENT')], self::history($order));
        $payments = self::payments($order);
        self::assertCount(1, $payments);
        self::assertSame($attempt->ref, $payments[0]['transaction_id']);
        self::assertSame(0, \Sattleme\PrestaShop\Payment\Amount::compare($attempt->amount, Database::string($payments[0]['amount'])));
        self::assertStringContainsString('Bitcoin payment ' . $attempt->ref . ': ' . $accepted . ' of ' . $attempt->sats() . ' sats came on-chain, which the sattle.me app accepted as the payment (your tolerance, or your acceptance of what came).', self::note($order));
        self::assertStringNotContainsString('to check', self::note($order));
    }

    public function testExpiredUnpaidTheCustomerMayPayAgain(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'expired', 'received' => '0']);
        $expired = $this->check($order);
        self::assertSame(Attempt::EXPIRED, $expired->state);
        self::assertTrue($expired->final);
        self::assertSame(Config::awaitingState(), self::state($order));
        self::assertSame('', self::note($order));
        [$phase] = $this->module->phaseOf($order);
        self::assertSame(Phase::EXPIRED, $phase);
        self::assertTrue(\Sattleme\PrestaShop\Payment\Creator::mayRenew($expired, self::orders((int) $order->id_cart)));

        // A new invoice, of a new reference, for the same order.
        $renewed = $this->module->creator()->renew((int) $order->id_cart);
        self::assertNotNull($renewed);
        self::assertNotSame($attempt->ref, $renewed->ref);
        self::assertSame(Attempt::OPEN, $renewed->state);
        self::assertSame((int) $order->id, $renewed->idOrder);
        self::assertSame((string) $order->reference, $renewed->orderReference);
        self::assertSame($attempt->amount, $renewed->amount);
        // One payable invoice per order: no other while this one may be paid.
        self::assertFalse(\Sattleme\PrestaShop\Payment\Creator::mayRenew($renewed, self::orders((int) $order->id_cart)));
        self::assertSame($renewed->id, $this->module->creator()->renew((int) $order->id_cart)?->id);
        self::assertCount(2, $this->fake->calls('create'));
    }

    public function testExpiredUnderpaidIsNotedForTheMerchant(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'expired', 'received' => '12000']);
        $expired = $this->check($order);
        self::assertSame(Attempt::EXPIRED, $expired->state);
        self::assertSame([], self::payments($order));
        self::assertSame(Config::awaitingState(), self::state($order));
        $note = self::note($order);
        // An expired status names no method: what came, against what was asked, not how.
        self::assertStringContainsString('12000 of ' . $attempt->sats() . ' sats came in time, and reached your wallet', $note);
        self::assertStringNotContainsString('on-chain', $note);
        self::assertStringNotContainsString('Lightning', $note);
        self::assertStringContainsString($attempt->ref, $note);
        [$phase] = $this->module->phaseOf($order);
        self::assertSame(Phase::UNDERPAID_EXPIRED, $phase);
        // What reached the merchant's wallet is settled by hand: no new invoice for it.
        self::assertFalse(\Sattleme\PrestaShop\Payment\Creator::mayRenew($expired, self::orders((int) $order->id_cart)));
        // Told once.
        $this->module->settler()->apply($expired, \Sattleme\PrestaShop\Tests\Fake\FakeGateway::status(['state' => 'expired', 'received' => '12000'], $expired->ref, 0), Clock::now());
        self::assertSame($note, self::note($order));
    }

    public function testAPaymentForAnOrderPaidOtherwiseIsNoted(): void
    {
        $order = $this->checkout();
        $reloaded = self::reload($order);
        self::assertTrue($reloaded->addOrderPayment((string) $reloaded->total_paid_tax_incl, 'Bank wire', 'WIRE-1'));
        $history = new \OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState(self::os('PS_OS_PAYMENT'), self::reload($order), true);
        $history->add();

        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'lightning']);
        $this->check($order);
        self::assertCount(2, self::payments($order));
        self::assertStringContainsString('This order was paid otherwise already, and bitcoin payment ' . $attempt->ref . ' came too: ' . $attempt->sats() . ' sats came in Lightning, for an invoice of ' . $attempt->sats() . ' sats (' . $attempt->amount . ' ' . $attempt->currency . '). Refund one of them.', self::note($order));
        self::assertStringNotContainsString(' 0 sats', self::note($order), 'a Lightning payment has no received: never "0 sats"');
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
    }

    public function testAFailedCheckIsCountedLoggedOnceAndScheduledLessOften(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'error', 'error' => 'network']);
        // Past the invoice's end and its grace period: the schedule backs off.
        Clock::freeze((int) $attempt->expiresAt + Schedule::GRACE_SECONDS + 3600);
        $logs = static fn (): int => Database::int(Database::value('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'log` WHERE `message` LIKE ' . Database::quote('%' . $attempt->ref . '%failed: network%')));
        $before = $logs();
        $first = $this->check($order);
        self::assertSame('network', $first->error);
        self::assertSame(1, $first->errors);
        self::assertSame(Clock::now() + 60 - Schedule::SLACK, $first->nextCheckAt);
        $second = $this->check($order);
        self::assertSame(2, $second->errors);
        self::assertSame(Clock::now() + 120 - Schedule::SLACK, $second->nextCheckAt);
        self::assertSame($before + 1, $logs(), 'logged when it starts failing, not at each retry');
        self::assertSame(Attempt::OPEN, $second->state);
        self::assertSame(Config::awaitingState(), self::state($order));

        $this->fake->setStatus($attempt->ref, ['state' => 'expired']);
        $expired = $this->check($order);
        self::assertSame('', $expired->error);
        self::assertSame(0, $expired->errors);
    }

    public function testAPageAsksTheServerAtMostEverySoOften(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        self::assertTrue($this->module->checker()->requestCheck($attempt, Checker::WEB_TIMEOUT));
        $checked = $this->attempt((int) $order->id_cart);
        self::assertFalse($this->module->checker()->requestCheck($checked, Checker::WEB_TIMEOUT));
        self::assertCount(1, $this->fake->calls('status'));
        Clock::freeze(Clock::now() + Schedule::WEB_INTERVAL);
        self::assertTrue($this->module->checker()->requestCheck($checked, Checker::WEB_TIMEOUT));
        self::assertCount(2, $this->fake->calls('status'));
    }

    public function testTwoChecksOfOneInvoiceNeverRunAtOnce(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $repository = new AttemptRepository();
        $now = Clock::now();
        // Another check holds it: this one is skipped, the SDK not asked.
        $first = $now + 60;
        self::assertTrue($repository->claim($attempt->id, $now, $first, 0));
        self::assertFalse($this->module->checker()->check($attempt->id, 0, 5));
        self::assertSame([], $this->fake->calls('status'));
        self::assertFalse($repository->claim($attempt->id, $now + 59, $now + 120, 0));

        // Its lease over, another check takes the invoice; the first, late, writes nothing.
        $second = $now + 200;
        self::assertTrue($repository->claim($attempt->id, $now + 61, $second, 0));
        self::assertFalse($repository->holdsLease($attempt->id, $first));
        self::assertTrue($repository->holdsLease($attempt->id, $second));
        $repository->checkFailed($attempt->id, $first, 'network', $now + 62, $now + 120);
        $repository->release($attempt->id, $first);
        $after = $this->attempt((int) $order->id_cart);
        self::assertSame('', $after->error);
        self::assertSame($second, $after->leaseUntil);

        $repository->release($attempt->id, $second);
        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'lightning']);
        self::assertTrue($this->module->checker()->check($attempt->id, 0, 5));
        self::assertSame(Attempt::PAID, $this->attempt((int) $order->id_cart)->state);
        self::assertNull($this->attempt((int) $order->id_cart)->leaseUntil);
    }
}
