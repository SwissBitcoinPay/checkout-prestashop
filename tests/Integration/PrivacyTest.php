<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Privacy\Shredder;
use Sattleme\PrestaShop\Tests\Support\ShopTestCase;

/**
 * PrestaShop's GDPR module (psgdpr): a customer's bitcoin payments in the export of their personal
 * data; their erasure removes what leads to their coins (the address, the transactions).
 */
final class PrivacyTest extends ShopTestCase
{
    public function testTheExportHoldsTheCustomersPayments(): void
    {
        $customer = $this->customer('Grace');
        $order = $this->checkout($this->cart($customer));
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'onchain', 'received' => '23800']);
        $this->module->checker()->check($attempt->id, 0, 5);
        // As psgdpr calls it: the customer's fields.
        $json = $this->module->hookActionExportGDPRData(self::fields($customer));
        $rows = json_decode($json, true);
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertIsArray($rows[0]);
        self::assertSame([
            'reference' => $attempt->ref,
            'order_reference' => (string) $order->reference,
            'date' => date('Y-m-d H:i:s', $attempt->createdAt),
            'amount' => $attempt->amount . ' CHF',
            'sats' => $attempt->sats(),
            'state' => Attempt::PAID,
            'bitcoin_address' => $attempt->onchainAddress(),
            'transactions' => $this->attempt((int) $order->id_cart)->txids()[0],
            'received_sats' => '23800',
        ], $rows[0]);
        // Another customer's: none of them.
        self::assertSame('[]', $this->module->hookActionExportGDPRData(self::fields($this->customer('Ada'))));
    }

    public function testAnErasureRemovesTheAddressAndTheTransactions(): void
    {
        $customer = $this->customer('Grace');
        $order = $this->checkout($this->cart($customer));
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'onchain', 'received' => '23800']);
        $this->module->checker()->check($attempt->id, 0, 5);

        $this->module->hookActionDeleteGDPRCustomer(self::fields($customer));
        $erased = $this->attempt((int) $order->id_cart);
        self::assertTrue($erased->shredded);
        self::assertSame(Shredder::REMOVED, $erased->onchainAddress());
        self::assertSame(Shredder::REMOVED, $erased->paymentUri());
        self::assertSame([], $erased->txids());
        // The record of the payment stays: its reference, amount, state, proof.
        self::assertSame($attempt->ref, $erased->ref);
        self::assertSame(Attempt::PAID, $erased->state);
        self::assertSame($attempt->sats(), $erased->sats());
        self::assertCount(1, self::payments($order));
    }

    public function testAPaymentBeingConfirmedLosesThemOnceFinal(): void
    {
        $customer = $this->customer('Grace');
        $order = $this->checkout($this->cart($customer));
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'unconfirmed', 'received' => '23800', 'confirmations' => 1]);
        $this->module->checker()->check($attempt->id, 0, 5);

        // Asked by email address only, as psgdpr may.
        $this->module->hookActionDeleteGDPRCustomer(['email' => $customer->email]);
        $marked = $this->attempt((int) $order->id_cart);
        self::assertTrue($marked->shredded);
        // Its checks still need the address: kept until its final status.
        self::assertSame($attempt->onchainAddress(), $marked->onchainAddress());

        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'onchain', 'received' => '23800']);
        $this->module->checker()->check($attempt->id, 0, 5);
        $final = $this->attempt((int) $order->id_cart);
        self::assertSame(Attempt::PAID, $final->state);
        self::assertSame(Shredder::REMOVED, $final->onchainAddress());
        self::assertSame([], $final->txids());
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
    }

    public function testAnErasureByPhoneNumber(): void
    {
        $customer = $this->customer('Grace');
        $order = $this->checkout($this->cart($customer));
        $this->module->hookActionDeleteGDPRCustomer(['phone' => '+41 44 000 00 00']);
        self::assertTrue($this->attempt((int) $order->id_cart)->shredded);
        // Nothing of the kind for an unknown number.
        $other = $this->checkout();
        $this->module->hookActionDeleteGDPRCustomer(['phone' => '+41 00 000 00 01']);
        self::assertFalse($this->attempt((int) $other->id_cart)->shredded);
    }

    public function testPrestaShopLoadsTheModulesTranslations(): void
    {
        $context = \Sattleme\PrestaShop\Platform::context();
        foreach (['fr-FR' => 'Payer en bitcoin', 'de-DE' => 'Mit Bitcoin bezahlen', 'it-IT' => 'Paga con bitcoin'] as $locale => $text) {
            // The translator of a request in that language.
            $translator = $context->getTranslatorFromLocale($locale);
            self::assertSame($text, $translator->trans('Pay with bitcoin', [], 'Modules.Sattleme.Shop'));
            self::assertSame(
                str_replace('%reference%', 'XKBKNABJK', \Sattleme\PrestaShop\Tests\Support\Translator::forLocale($locale)->trans('Order %reference%', [], 'Modules.Sattleme.Shop')),
                $translator->trans('Order %reference%', ['%reference%' => 'XKBKNABJK'], 'Modules.Sattleme.Shop'),
            );
            // Another language's wording, as the merchant's notes are written in the shop's language.
            self::assertSame($text, \Sattleme\PrestaShop\Wordings::trans($context->getTranslator(), 'Pay with bitcoin', 'Modules.Sattleme.Shop', $locale));
        }
        self::assertSame('Réglages', $context->getTranslatorFromLocale('fr-FR')->trans('Settings', [], 'Modules.Sattleme.Admin'));
        // A language the module has no translation of: English, its parameters replaced.
        self::assertSame('Order XKBKNABJK', $context->getTranslatorFromLocale('es-ES')->trans('Order %reference%', ['%reference%' => 'XKBKNABJK'], 'Modules.Sattleme.Shop'));
    }

    /**
     * A customer as psgdpr hands it to the modules: its fields.
     *
     * @return array<string, mixed>
     */
    private static function fields(\Customer $customer): array
    {
        $fields = [];
        foreach (get_object_vars($customer) as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }
}
