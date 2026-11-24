<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Payment\Creator;
use Sattleme\PrestaShop\Privacy\Shredder;
use Sattleme\PrestaShop\Security\Tokens;
use Sattleme\PrestaShop\Tests\Support\Attempts;

/**
 * What an erasure removes from a payment's record; what the merchant's app and the customer's
 * wallet read of the sale; how the module's secrets are compared.
 */
final class PrivacyAndNotesTest extends TestCase
{
    public function testAnErasureRemovesWhatLeadsToTheCustomersCoins(): void
    {
        $attempt = Attempts::make(status: ['state' => 'paid', 'method' => 'onchain', 'received' => '49000', 'txids' => [str_repeat('1', 64)]]);
        $invoice = Shredder::invoice($attempt->invoice);
        self::assertNotNull($invoice);
        self::assertSame(Shredder::REMOVED, $invoice['onchainAddress']);
        self::assertSame(Shredder::REMOVED, $invoice['paymentUri']);
        // What is not personal data on its own stays: the Lightning invoice, the amounts, the rate.
        self::assertSame(Attempts::BOLT11, $invoice['bolt11']);
        self::assertSame('49000', $invoice['sats']);
        self::assertSame($attempt->invoice['rate'] ?? null, $invoice['rate']);

        $status = Shredder::status($attempt->status);
        self::assertNotNull($status);
        self::assertSame([], $status['txids']);
        self::assertSame('49000', $status['received']);

        $rest = Shredder::status(['state' => 'open', 'expiresAt' => 1, 'received' => '100', 'rest' => ['sats' => '1', 'bolt11' => 'x', 'paymentUri' => 'bitcoin:addr']]);
        self::assertNotNull($rest);
        self::assertNull($rest['rest']);

        $lightningOnly = Attempts::make(invoice: ['onchainAddress' => null, 'paymentUri' => 'lightning:' . Attempts::BOLT11]);
        self::assertSame($lightningOnly->invoice, Shredder::invoice($lightningOnly->invoice));
        self::assertNull(Shredder::status(null));
    }

    public function testTheSalesNote(): void
    {
        self::assertSame('Shop · ABCDEFGHI', Creator::note('Shop', 'ABCDEFGHI'));
        self::assertSame('ABCDEFGHI', Creator::note('', 'ABCDEFGHI'));
        self::assertSame('A B · REF', Creator::note("A\n\tB\x00", 'REF'));
        $long = Creator::note(str_repeat('é', 200), 'ABCDEFGHI');
        self::assertSame(100, mb_strlen($long));
        self::assertStringEndsWith('… · ABCDEFGHI', $long);
        self::assertSame(100, mb_strlen(Creator::note('Shop', str_repeat('R', 150))));
    }

    public function testSecretsAreComparedAsStringsOnly(): void
    {
        self::assertTrue(Tokens::equals('abc', 'abc'));
        self::assertFalse(Tokens::equals('abc', 'abd'));
        self::assertFalse(Tokens::equals('abc', ['abc']));
        self::assertFalse(Tokens::equals('abc', null));
        self::assertFalse(Tokens::equals('', ''));
        self::assertFalse(Tokens::equals('123', 123));
    }
}
