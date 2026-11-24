<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\Cancellation;
use Sattleme\PrestaShop\Tests\Support\Attempts;

/**
 * When an order may not be canceled: while a payment of it may still come, and once bitcoin came,
 * whatever attempt of its cart says so.
 */
final class CancellationTest extends TestCase
{
    private const NOW = Attempts::NOW;

    public function testWhileAPaymentMayStillCome(): void
    {
        // Open, never checked yet; open, its time over (its final status unknown yet); being confirmed.
        self::assertSame(Cancellation::MAY_COME, Cancellation::refusal([Attempts::make()]));
        self::assertSame(Cancellation::MAY_COME, Cancellation::refusal([Attempts::make(status: ['state' => 'open', 'expiresAt' => self::NOW + 900, 'received' => '0', 'rest' => null])]));
        self::assertSame(Cancellation::MAY_COME, Cancellation::refusal([Attempts::make(['state' => Attempt::UNCONFIRMED], status: ['state' => 'unconfirmed', 'received' => '49000', 'confirmations' => 1, 'txids' => [str_repeat('cd', 32)]])]));
    }

    public function testOnceBitcoinCame(): void
    {
        $paid = ['state' => 'paid', 'method' => 'lightning', 'preimage' => str_repeat('ab', 32)];
        self::assertSame(Cancellation::CAME, Cancellation::refusal([Attempts::make(['state' => Attempt::PAID, 'final' => 1], status: $paid)]));
        // Found paid by a check, not recorded in PrestaShop yet.
        self::assertSame(Cancellation::CAME, Cancellation::refusal([Attempts::make(status: $paid)]));
        // Underpaid, still open; ended underpaid; given up on after something came.
        self::assertSame(Cancellation::CAME, Cancellation::refusal([Attempts::make(status: ['state' => 'open', 'expiresAt' => self::NOW + 900, 'received' => '20000', 'rest' => null])]));
        self::assertSame(Cancellation::CAME, Cancellation::refusal([Attempts::make(['state' => Attempt::EXPIRED, 'final' => 1], status: ['state' => 'expired', 'received' => '12000'])]));
        self::assertSame(Cancellation::CAME, Cancellation::refusal([Attempts::make(['state' => Attempt::ABANDONED, 'final' => 1, 'error' => 'abandoned'], status: ['state' => 'unconfirmed', 'received' => '49000', 'confirmations' => 2, 'txids' => [str_repeat('cd', 32)]])]));
        // An earlier attempt that came counts, whatever the later one is.
        self::assertSame(Cancellation::CAME, Cancellation::refusal([
            Attempts::make(['state' => Attempt::FAILED, 'final' => 1], null),
            Attempts::make(['state' => Attempt::EXPIRED, 'final' => 1], status: ['state' => 'expired', 'received' => '1500']),
        ]));
    }

    public function testOtherwiseTheMerchantMayCancel(): void
    {
        self::assertNull(Cancellation::refusal([]));
        // Ended unpaid, nothing received; no invoice could be made; none made yet; given up on, nothing received.
        self::assertNull(Cancellation::refusal([Attempts::make(['state' => Attempt::EXPIRED, 'final' => 1], status: ['state' => 'expired', 'received' => '0'])]));
        self::assertNull(Cancellation::refusal([Attempts::make(['state' => Attempt::FAILED, 'final' => 1], null)]));
        self::assertNull(Cancellation::refusal([Attempts::make([], null)]));
        self::assertNull(Cancellation::refusal([Attempts::make(['state' => Attempt::ABANDONED, 'final' => 1, 'error' => 'abandoned'])]));
    }
}
