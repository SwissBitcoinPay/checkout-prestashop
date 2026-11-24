<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Support;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\KeyVault;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\Orders;
use Sattleme\PrestaShop\Tests\Fake\FakeGateway;
use Sattleme\PrestaShop\Tests\Fake\FakeState;

/**
 * A test in the shop (tests/shop/shop.sh): PrestaShop booted in the test's process, the module
 * installed from the ZIP and set up with a key, the SDK replaced by the fake. Each test makes its
 * own customer, cart and order: tests never share one.
 */
abstract class ShopTestCase extends TestCase
{
    /** A product of PrestaShop's demo data without combinations: "Mug The best is yet to come". */
    public const PRODUCT = 6;

    /** PrestaShop's demo carrier with a price ("My carrier"). */
    public const CARRIER = 2;

    protected FakeState $fake;

    protected \Sattleme $module;

    /** @var array<string, mixed> the settings by language a test may change, restored after it */
    private array $texts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $module = \Module::getInstanceByName('sattleme');
        if (!$module instanceof \Sattleme) {
            self::fail('The module is not installed');
        }
        $this->module = $module;
        foreach ([Config::TITLE, Config::DESCRIPTION] as $key) {
            $this->texts[$key] = \Configuration::getConfigInMultipleLangs($key);
        }
        $this->fake = FakeState::forTests();
        $this->fake->reset();
        Clock::freeze(null);
        \Configuration::updateValue(Config::ENABLED, '1');
        \Configuration::updateValue(Config::MINUTES, '15');
        KeyVault::store(FakeGateway::KEY);
        // The context of a customer's request in the shop's default currency, country, language.
        $context = \Sattleme\PrestaShop\Platform::context();
        $context->employee = null;
        $context->currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $context->country = new \Country((int) \Configuration::get('PS_COUNTRY_DEFAULT'));
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        foreach ($this->texts as $key => $values) {
            \Configuration::updateValue($key, $values);
        }
        parent::tearDown();
    }

    protected function customer(string $name = 'Ada'): \Customer
    {
        $customer = new \Customer();
        $customer->firstname = $name;
        $customer->lastname = 'Lovelace';
        $customer->email = strtolower($name) . '.' . bin2hex(random_bytes(6)) . '@example.com';
        $customer->passwd = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $customer->is_guest = true;
        $customer->id_default_group = (int) \Configuration::get('PS_GUEST_GROUP');
        $customer->id_shop = (int) \Sattleme\PrestaShop\Platform::context()->shop?->id;
        self::assertTrue($customer->add());
        $customer->updateGroup([(int) \Configuration::get('PS_GUEST_GROUP')]);

        return $customer;
    }

    /**
     * A cart of the customer, ready to order: an address in Switzerland (or in France, where
     * PrestaShop's demo carriers deliver), products, a carrier.
     *
     * @param array<int, int> $products product => quantity
     */
    protected function cart(?\Customer $customer = null, array $products = [self::PRODUCT => 1], string $currency = 'CHF', string $country = 'CH'): \Cart
    {
        $customer ??= $this->customer();
        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = (int) \Country::getByIso($country);
        $address->alias = 'Home';
        $address->firstname = $customer->firstname;
        $address->lastname = $customer->lastname;
        if ($country === 'FR') {
            $address->address1 = '1 rue de Rivoli';
            $address->postcode = '75001';
            $address->city = 'Paris';
            $address->phone = '+33 1 00 00 00 00';
        } else {
            $address->address1 = 'Bahnhofstrasse 1';
            $address->postcode = '8001';
            $address->city = 'Zürich';
            $address->phone = '+41 44 000 00 00';
        }
        self::assertTrue($address->add());

        $cart = new \Cart();
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = (int) $address->id;
        $cart->id_address_invoice = (int) $address->id;
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_currency = (int) \Currency::getIdByIsoCode($currency);
        $cart->id_shop = (int) \Sattleme\PrestaShop\Platform::context()->shop?->id;
        $cart->id_shop_group = (int) \Sattleme\PrestaShop\Platform::context()->shop?->id_shop_group;
        $cart->secure_key = (string) $customer->secure_key;
        self::assertTrue($cart->add());
        // PrestaShop prices a cart in its context, as the shop's front office does.
        \Sattleme\PrestaShop\Platform::context()->cart = $cart;
        \Sattleme\PrestaShop\Platform::context()->customer = $customer;
        foreach ($products as $product => $quantity) {
            // The tests order many: never out of stock.
            \StockAvailable::setQuantity($product, 0, 100000);
            self::assertNotFalse($cart->updateQty($quantity, $product));
        }
        $cart->setDeliveryOption([(int) $address->id => self::CARRIER . ',']);
        self::assertTrue($cart->update());

        return new \Cart((int) $cart->id);
    }

    /** The cart ordered with the module, as the checkout's "Place order" does. */
    protected function checkout(?\Cart $cart = null): \Order
    {
        $cart ??= $this->cart();

        return new \Order($this->module->creator()->checkout($cart, new \Customer((int) $cart->id_customer)));
    }

    protected function attempt(int $idCart): Attempt
    {
        $attempt = (new AttemptRepository())->latestForCart($idCart);
        self::assertNotNull($attempt);

        return $attempt;
    }

    protected static function reload(\Order $order): \Order
    {
        return new \Order((int) $order->id);
    }

    protected static function state(\Order $order): int
    {
        return (int) self::reload($order)->current_state;
    }

    /**
     * The payments PrestaShop recorded for an order's reference.
     *
     * @return list<array<string, mixed>>
     */
    protected static function payments(\Order $order): array
    {
        return Database::rows('SELECT * FROM `' . _DB_PREFIX_ . 'order_payment` WHERE `order_reference` = ' . Database::quote((string) $order->reference) . ' ORDER BY `id_order_payment`');
    }

    protected static function note(\Order $order): string
    {
        return (string) self::reload($order)->note;
    }

    /** @return list<\Order> */
    protected static function orders(int $idCart): array
    {
        return Orders::forCart($idCart);
    }

    /** The shop's order states by their configuration names. */
    protected static function os(string $name): int
    {
        return (int) \Configuration::get($name);
    }
}
