<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Support;

use PHPUnit\Framework\Assert;
use Sattleme\PrestaShop\Tests\Fake\FakeGateway;
use Sattleme\PrestaShop\Tests\Fake\FakeState;

/**
 * A shop the HTTP tests reach as its merchant and its customers do, over HTTP only, without
 * JavaScript: tests/shop/shop.sh's (PHP's built-in server), or PrestaShop's Docker image
 * (tests/e2e/run.sh). Its environment: SATTLEME_SHOP_URL, SATTLEME_SHOP_ADMIN (the back office's
 * directory), SATTLEME_SHOP_ADMIN_EMAIL and SATTLEME_SHOP_ADMIN_PASSWORD, SATTLEME_SHOP_FAKE (the
 * directory of the fake SDK's state, which the shop's module reads through its test-only switch).
 */
final class HttpShop
{
    /** PrestaShop's demo product without combinations: "Mug The best is yet to come". */
    public const PRODUCT = 6;

    /** A virtual product of PrestaShop's demo data ("Mountain fox - Vector graphics"). */
    public const VIRTUAL_PRODUCT = 12;

    /** The French address of the customers: PrestaShop's demo carriers deliver to France. */
    public const ADDRESS = ['address1' => '1 rue de Rivoli', 'postcode' => '75001', 'city' => 'Paris', 'phone' => '+33 1 00 00 00 00'];

    private function __construct()
    {
    }

    public static function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            Assert::fail("{$name} is not set: the HTTP tests run against a shop (tests/shop/shop.sh env, or tests/e2e/run.sh)");
        }

        return $value;
    }

    public static function url(): string
    {
        return rtrim(self::env('SATTLEME_SHOP_URL'), '/');
    }

    public static function fake(): FakeState
    {
        return new FakeState(self::env('SATTLEME_SHOP_FAKE'));
    }

    public static function browser(): Browser
    {
        return new Browser(self::url());
    }

    /** A browser logged in to the back office, as the shop's merchant, and its token. */
    public static function merchant(): Merchant
    {
        $browser = self::browser();
        $admin = '/' . trim(self::env('SATTLEME_SHOP_ADMIN'), '/') . '/';
        $login = $browser->get($admin . 'index.php');
        // The form of a password field (passwd, or login[passwd] as PrestaShop's versions name it).
        $password = '/(^|\[)passw(or)?d\]?$/';
        $form = null;
        foreach ($login->forms() as $candidate) {
            foreach ($candidate->names() as $name) {
                if (preg_match($password, $name) === 1) {
                    $form ??= $candidate;
                }
            }
        }
        Assert::assertNotNull($form, 'the back office\'s login form: ' . substr($login->text(), 0, 300));
        $values = [];
        foreach ($form->names() as $name) {
            if (preg_match('/(^|\[)email\]?$/', $name) === 1) {
                $values[$name] = self::env('SATTLEME_SHOP_ADMIN_EMAIL');
            } elseif (preg_match($password, $name) === 1) {
                $values[$name] = self::env('SATTLEME_SHOP_ADMIN_PASSWORD');
            }
        }
        $dashboard = $browser->follow($form->with($values)->submit($browser));
        $token = null;
        foreach ($dashboard->urls() as $url) {
            if (preg_match('/[?&]_token=([A-Za-z0-9_.\-]+)/', $url, $match) === 1) {
                $token = $match[1];
                break;
            }
        }
        Assert::assertNotNull($token, 'logged in to the back office: ' . substr($dashboard->text(), 0, 300));

        return new Merchant($browser, $admin, $token);
    }

    /**
     * The module's settings saved by the merchant through its page: enabled, with the fake's key.
     * The page after saving.
     */
    public static function configure(Merchant $merchant, string $minutes = '15'): Page
    {
        $page = $merchant->configurePage();
        $saved = $merchant->browser->follow(self::settingsForm($page)->with([
            'SATTLEME_ENABLED' => '1',
            'SATTLEME_KEY' => FakeGateway::KEY,
            'SATTLEME_MINUTES' => $minutes,
            'submitSattlemeSettings' => '1',
        ])->submit($merchant->browser));
        Assert::assertStringContainsString('Settings saved.', $saved->text());

        return $saved;
    }

    /** The module's settings form on its page. */
    public static function settingsForm(Page $page): Form
    {
        foreach ($page->forms() as $form) {
            if ($form->has('SATTLEME_FORM_TOKEN')) {
                return $form;
            }
        }
        Assert::fail('No settings form: ' . substr($page->text(), 0, 500));
    }

    /**
     * A guest's checkout up to its payment step: the demo mug in the cart (or $product), the
     * customer's information, a French address, the first carrier (none for a virtual product,
     * which PrestaShop's checkout does not deliver). The payment step's page.
     */
    public static function paymentStep(Browser $customer, string $name = 'Ada', int $product = self::PRODUCT): Page
    {
        self::addToCart($customer, $product);
        $order = $customer->get('/index.php?controller=order');
        $information = $order->form(static fn (Form $form): bool => $form->id === 'customer-form', 'of the customer');
        $values = [
            'id_gender' => '1',
            'firstname' => $name,
            'lastname' => 'Lovelace',
            'email' => strtolower($name) . '.' . bin2hex(random_bytes(6)) . '@example.com',
            'continue' => '1',
        ];
        // The consents PrestaShop's modules ask for (psgdpr, ps_dataprivacy), where they are.
        foreach (['psgdpr', 'customer_privacy'] as $consent) {
            if ($information->has($consent)) {
                $values[$consent] = '1';
            }
        }
        $page = $customer->follow($information->with($values)->submit($customer));
        $address = null;
        foreach ($page->forms() as $form) {
            if ($form->has('saveAddress')) {
                $address = $form;
            }
        }
        Assert::assertNotNull($address, 'the address step: ' . substr($page->text(), 0, 300));
        $france = self::choice($address, 'id_country', 'France', $page);
        $page = $customer->follow($address->with(self::ADDRESS + ['id_country' => $france, 'confirm-addresses' => '1'])->submit($customer));
        if ($product === self::VIRTUAL_PRODUCT) {
            return $page;
        }
        $delivery = null;
        foreach ($page->forms() as $form) {
            foreach ($form->names() as $field) {
                if (str_starts_with($field, 'delivery_option')) {
                    $delivery = $form;
                }
            }
        }
        Assert::assertNotNull($delivery, 'the delivery step: ' . substr($page->text(), 0, 300));

        return $customer->follow($delivery->with(['confirmDeliveryOption' => '1'])->submit($customer));
    }

    /** The demo mug (or $product) in the customer's cart, from its product page. */
    public static function addToCart(Browser $customer, int $id = self::PRODUCT): void
    {
        $product = $customer->get('/index.php?id_product=' . $id . '&controller=product');
        $cart = $product->form(static fn (Form $form): bool => $form->id === 'add-to-cart-or-refresh', 'to add to the cart');
        $customer->follow($cart->with(['add' => '1', 'qty' => '1'])->submit($customer));
    }

    /** "Place order" with the module: its form on the payment step, as the page's script posts it. */
    public static function placeOrder(Browser $customer, Page $paymentStep): Page
    {
        return self::validationForm($paymentStep)->submit($customer);
    }

    /** The module's form of the payment step: "Place order" with it. */
    public static function validationForm(Page $paymentStep): Form
    {
        return $paymentStep->form(static fn (Form $form): bool => self::isPage($form->action, 'validation', 'sattleme'), 'to place the order with the module');
    }

    /**
     * Whether a URL leads to a page of the shop: a controller of PrestaShop's ("order",
     * "order-confirmation"), or of a module ($module), as the shop writes its URLs: with its
     * friendly URLs on (/order, /module/sattleme/pay, PrestaShop's Docker image) or off
     * (index.php?controller=order, index.php?fc=module&module=sattleme&controller=pay,
     * tests/shop/shop.sh's shop).
     */
    public static function isPage(string $url, string $controller, ?string $module = null): bool
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (isset($query['controller'])) {
            return $query['controller'] === $controller && ($query['module'] ?? null) === $module;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_ends_with($path, $module === null ? '/' . $controller : '/module/' . $module . '/' . $controller);
    }

    /** The value of a select's option by its label (a country by its name). */
    public static function choice(Form $form, string $name, string $label, Page $page): string
    {
        if (preg_match('#<option value="(\d+)"[^>]*>\s*' . preg_quote($label, '#') . '\s*</option>#', $page->body, $match) === 1) {
            Assert::assertContains($match[1], $form->choices[$name] ?? []);

            return $match[1];
        }
        Assert::fail("No {$label} in {$name}");
    }
}
