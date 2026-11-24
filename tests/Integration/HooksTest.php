<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\KeyVault;
use Sattleme\PrestaShop\Security\Tokens;
use Sattleme\PrestaShop\Tests\Fake\FakeGateway;
use Sattleme\PrestaShop\Tests\Support\ShopTestCase;

/**
 * Where PrestaShop shows the module: the payment method at checkout, the order's confirmation and
 * details for the customer, the order's page for the merchant; PrestaShop's restrictions of
 * payment methods.
 */
final class HooksTest extends ShopTestCase
{
    /** @return list<PaymentOption> */
    private function options(\Cart $cart): array
    {
        return $this->module->hookPaymentOptions(['cart' => $cart]);
    }

    public function testTheMethodAtCheckout(): void
    {
        $cart = $this->cart();
        \Configuration::updateValue(Config::DESCRIPTION, [(int) \Language::getIdByIso('en') => "Fast & cheap.\n<script>alert(1)</script>"]);
        $options = $this->options($cart);
        self::assertCount(1, $options);
        $option = $options[0];
        self::assertSame('sattleme', $option->getModuleName());
        self::assertSame('Pay with bitcoin', $option->getCallToActionText());
        self::assertStringContainsString('fc=module&module=sattleme&controller=validation', (string) $option->getAction());
        self::assertStringEndsWith('/modules/sattleme/views/img/sattleme.svg', (string) $option->getLogo());
        self::assertSame([
            'sattleme_cart' => ['name' => 'sattleme_cart', 'type' => 'hidden', 'value' => (string) (int) $cart->id],
            'sattleme_token' => ['name' => 'sattleme_token', 'type' => 'hidden', 'value' => Tokens::checkout((int) $cart->id)],
        ], $option->getInputs());
        $information = (string) $option->getAdditionalInformation();
        self::assertStringContainsString('Fast &amp; cheap.<br>', $information);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $information);
        self::assertStringNotContainsString('<script>', $information);
    }

    public function testNotOfferedUnlessSetUpAndPriceable(): void
    {
        $cart = $this->cart();
        self::assertSame(number_format($cart->getOrderTotal(true, \Cart::BOTH), 2, '.', ''), $this->module->offeredAmount($cart));

        \Configuration::updateValue(Config::ENABLED, '0');
        self::assertSame([], $this->options($cart));
        \Configuration::updateValue(Config::ENABLED, '1');

        KeyVault::forget();
        self::assertSame([], $this->options($cart));
        // A key this shop cannot read (its encryption key changed): as none.
        \Configuration::updateValue(Config::KEY, 'v1:def5020000');
        self::assertSame([], $this->options($cart));
        KeyVault::store(FakeGateway::KEY);
        self::assertCount(1, $this->options($cart));

        // A currency of no price table.
        $gold = new \Currency();
        $gold->iso_code = 'XAU';
        $gold->numeric_iso_code = '959';
        $gold->precision = 2;
        $gold->conversion_rate = 0.0005;
        $gold->name = 'Gold';
        $gold->active = true;
        if ((int) \Currency::getIdByIsoCode('XAU') === 0) {
            // Truthy, not always true: PrestaShop 9.1's ObjectModel::add gives 1 for a currency.
            self::assertTrue((bool) $gold->add());
        }
        $xau = $this->cart(null, [self::PRODUCT => 1], 'XAU');
        self::assertNull($this->module->offeredAmount($xau));
        self::assertSame([], $this->options($xau));
    }

    public function testPrestaShopsRestrictionsOfPaymentMethodsApply(): void
    {
        $this->cart();
        self::assertTrue($this->module->isAllowedHere());
        $country = (int) \Country::getByIso('CH');
        $shop = (int) \Sattleme\PrestaShop\Platform::context()->shop?->id;
        \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'module_country` WHERE `id_module` = ' . (int) $this->module->id . ' AND `id_country` = ' . $country);
        try {
            \Cache::clean('*');
            self::assertFalse($this->module->isAllowedHere());
        } finally {
            \Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'module_country` (`id_module`, `id_shop`, `id_country`) VALUES (' . (int) $this->module->id . ', ' . $shop . ', ' . $country . ')');
            \Cache::clean('*');
        }
        self::assertTrue($this->module->isAllowedHere());
    }

    public function testTheConfirmationLeadsToThePaymentPage(): void
    {
        $order = $this->checkout();
        $html = $this->module->hookDisplayPaymentReturn(['order' => $order]);
        self::assertStringContainsString('Your order awaits its bitcoin payment.', $html);
        self::assertStringContainsString('Pay now', $html);
        self::assertStringContainsString(htmlspecialchars($this->module->payUrl($order)), $html);
        self::assertStringContainsString('id_order=' . (int) $order->id, $html);
        self::assertStringContainsString('key=' . $order->secure_key, $html);

        $attempt = $this->attempt((int) $order->id_cart);
        // Being confirmed: nothing final (its on-chain transaction may still be replaced or dropped).
        $this->fake->setStatus($attempt->ref, ['state' => 'unconfirmed', 'received' => '1000', 'confirmations' => 1]);
        $this->module->checker()->check($attempt->id, 0, 5);
        $confirming = $this->module->hookDisplayOrderDetail(['order' => self::reload($order)]);
        self::assertStringContainsString('Your bitcoin payment came in time. The Bitcoin network is confirming its on-chain transaction, which takes about 30 minutes', $confirming);
        self::assertStringNotContainsString('was received', $confirming);

        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'lightning']);
        $this->module->checker()->check($attempt->id, 0, 5);
        $paid = $this->module->hookDisplayOrderDetail(['order' => self::reload($order)]);
        self::assertStringContainsString('Your bitcoin payment was received. Thank you!', $paid);
        self::assertStringNotContainsString('Pay now', $paid);
        self::assertStringContainsString('Payment reference: ' . $attempt->ref, $paid);

        // Another module's order: nothing.
        $other = self::reload($order);
        $other->module = 'ps_wirepayment';
        self::assertSame('', $this->module->hookDisplayPaymentReturn(['order' => $other]));
        self::assertSame('', $this->module->hookDisplayOrderDetail(['order' => null]));
    }

    public function testTheConfirmationInTheCustomersLanguage(): void
    {
        $order = $this->checkout();
        $context = \Sattleme\PrestaShop\Platform::context();
        $english = $context->language;
        $context->language = new \Language((int) \Language::getIdByIso('de'));
        $context->getTranslator()->setLocale('de-DE');
        try {
            $html = $this->module->hookDisplayPaymentReturn(['order' => $order]);
        } finally {
            $context->language = $english;
            $context->getTranslator()->setLocale('en-US');
        }
        self::assertStringContainsString('Ihre Bestellung wartet auf ihre Bitcoin-Zahlung.', $html);
        self::assertStringContainsString('Jetzt bezahlen', $html);
    }

    public function testTheMerchantsViewOfTheOrderChecksAndShowsEachPayment(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'mixed', 'received' => '10000']);
        \Sattleme\PrestaShop\Platform::context()->employee = new \Employee(1);
        $html = $this->module->hookDisplayAdminOrderMainBottom(['id_order' => (int) $order->id]);
        // The view checked the payment first.
        self::assertCount(1, $this->fake->calls('status'));
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
        $paid = $this->attempt((int) $order->id_cart);
        self::assertStringContainsString($attempt->ref, $html);
        self::assertStringContainsString('On-chain and Lightning', $html);
        // What was received in time, and how: the paid status names its method.
        self::assertMatchesRegularExpression('#10000 sats,\s*on-chain and in Lightning \(the rest of an underpayment\)\s*</dd>#', $html);
        // Its transactions and address as text to copy, linked to no other site (PrestaShop Addons).
        self::assertStringContainsString('<code class="text-break user-select-all">' . $paid->txids()[0] . '</code>', $html);
        self::assertStringContainsString('<code class="text-break user-select-all">' . $paid->onchainAddress() . '</code>', $html);
        self::assertStringNotContainsString('<a href', $html);
        self::assertStringContainsString($paid->preimages()[0], $html);
        self::assertStringContainsString('1 BTC = ' . FakeGateway::PRICE . ' CHF', $html);
        self::assertStringContainsString('Refunds are never automatic', $html);
        self::assertStringNotContainsString(FakeGateway::KEY, $html);

        // An error in plain words.
        $order2 = $this->checkout();
        $attempt2 = $this->attempt((int) $order2->id_cart);
        $this->fake->setStatus($attempt2->ref, ['state' => 'error', 'error' => 'rate']);
        $html2 = $this->module->hookDisplayAdminOrderMainBottom(['id_order' => (int) $order2->id]);
        self::assertStringContainsString('No usable bitcoin price was found. Try again later.', $html2);
        // An invoice ended underpaid: what was received in time, not how (an expired status names
        // no method).
        $order3 = $this->checkout();
        $attempt3 = $this->attempt((int) $order3->id_cart);
        $this->fake->setStatus($attempt3->ref, ['state' => 'expired', 'received' => '12000']);
        $html3 = $this->module->hookDisplayAdminOrderMainBottom(['id_order' => (int) $order3->id]);
        self::assertMatchesRegularExpression('#Received in time</dt>\s*<dd class="col-sm-8">\s*12000 sats\s*</dd>#', $html3);
        self::assertStringNotContainsString('if any', $html3);
        // Not this module's order: nothing.
        self::assertSame('', $this->module->hookDisplayAdminOrderMainBottom(['id_order' => 0]));
    }
}
