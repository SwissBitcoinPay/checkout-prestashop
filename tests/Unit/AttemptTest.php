<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\Schedule;
use Sattleme\PrestaShop\Tests\Support\Attempts;

/** A payment attempt as its row reads it, and what its stored invoice and status say. */
final class AttemptTest extends TestCase
{
    public function testThePriceOfTheInvoiceAndWhatItsPaymentBrought(): void
    {
        $attempt = Attempts::make(status: ['state' => 'paid', 'method' => 'lightning', 'preimage' => str_repeat('ab', 32)]);
        self::assertSame(['amount' => '24.50', 'currency' => 'CHF'], $attempt->price());
        self::assertSame('49000', $attempt->paidSats(), 'a Lightning invoice is paid for its amount');
        self::assertSame('48500', Attempts::make(status: ['state' => 'paid', 'method' => 'onchain', 'received' => '48500', 'txids' => []])->paidSats(), 'what came, the tolerance accepting it');
        self::assertSame('51000', Attempts::make(status: ['state' => 'paid', 'method' => 'mixed', 'received' => '51000', 'txids' => [], 'preimages' => []])->paidSats());
        foreach ([null, ['amount' => 24.5, 'currency' => 'CHF'], ['amount' => '24.50', 'currency' => 'chf'], ['amount' => '-1', 'currency' => 'CHF'], 'CHF 24.50'] as $fiat) {
            self::assertNull(Attempts::make(invoice: ['fiat' => $fiat])->price(), (string) json_encode($fiat));
        }
    }

    public function testARowIsReadWhateverTypesTheDatabaseGives(): void
    {
        $ints = Attempts::make();
        $strings = Attempts::make(['id_sattleme_payment' => '7', 'id_cart' => '42', 'id_order' => '5', 'final' => '0', 'errors' => '0', 'created_at' => (string) Attempts::NOW]);
        foreach ([$ints, $strings] as $attempt) {
            self::assertSame(7, $attempt->id);
            self::assertSame(42, $attempt->idCart);
            self::assertSame(5, $attempt->idOrder);
            self::assertFalse($attempt->final);
            self::assertSame(Attempts::NOW, $attempt->createdAt);
            self::assertSame(Attempts::NOW + 900, $attempt->expiresAt);
            self::assertSame(Attempts::NOW + 900 + Schedule::GRACE_SECONDS, $attempt->end());
        }
        self::assertNull(Attempts::make(['id_order' => null])->idOrder);
        self::assertNull(Attempts::make(['id_order' => '0'])->idOrder);
        self::assertTrue(Attempts::make(['final' => '1'])->final);
        self::assertSame(['paid_twice', 'underpaid'], Attempts::make(['notes' => 'paid_twice,underpaid'])->notes);
        self::assertTrue(Attempts::make(['notes' => 'paid_twice,underpaid'])->hasNote('underpaid'));
        self::assertSame([], Attempts::make()->notes);
    }

    public function testTheStoredInvoice(): void
    {
        $attempt = Attempts::make();
        self::assertSame('49000', $attempt->sats());
        self::assertSame(Attempts::BOLT11, $attempt->bolt11());
        self::assertSame(Attempts::ADDRESS, $attempt->onchainAddress());
        self::assertSame(Attempts::URI, $attempt->paymentUri());
        self::assertSame(str_repeat('ab', 32), $attempt->paymentHash());
        self::assertSame(Attempts::NOW, $attempt->invoiceCreatedAt());
        self::assertSame(['source' => 'kraken', 'currency' => 'CHF', 'price' => '50000', 'fetchedAt' => Attempts::NOW], $attempt->rate());

        $lightningOnly = Attempts::make(invoice: ['onchainAddress' => null, 'paymentUri' => 'lightning:' . Attempts::BOLT11]);
        self::assertNull($lightningOnly->onchainAddress());
        self::assertNull(Attempts::make([], null)->sats());
        self::assertNull(Attempts::make(['invoice' => '{not json'])->invoice);
        self::assertNull(Attempts::make(invoice: ['sats' => '1.5'])->sats());
    }

    public function testWhatTheLastCheckFound(): void
    {
        $txid = str_repeat('0f', 32);
        $attempt = Attempts::make(['state' => Attempt::UNCONFIRMED], status: [
            'state' => 'unconfirmed', 'received' => '049000', 'confirmations' => 2, 'txids' => [$txid, 'not a txid', 12],
        ]);
        self::assertSame('unconfirmed', $attempt->statusState());
        self::assertSame('49000', $attempt->received());
        self::assertTrue($attempt->hasReceived());
        self::assertSame(2, $attempt->confirmations());
        self::assertSame([$txid], $attempt->txids());

        $paid = Attempts::make(status: ['state' => 'paid', 'method' => 'mixed', 'received' => '20000', 'txids' => [$txid], 'preimages' => [str_repeat('cd', 32), 'x']]);
        self::assertSame('mixed', $paid->method());
        self::assertSame([str_repeat('cd', 32)], $paid->preimages());
        $lightning = Attempts::make(status: ['state' => 'paid', 'method' => 'lightning', 'preimage' => str_repeat('ef', 32)]);
        self::assertSame([str_repeat('ef', 32)], $lightning->preimages());
        self::assertSame('0', $lightning->received());
        self::assertFalse($lightning->hasReceived());

        $rest = Attempts::make(status: ['state' => 'open', 'expiresAt' => Attempts::NOW + 900, 'received' => '20000', 'rest' => ['sats' => '29000', 'bolt11' => 'lnbc1rest', 'paymentUri' => 'bitcoin:x?lightning=lnbc1rest']]);
        self::assertSame(['sats' => '29000', 'bolt11' => 'lnbc1rest', 'paymentUri' => 'bitcoin:x?lightning=lnbc1rest'], $rest->rest());
        self::assertNull(Attempts::make()->rest());
        self::assertNull(Attempts::make()->statusState());
        self::assertSame('0', Attempts::make(status: ['state' => 'open', 'received' => 'abc'])->received());
    }
}
