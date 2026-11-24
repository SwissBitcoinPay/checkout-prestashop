<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\Install\Installer;
use Sattleme\PrestaShop\KeyVault;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\OrderStates;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Sdk\Environment;
use Sattleme\PrestaShop\Tests\Fake\FakeGateway;
use Sattleme\PrestaShop\Tests\Support\ShopTestCase;

/**
 * The module as PrestaShop installed it from its ZIP, and its uninstallation: its settings go, the
 * records of its payments and its order states stay for the orders that have them.
 */
final class InstallTest extends ShopTestCase
{
    public function testItIsAPaymentModuleOfThisPrestaShop(): void
    {
        self::assertTrue(\Module::isInstalled('sattleme'));
        self::assertTrue(\Module::isEnabled('sattleme'));
        self::assertSame(\Sattleme::VERSION, \Db::getInstance()->getValue('SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = \'sattleme\''));
        self::assertSame('payments_gateways', $this->module->tab);
        self::assertTrue($this->module->isUsingNewTranslationSystem());
        self::assertSame(['min' => '9.0.0', 'max' => '9.99.99'], $this->module->ps_versions_compliancy);
        self::assertTrue(version_compare(_PS_VERSION_, '9.0.0', '>=') && version_compare(_PS_VERSION_, '9.99.99', '<='));
        foreach (Installer::HOOKS as $hook) {
            self::assertTrue($this->module->isRegisteredInHook($hook), $hook);
        }
        // PrestaShop's restrictions of payment methods: every currency, country and carrier at first.
        self::assertNotEmpty(\Currency::checkPaymentCurrencies((int) $this->module->id));
    }

    public function testItsOrderStatesInTheShopsLanguages(): void
    {
        $expected = [
            Config::OS_AWAITING => ['en' => 'Awaiting bitcoin payment', 'fr' => 'En attente du paiement bitcoin', 'de' => 'Warten auf Bitcoin-Zahlung', 'it' => 'In attesa del pagamento bitcoin'],
            Config::OS_CONFIRMING => ['en' => 'Bitcoin payment being confirmed', 'fr' => 'Paiement bitcoin en cours de confirmation', 'de' => 'Bitcoin-Zahlung wird bestätigt', 'it' => 'Pagamento bitcoin in conferma'],
            Config::OS_TO_CHECK => ['en' => 'Bitcoin payment to check', 'fr' => 'Paiement bitcoin à vérifier', 'de' => 'Bitcoin-Zahlung zu prüfen', 'it' => 'Pagamento bitcoin da verificare'],
        ];
        foreach ($expected as $key => $names) {
            $state = new \OrderState((int) \Configuration::getGlobalValue($key));
            self::assertTrue(\Validate::isLoadedObject($state), $key);
            self::assertSame('sattleme', $state->module_name);
            self::assertFalse((bool) $state->paid, 'never paid');
            self::assertFalse((bool) $state->logable, 'not a valid order yet');
            self::assertFalse((bool) $state->send_email, 'PrestaShop e-mails the order and its payment');
            self::assertFalse((bool) $state->invoice);
            self::assertFalse((bool) $state->shipped);
            self::assertFalse((bool) $state->delivery);
            self::assertFalse((bool) $state->deleted);
            foreach ($names as $iso => $name) {
                self::assertSame($name, $state->name[(int) \Language::getIdByIso($iso)] ?? null, "{$key} in {$iso}");
            }
        }
    }

    public function testItsSettingsAtFirst(): void
    {
        // As a first installation finds them: no title, no description.
        \Configuration::deleteByName(Config::TITLE);
        \Configuration::deleteByName(Config::DESCRIPTION);
        self::assertTrue(Installer::install(Platform::translator(), 'sattleme'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) \Configuration::getGlobalValue(Config::SECRET));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Config::cronToken());
        self::assertSame('Pay with bitcoin', Config::title((int) \Language::getIdByIso('en')));
        self::assertSame('Payer en bitcoin', Config::title((int) \Language::getIdByIso('fr')));
        self::assertSame('Mit Bitcoin bezahlen', Config::title((int) \Language::getIdByIso('de')));
        self::assertSame('Paga con bitcoin', Config::title((int) \Language::getIdByIso('it')));
        self::assertStringStartsWith('Paga con il tuo wallet Bitcoin', Config::description((int) \Language::getIdByIso('it')));
    }

    public function testUninstalledItsSettingsGoItsRecordsAndStatesStayForTheOrders(): void
    {
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $states = [Config::awaitingState(), Config::confirmingState()];
        $hooks = Installer::HOOKS;

        self::assertTrue($this->module->uninstall());
        try {
            self::assertFalse(\Module::isInstalled('sattleme'));
            foreach (Config::REMOVED_AT_UNINSTALL as $key) {
                self::assertFalse(\Configuration::hasKey($key), $key);
            }
            foreach ($states as $id) {
                $state = new \OrderState($id);
                self::assertTrue(\Validate::isLoadedObject($state));
                self::assertTrue((bool) $state->deleted, 'hidden from the lists, kept for the orders\' history');
            }
            self::assertSame($states[0], (int) self::reload($order)->current_state);
            self::assertNotNull((new AttemptRepository())->find($attempt->id));
        } finally {
            $module = \Module::getInstanceByName('sattleme');
            self::assertInstanceOf(\Sattleme::class, $module);
            self::assertTrue($module->install());
            KeyVault::store(FakeGateway::KEY);
            \Configuration::updateValue(Config::ENABLED, '1');
        }
        // A new installation takes its states and its records back, and goes on checking.
        self::assertSame($states, [Config::awaitingState(), Config::confirmingState()]);
        foreach ($states as $id) {
            self::assertFalse((bool) (new \OrderState($id))->deleted);
        }
        foreach ($hooks as $hook) {
            self::assertTrue((bool) \Hook::isModuleRegisteredOnHook(\Module::getInstanceByName('sattleme'), $hook, (int) Platform::context()->shop?->id), $hook);
        }
        self::assertSame(1, Database::int(Database::value('SELECT COUNT(*) FROM `' . AttemptRepository::table() . '` WHERE `id_sattleme_payment` = ' . $attempt->id)));
    }

    public function testTheStateToCheckIsDeletedWithTheModuleOnlyIfNoOrderIsOrWasInIt(): void
    {
        // A state "to check" no order was ever in (as a new installation makes it): deleted.
        \Configuration::deleteByName(Config::OS_TO_CHECK);
        self::assertTrue(OrderStates::install(Platform::translator(), 'sattleme'));
        $unused = Config::toCheckState();
        self::assertGreaterThan(0, $unused);
        self::assertFalse(OrderStates::isUsed($unused));
        $this->uninstallThenInstall(function () use ($unused): void {
            self::assertFalse(\Validate::isLoadedObject(new \OrderState($unused)), 'deleted for good');
            self::assertSame(0, Database::int(Database::value('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_state_lang` WHERE `id_order_state` = ' . $unused)));
            self::assertFalse(\Configuration::hasKey(Config::OS_TO_CHECK));
        });
        $new = Config::toCheckState();
        self::assertGreaterThan(0, $new);
        self::assertNotSame($unused, $new);

        // An order in it: it stays as it is, the orders' state, and a new installation takes it back.
        $order = $this->checkout();
        $history = new \OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($new, self::reload($order), true);
        self::assertTrue($history->add());
        self::assertTrue(OrderStates::isUsed($new));
        $this->uninstallThenInstall(function () use ($new, $order): void {
            $state = new \OrderState($new);
            self::assertTrue(\Validate::isLoadedObject($state));
            self::assertFalse((bool) $state->deleted, 'an order waits in it for the merchant');
            self::assertSame($new, (int) self::reload($order)->current_state);
        });
        self::assertSame($new, Config::toCheckState());
    }

    /** Uninstalls the module, runs $check, then installs the module again (set up as the tests have it). */
    private function uninstallThenInstall(\Closure $check): void
    {
        self::assertTrue($this->module->uninstall());
        try {
            $check();
        } finally {
            $module = \Module::getInstanceByName('sattleme');
            self::assertInstanceOf(\Sattleme::class, $module);
            self::assertTrue($module->install());
            KeyVault::store(FakeGateway::KEY);
            \Configuration::updateValue(Config::ENABLED, '1');
            $this->module = $module;
        }
    }

    public function testAServerWithoutWhatTheSdkNeedsIsRefused(): void
    {
        $missing = new \ReflectionProperty(Environment::class, 'missing');
        $missing->setValue(null, 'PHP 8.2 or later (this server runs PHP 8.1.0)');
        try {
            $module = new \Sattleme();
            self::assertFalse($module->install());
            self::assertContains('This server cannot run the module: PHP 8.2 or later (this server runs PHP 8.1.0)', $module->getErrors());
            self::assertNull($this->module->offeredAmount($this->cart()));
        } finally {
            $missing->setValue(null, null);
        }
        self::assertNull(Environment::missing());
    }
}
