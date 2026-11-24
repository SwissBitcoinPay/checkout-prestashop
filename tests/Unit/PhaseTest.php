<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\Phase;
use Sattleme\PrestaShop\Tests\Support\Attempts;

/** What the customer is told of a payment: only what the server's checks found. */
final class PhaseTest extends TestCase
{
    private const NOW = Attempts::NOW;

    public function testAnOpenInvoiceIsToPayUntilItsEnd(): void
    {
        $attempt = Attempts::make(status: ['state' => 'open', 'expiresAt' => self::NOW + 900, 'received' => '0', 'rest' => null]);
        self::assertSame(Phase::OPEN, Phase::of($attempt, false, false, self::NOW));
        self::assertSame(Phase::OPEN, Phase::of(Attempts::make(), false, false, self::NOW));
        self::assertSame(Phase::ENDED, Phase::of($attempt, false, false, self::NOW + 900));
    }

    public function testAnUnderpaymentLeavesARestToPay(): void
    {
        $attempt = Attempts::make(status: ['state' => 'open', 'expiresAt' => self::NOW + 900, 'received' => '20000', 'rest' => ['sats' => '29000', 'bolt11' => 'lnbc290u1rest', 'paymentUri' => 'lightning:lnbc290u1rest']]);
        self::assertSame(Phase::UNDERPAID, Phase::of($attempt, false, false, self::NOW));
    }

    public function testTheOrdersStateComesFirst(): void
    {
        $attempt = Attempts::make();
        self::assertSame(Phase::PAID, Phase::of($attempt, true, false, self::NOW));
        self::assertSame(Phase::CANCELED, Phase::of($attempt, false, true, self::NOW));
        // Paid by this attempt: paid, whatever happened to the order since.
        self::assertSame(Phase::PAID, Phase::of(Attempts::make(['state' => Attempt::PAID, 'final' => 1]), false, true, self::NOW));
    }

    public function testAPaymentWhoseOrdersAreToCheckIsReceivedNeverPaid(): void
    {
        $paid = ['state' => 'paid', 'method' => 'lightning', 'preimage' => str_repeat('ab', 32)];
        $toCheck = Attempts::make(['state' => Attempt::PAID, 'final' => 1, 'notes' => 'to_check'], status: $paid);
        self::assertSame(Phase::REVIEW, Phase::of($toCheck, false, false, self::NOW));
        self::assertSame(Phase::REVIEW, Phase::of($toCheck, false, true, self::NOW), 'canceled, then paid');
        // Settled by the merchant: paid.
        self::assertSame(Phase::PAID, Phase::of($toCheck, true, false, self::NOW));
        self::assertSame(Phase::PAID, Phase::of(Attempts::make(['state' => Attempt::PAID, 'final' => 1, 'notes' => 'paid_twice'], status: $paid), false, false, self::NOW));
        self::assertNotContains(Phase::REVIEW, Phase::PAYABLE);
        self::assertNull(Phase::pollInterval(Phase::REVIEW));
    }

    public function testFinalStates(): void
    {
        self::assertSame(Phase::EXPIRED, Phase::of(Attempts::make(['state' => Attempt::EXPIRED, 'final' => 1], status: ['state' => 'expired', 'received' => '0']), false, false, self::NOW));
        self::assertSame(Phase::UNDERPAID_EXPIRED, Phase::of(Attempts::make(['state' => Attempt::EXPIRED, 'final' => 1], status: ['state' => 'expired', 'received' => '1500']), false, false, self::NOW));
        self::assertSame(Phase::FAILED, Phase::of(Attempts::make(['state' => Attempt::FAILED, 'final' => 1], null), false, false, self::NOW));
        self::assertSame(Phase::ABANDONED, Phase::of(Attempts::make(['state' => Attempt::ABANDONED, 'final' => 1]), false, false, self::NOW));
        // A final state is told even of a closed order.
        self::assertSame(Phase::EXPIRED, Phase::of(Attempts::make(['state' => Attempt::EXPIRED, 'final' => 1]), false, true, self::NOW));
    }

    public function testAnInvoiceFoundPaidIsNeverToPayAgainWhileItsPaymentIsBeingRecorded(): void
    {
        // A check found it paid; PrestaShop has not taken the payment yet (its recording failed,
        // the attempt is not final): received, nothing to pay, whatever its time and its order.
        $txids = [str_repeat('1', 64)];
        $found = [
            'lightning' => Attempts::make(status: ['state' => 'paid', 'method' => 'lightning', 'preimage' => str_repeat('ab', 32)]),
            'on-chain' => Attempts::make(status: ['state' => 'paid', 'method' => 'onchain', 'received' => '49000', 'txids' => $txids]),
            'being confirmed before' => Attempts::make(['state' => Attempt::UNCONFIRMED], status: ['state' => 'paid', 'method' => 'onchain', 'received' => '49000', 'txids' => $txids]),
            'handed out by the SDK paid' => Attempts::make(['origin' => Attempt::ORIGIN_PAID], status: ['state' => 'paid', 'method' => 'lightning', 'preimage' => str_repeat('ab', 32)]),
        ];
        foreach ($found as $case => $attempt) {
            self::assertSame(Phase::RECORDING, Phase::of($attempt, false, false, self::NOW), $case);
            self::assertSame(Phase::RECORDING, Phase::of($attempt, false, false, self::NOW + 3600), $case . ', past its end');
            self::assertSame(Phase::RECORDING, Phase::of($attempt, false, true, self::NOW), $case . ', its order canceled meanwhile');
            self::assertSame(Phase::PAID, Phase::of($attempt, true, false, self::NOW), $case . ', its order paid');
        }
        self::assertNotContains(Phase::RECORDING, Phase::PAYABLE);
        self::assertSame(10, Phase::pollInterval(Phase::RECORDING));

        // Only a status none or open is shown to pay: being confirmed or expired never is.
        $unconfirmed = Attempts::make(status: ['state' => 'unconfirmed', 'received' => '49000', 'confirmations' => 1, 'txids' => $txids]);
        self::assertSame(Phase::UNCONFIRMED, Phase::of($unconfirmed, false, false, self::NOW));
        $expired = Attempts::make(status: ['state' => 'expired', 'received' => '0']);
        self::assertSame(Phase::ENDED, Phase::of($expired, false, false, self::NOW));
        foreach ([null, ['state' => 'open', 'expiresAt' => self::NOW + 900, 'received' => '0', 'rest' => null]] as $status) {
            self::assertSame(Phase::OPEN, Phase::of(Attempts::make(status: $status), false, false, self::NOW));
        }
    }

    public function testBeingConfirmed(): void
    {
        $attempt = Attempts::make(['state' => Attempt::UNCONFIRMED], status: ['state' => 'unconfirmed', 'received' => '49000', 'confirmations' => 1, 'txids' => [str_repeat('1', 64)]]);
        self::assertSame(Phase::UNCONFIRMED, Phase::of($attempt, false, false, self::NOW + 3600));
        // Even of an order canceled meanwhile: the payment came.
        self::assertSame(Phase::UNCONFIRMED, Phase::of($attempt, false, true, self::NOW + 3600));
    }

    public function testNoInvoiceYet(): void
    {
        self::assertSame(Phase::CREATING, Phase::of(Attempts::make([], null), false, false, self::NOW));
    }

    public function testAnInvoiceHandedOutAgainIsShownOnlyOnceACheckFoundItOpen(): void
    {
        $pending = Attempts::make(['origin' => Attempt::ORIGIN_PENDING]);
        self::assertSame(Phase::CHECKING, Phase::of($pending, false, false, self::NOW));
        $checked = Attempts::make(['origin' => Attempt::ORIGIN_PENDING], status: ['state' => 'open', 'expiresAt' => self::NOW + 900, 'received' => '0', 'rest' => null]);
        self::assertSame(Phase::OPEN, Phase::of($checked, false, false, self::NOW));
    }

    public function testThePagesAskForNewsOnlyWhileSomethingMayChange(): void
    {
        self::assertSame(3, Phase::pollInterval(Phase::OPEN));
        self::assertSame(3, Phase::pollInterval(Phase::UNDERPAID));
        self::assertSame(3, Phase::pollInterval(Phase::CHECKING));
        self::assertSame(5, Phase::pollInterval(Phase::ENDED));
        self::assertSame(10, Phase::pollInterval(Phase::RECORDING));
        self::assertSame(30, Phase::pollInterval(Phase::UNCONFIRMED));
        foreach ([Phase::PAID, Phase::REVIEW, Phase::EXPIRED, Phase::UNDERPAID_EXPIRED, Phase::CANCELED, Phase::CREATING, Phase::FAILED, Phase::ABANDONED] as $phase) {
            self::assertNull(Phase::pollInterval($phase), $phase);
        }
    }
}
