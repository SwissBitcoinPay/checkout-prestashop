<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Opc;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\Form;
use Sattleme\PrestaShop\Tests\Support\HttpShop;
use Sattleme\PrestaShop\Tests\Support\Merchant;
use Sattleme\PrestaShop\Tests\Support\OnePageCheckout;

/**
 * PrestaShop 9.2's One Page Checkout, over HTTP, as its script drives it (OnePageCheckout): the
 * merchant turns it on in its module's settings, a guest checks out on its one page and places the
 * order with the method, which leads to the payment page. Its first page shows the method's form
 * before the guest's account exists; its script shows the form again once the guest is known, and
 * posts it after its own submission: the form's token is the cart's, the same in both.
 *
 * PrestaShop 9.2 and later only (its module ps_onepagecheckout): the suite "opc", which the CI and
 * tests/e2e/run.sh run in a shop of PrestaShop 9.2.
 */
final class OnePageCheckoutTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        HttpShop::fake()->reset();
        HttpShop::configure(HttpShop::merchant());
    }

    public function testAGuestPlacesAnOrderWithTheMethodOnTheOnePageCheckout(): void
    {
        $merchant = HttpShop::merchant();
        self::layout($merchant, true);
        try {
            $fake = HttpShop::fake();
            $customer = HttpShop::browser();
            HttpShop::addToCart($customer);
            $checkout = new OnePageCheckout($customer, $customer->get('/index.php?controller=order'));

            // Its first page, before the guest's account exists: the method's form is there.
            $first = $checkout->page->byName('input', 'sattleme_token');
            self::assertCount(1, $first);
            $token = $first[0]->getAttribute('value');
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

            $email = 'grace.' . bin2hex(random_bytes(6)) . '@example.com';
            $checkout->guest($email);
            $address = HttpShop::ADDRESS + [
                'firstname' => 'Grace',
                'lastname' => 'Hopper',
                'id_country' => HttpShop::choice($checkout->form(), 'id_country', 'France', $checkout->page),
            ];
            $idAddress = $checkout->address($address);
            $carrier = $checkout->carrier($idAddress);
            $payment = $checkout->paymentForm('sattleme', $idAddress);
            // Shown again once the guest is known: the same token, the cart's.
            self::assertSame($token, $payment->value('sattleme_token'));

            // "Place order": the checkout saved first, then the method's form posted.
            $submitted = $checkout->submit($address + [
                'email' => $email,
                'use_same_address' => '1',
                'delivery_option' => $carrier,
                'conditions_to_approve[terms-and-conditions]' => '1',
                'paymentMethod' => 'sattleme',
                'psgdpr' => '1',
                'customer_privacy' => '1',
            ]);
            self::assertTrue($submitted['success'] ?? null, (string) json_encode($submitted));
            $before = count($fake->calls('create'));
            $placed = $payment->submit($customer);
            self::assertSame(302, $placed->status, substr($placed->text(), 0, 500));
            self::assertTrue(HttpShop::isPage((string) $placed->location(), 'pay', 'sattleme'), (string) $placed->location());
            self::assertCount($before + 1, $fake->calls('create'));
            $page = $customer->follow($placed);
            self::assertNotNull($page->element('sattleme-pay'), substr($page->text(), 0, 500));
            self::assertStringContainsString('Scan the QR code with your Bitcoin wallet', $page->text());
        } finally {
            self::layout($merchant, false);
        }
    }

    /** The checkout's layout, in its module's settings: one page, or PrestaShop's four steps. */
    private static function layout(Merchant $merchant, bool $onePage): void
    {
        $settings = $merchant->browser->get($merchant->url('improve/modules/manage/action/configure/ps_onepagecheckout'));
        $form = $settings->form(static fn (Form $form): bool => $form->has('submitPsOnePageCheckoutConfiguration'), 'of the checkout\'s layout');
        $saved = $merchant->browser->follow($form->with(['PS_ONE_PAGE_CHECKOUT_ENABLED' => $onePage ? '1' : '0', 'psopc_enable_maintenance' => '0'])->submit($merchant->browser));
        self::assertSame(200, $saved->status);
        $layout = $saved->form(static fn (Form $form): bool => $form->has('submitPsOnePageCheckoutConfiguration'), 'of the checkout\'s layout');
        self::assertSame($onePage ? '1' : '0', $layout->value('PS_ONE_PAGE_CHECKOUT_ENABLED'));
    }
}
