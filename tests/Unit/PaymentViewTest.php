<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Payment\Phase;
use Sattleme\PrestaShop\Tests\Support\Attempts;
use Sattleme\PrestaShop\Tests\Support\QrReader;
use Sattleme\PrestaShop\View\PaymentView;

/** What the customer's pages show of an invoice: values only, the templates escape them. */
final class PaymentViewTest extends TestCase
{
    private const NOW = Attempts::NOW;

    /**
     * @param array<string, mixed>|null $status
     * @param array<string, mixed> $invoice
     *
     * @return array<string, mixed>
     */
    private static function view(string $phase, ?array $status = null, array $invoice = [], bool $withQr = true): array
    {
        return PaymentView::of(Attempts::make(invoice: $invoice, status: $status), $phase, self::NOW + 125, static fn (string $sats): string => number_format((float) $sats, 0, '.', '’'), 'QR', $withQr);
    }

    public function testAnOpenInvoice(): void
    {
        $view = self::view(Phase::OPEN);
        self::assertSame(Attempts::URI, $view['uri']);
        self::assertSame(Attempts::BOLT11, $view['lightning']);
        self::assertSame(Attempts::ADDRESS, $view['address']);
        self::assertSame('49’000', $view['sats']);
        self::assertSame('0.00049', $view['btc']);
        self::assertSame('49’000', $view['to_pay_sats']);
        self::assertSame(775, $view['seconds_left']);
        self::assertSame('12:55', $view['time_left']);
        self::assertSame(3, $view['poll_interval']);
        // The QR code, an <img>'s base64 data URI: the invoice's payment URI, as a scanner reads it.
        self::assertIsString($view['qr']);
        self::assertSame(Attempts::URI, QrReader::image($view['qr']));
    }

    public function testTheRestOfAnUnderpayment(): void
    {
        $restUri = 'bitcoin:' . Attempts::ADDRESS . '?amount=0.00029&lightning=lnbc290u1rest';
        $view = self::view(Phase::UNDERPAID, ['state' => 'open', 'expiresAt' => self::NOW + 900, 'received' => '20000', 'rest' => ['sats' => '29000', 'bolt11' => 'lnbc290u1rest', 'paymentUri' => $restUri]]);
        self::assertSame($restUri, $view['uri']);
        self::assertSame('lnbc290u1rest', $view['lightning']);
        self::assertSame('29’000', $view['to_pay_sats']);
        self::assertSame('0.00029', $view['to_pay_btc']);
        self::assertSame('20’000', $view['received_sats']);
        self::assertTrue($view['has_received']);
        // A rest below 1 000 sats is payable in Lightning only: no address then.
        $small = self::view(Phase::UNDERPAID, ['state' => 'open', 'expiresAt' => self::NOW + 900, 'received' => '48500', 'rest' => ['sats' => '500', 'bolt11' => 'lnbc5u1rest', 'paymentUri' => 'lightning:lnbc5u1rest']]);
        self::assertNull($small['address']);
        self::assertSame('lightning:lnbc5u1rest', $small['uri']);
    }

    public function testNothingToPayOnceItIsNoLongerPayable(): void
    {
        foreach ([Phase::ENDED, Phase::EXPIRED, Phase::UNCONFIRMED, Phase::CHECKING, Phase::CANCELED, Phase::PAID, Phase::RECORDING, Phase::REVIEW] as $phase) {
            $view = self::view($phase);
            self::assertNull($view['uri'], $phase);
            self::assertNull($view['qr'], $phase);
            self::assertNull($view['lightning'], $phase);
            self::assertNull($view['address'], $phase);
        }
    }

    public function testOnlyPaymentUrisOfTheSdksSchemes(): void
    {
        self::assertSame('lightning:lnbc1x', PaymentView::uri('lightning:lnbc1x'));
        self::assertNull(PaymentView::uri('javascript:alert(1)'));
        self::assertNull(PaymentView::uri('bitcoin:bc1q"><script>'));
        self::assertNull(PaymentView::uri('https://evil.example/'));
        self::assertNull(PaymentView::uri(null));
        $view = self::view(Phase::OPEN, invoice: ['paymentUri' => 'javascript:alert(1)']);
        self::assertNull($view['uri']);
        self::assertNull($view['qr']);
        self::assertNull($view['address']);
    }

    public function testNoQrCodeWhereThePageDoesNotShowIt(): void
    {
        self::assertNull(self::view(Phase::OPEN, withQr: false)['qr']);
    }

    public function testTheVersionChangesWithWhatThePageShows(): void
    {
        $open = Attempts::make();
        $underpaid = Attempts::make(status: ['state' => 'open', 'expiresAt' => self::NOW + 900, 'received' => '20000', 'rest' => ['sats' => '29000', 'bolt11' => 'lnbc1', 'paymentUri' => 'lightning:lnbc1']]);
        $versions = [
            PaymentView::version($open, Phase::OPEN),
            PaymentView::version($open, Phase::ENDED),
            PaymentView::version($underpaid, Phase::UNDERPAID),
        ];
        self::assertCount(3, array_unique($versions));
        self::assertSame(PaymentView::version($open, Phase::OPEN), PaymentView::version(Attempts::make(), Phase::OPEN));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $versions[0]);
    }
}
