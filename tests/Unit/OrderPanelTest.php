<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Admin\OrderPanel;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Privacy\Shredder;
use Sattleme\PrestaShop\Tests\Support\Attempts;
use Sattleme\PrestaShop\Tests\Support\Translator;

/** What the merchant sees of an order's payments in the back office. */
final class OrderPanelTest extends TestCase
{
    public function testAPaidOnChainPayment(): void
    {
        $txid = str_repeat('a1', 32);
        $attempt = Attempts::make(['state' => Attempt::PAID, 'final' => 1, 'checked_at' => Attempts::NOW + 60], status: [
            'state' => 'paid', 'method' => 'onchain', 'received' => '49000', 'txids' => [$txid, 'A1' . str_repeat('0', 62)],
        ]);
        [$row] = OrderPanel::rows([$attempt], Translator::english());
        self::assertSame(Attempts::REF, $row['ref']);
        self::assertSame('24.50', $row['amount']);
        self::assertSame('CHF', $row['currency']);
        self::assertSame('49000', $row['sats']);
        self::assertSame('0.00049', $row['btc']);
        self::assertSame(['source' => 'kraken', 'currency' => 'CHF', 'price' => '50000', 'fetchedAt' => Attempts::NOW], $row['rate']);
        self::assertSame('onchain', $row['method']);
        self::assertSame(Attempts::ADDRESS, $row['address']);
        self::assertArrayNotHasKey('address_link', $row, 'shown as text, never linked to another site');
        // Only transactions as they are written: 64 lower-case hexadecimal characters.
        self::assertSame([$txid], $row['txids']);
        self::assertSame(date('Y-m-d H:i:s', Attempts::NOW), $row['created_at']);
        self::assertSame(date('Y-m-d H:i:s', Attempts::NOW + 60), $row['checked_at']);
        self::assertNull($row['next_check_at']);
        self::assertSame('', $row['error_text']);
    }

    public function testProofsErrorsAndWhatWasErased(): void
    {
        $preimage = str_repeat('5e', 32);
        [$row] = OrderPanel::rows([Attempts::make(['error' => 'network', 'errors' => 3], status: ['state' => 'paid', 'method' => 'lightning', 'preimage' => $preimage])], Translator::english());
        // Each preimage with the payment hash it proves: SHA-256 of its bytes.
        self::assertSame([['preimage' => $preimage, 'hash' => hash('sha256', (string) hex2bin($preimage))]], $row['proofs']);
        self::assertSame('network', $row['error']);
        self::assertFalse($row['check_failed'], 'never checked yet');
        self::assertIsString($row['error_text']);
        self::assertStringContainsString('did not answer', $row['error_text']);
        self::assertSame(date('Y-m-d H:i:s', Attempts::NOW), $row['next_check_at']);

        // A first check that failed: said as such.
        [$failed] = OrderPanel::rows([Attempts::make(['error' => 'network', 'errors' => 1, 'checked_at' => Attempts::NOW + 5])], Translator::english());
        self::assertTrue($failed['check_failed']);
        self::assertSame(date('Y-m-d H:i:s', Attempts::NOW + 5), $failed['checked_at']);

        [$erased] = OrderPanel::rows([Attempts::make(['shredded' => 1], invoice: ['onchainAddress' => Shredder::REMOVED, 'paymentUri' => Shredder::REMOVED])], Translator::english());
        self::assertTrue($erased['address_removed']);
    }

    public function testTheStatesTheMerchantSettlesByHand(): void
    {
        [$underpaid, $abandoned, $confirming] = OrderPanel::rows([
            Attempts::make(['state' => Attempt::EXPIRED, 'final' => 1], status: ['state' => 'expired', 'received' => '1000']),
            Attempts::make(['state' => Attempt::ABANDONED, 'final' => 1, 'error' => 'abandoned']),
            Attempts::make(['state' => Attempt::UNCONFIRMED], status: ['state' => 'unconfirmed', 'received' => '49000', 'confirmations' => 1, 'txids' => []]),
        ], Translator::english());
        self::assertTrue($underpaid['underpaid']);
        // Its status lists no transaction: they are the deposit address's.
        self::assertSame([], $underpaid['txids']);
        self::assertTrue($underpaid['txids_at_address']);
        self::assertTrue($abandoned['abandoned']);
        self::assertSame('', $abandoned['error_text']);
        self::assertTrue($confirming['confirming']);
        self::assertSame(1, $confirming['confirmations']);
        self::assertFalse($confirming['recording']);
    }

    public function testAPaymentFoundPaidThatPrestaShopHasNotRecordedYet(): void
    {
        [$recording, $paid] = OrderPanel::rows([
            Attempts::make(['error' => 'apply', 'errors' => 2], status: ['state' => 'paid', 'method' => 'lightning', 'preimage' => str_repeat('5e', 32)]),
            Attempts::make(['state' => Attempt::PAID, 'final' => 1], status: ['state' => 'paid', 'method' => 'lightning', 'preimage' => str_repeat('5e', 32)]),
        ], Translator::english());
        self::assertTrue($recording['recording']);
        self::assertIsString($recording['error_text']);
        self::assertStringContainsString('It is tried again at the next check', $recording['error_text']);
        self::assertFalse($paid['recording']);
        self::assertFalse($paid['to_check']);
        [$toCheck] = OrderPanel::rows([Attempts::make(['state' => Attempt::PAID, 'final' => 1, 'notes' => 'to_check'], status: ['state' => 'paid', 'method' => 'lightning', 'preimage' => str_repeat('5e', 32)])], Translator::english());
        self::assertTrue($toCheck['to_check']);
    }
}
