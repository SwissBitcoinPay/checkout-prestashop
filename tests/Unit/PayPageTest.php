<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\Phase;
use Sattleme\PrestaShop\Tests\Support\Attempts;
use Sattleme\PrestaShop\Tests\Support\Translator;
use Sattleme\PrestaShop\View\PayPage;

/** The customer's pages: their sentences in the customer's language, and what they offer. */
final class PayPageTest extends TestCase
{
    private static function page(Translator $translator): PayPage
    {
        return new PayPage($translator, static fn (string $sats): string => $sats, static fn (string $amount, string $currency): string => $currency . ' ' . $amount);
    }

    public function testTheSentencesHoldTheirValues(): void
    {
        $status = ['state' => 'open', 'expiresAt' => Attempts::NOW + 900, 'received' => '20000', 'rest' => ['sats' => '29000', 'bolt11' => 'lnbc1', 'paymentUri' => 'lightning:lnbc1']];
        $view = self::page(Translator::english())->view('XKBKNABJK', Attempts::make(status: $status), Phase::UNDERPAID, false, Attempts::NOW, true);
        $texts = $view['texts'];
        self::assertIsArray($texts);
        self::assertSame('Order XKBKNABJK', $texts['order']);
        self::assertSame('29000 sats', $texts['to_pay_sats']);
        self::assertSame('20000 of 49000 sats came. Pay the rest to complete your payment: 29000 sats.', $texts['underpaid']);
        self::assertSame('Payment reference: ' . Attempts::REF, $texts['reference']);
        self::assertSame('CHF 24.50', $view['amount']);
        self::assertTrue($view['payable']);
        self::assertTrue($view['checkable']);
        self::assertFalse($view['may_renew']);
    }

    public function testInFrench(): void
    {
        $view = self::page(Translator::forLocale('fr-FR'))->view('XKBKNABJK', Attempts::make(['state' => Attempt::UNCONFIRMED], status: ['state' => 'unconfirmed', 'received' => '49000', 'confirmations' => 2, 'txids' => []]), Phase::UNCONFIRMED, false, Attempts::NOW, true);
        $texts = $view['texts'];
        self::assertIsArray($texts);
        self::assertSame('Commande XKBKNABJK', $texts['order']);
        self::assertSame('2 confirmation(s) sur 3', $texts['confirmations']);
        self::assertSame('Copié', $texts['copied']);
    }

    public function testWhatEachPhaseOffers(): void
    {
        $page = self::page(Translator::english());
        $cases = [
            // phase, may renew (the orders allow it), [payable, checkable, may_renew, contact, to_page]
            [Phase::OPEN, false, [true, true, false, false, true]],
            [Phase::CHECKING, false, [false, true, false, false, true]],
            [Phase::ENDED, false, [false, true, false, false, true]],
            [Phase::UNCONFIRMED, false, [false, true, false, false, true]],
            [Phase::RECORDING, false, [false, true, false, false, true]],
            [Phase::REVIEW, false, [false, false, false, true, false]],
            [Phase::EXPIRED, true, [false, false, true, true, true]],
            [Phase::EXPIRED, false, [false, false, false, true, false]],
            [Phase::UNDERPAID_EXPIRED, false, [false, false, false, true, false]],
            [Phase::FAILED, true, [false, false, true, true, true]],
            [Phase::ABANDONED, false, [false, false, false, true, false]],
            [Phase::CANCELED, false, [false, false, false, false, false]],
            [Phase::CREATING, true, [false, false, true, false, true]],
            // A page whose orders were paid otherwise offers no new invoice in any phase.
            [Phase::OPEN, true, [true, true, false, false, true]],
        ];
        foreach ($cases as [$phase, $mayRenew, $expected]) {
            $view = $page->view('REF', Attempts::make(), $phase, $mayRenew, Attempts::NOW, false);
            self::assertSame($expected, [$view['payable'], $view['checkable'], $view['may_renew'], $view['contact'], $view['to_page']], $phase);
        }
        $none = $page->view('REF', null, null, true, Attempts::NOW, false);
        self::assertSame(PayPage::NONE, $none['phase']);
        self::assertNull($none['payment']);
        self::assertTrue($none['may_renew']);
        self::assertTrue($none['to_page']);
    }
}
