<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use Sattleme\PrestaShop\Admin\ConfigPage;
use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Tests\Support\HttpShop;
use Sattleme\PrestaShop\Tests\Support\ShopTestCase;

/**
 * Two shops of one installation (PrestaShop's multistore): the settings page lists each shop's
 * cron URL, whatever shop it is opened in, and each URL, on its shop's own address, checks that
 * shop's invoices in its context. The second shop is made as the back office makes one (its
 * data imported from the first: Shop::copyShopData), on another address of this machine
 * (localhost, where the first is 127.0.0.1), then deleted.
 */
final class MultistoreTest extends ShopTestCase
{
    private \Shop $second;

    private string $multistore;

    /** @var array<string, list<int>> the rows of each table moved to the second shop */
    private array $moved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $multistore = \Configuration::getGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE');
        $this->multistore = is_string($multistore) ? $multistore : '0';
        \Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', '1');
        $first = new \Shop((int) \Configuration::get('PS_SHOP_DEFAULT'));
        $shop = new \Shop();
        $shop->name = 'sattle.me second shop';
        $shop->id_shop_group = (int) $first->id_shop_group;
        $shop->id_category = (int) $first->id_category;
        $shop->theme_name = $first->theme_name;
        $shop->active = true;
        self::assertTrue($shop->add());
        // A shop's data, as the back office's "import data" lists them (copyShopData copies the
        // currencies alone when given none), its products aside: none is ordered there, and
        // copyShopData reads their default category from the back office's form.
        $tables = array_fill_keys(array_keys(\Shop::getAssoTables()), true);
        unset($tables['product']);
        $shop->copyShopData((int) $first->id, $tables);
        $port = parse_url(HttpShop::url(), PHP_URL_PORT);
        $url = new \ShopUrl();
        $url->id_shop = (int) $shop->id;
        $url->domain = 'localhost' . (is_int($port) ? ':' . $port : '');
        $url->domain_ssl = $url->domain;
        $url->physical_uri = $first->physical_uri;
        $url->virtual_uri = '';
        $url->main = true;
        $url->active = true;
        self::assertTrue($url->add());
        \Shop::cacheShops(true);
        $this->second = new \Shop((int) $shop->id);
    }

    protected function tearDown(): void
    {
        try {
            $first = (int) \Configuration::get('PS_SHOP_DEFAULT');
            foreach ($this->moved as $table => $ids) {
                $key = $table === AttemptRepository::TABLE ? 'id_sattleme_payment' : 'id_' . ($table === 'orders' ? 'order' : $table);
                Database::execute('UPDATE `' . _DB_PREFIX_ . $table . '` SET `id_shop` = ' . $first . ' WHERE `' . $key . '` IN (' . implode(', ', $ids) . ')');
            }
            Database::execute('DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `id_shop` = ' . (int) $this->second->id);
            self::assertTrue($this->second->delete(), 'the second shop deleted');
        } finally {
            \Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', $this->multistore);
            \Shop::cacheShops(true);
        }
        parent::tearDown();
    }

    public function testTheSettingsListEachShopsCronUrl(): void
    {
        $page = new ConfigPage($this->module, 'sattleme', Platform::translator());
        $crons = $page->crons();
        $first = (int) \Configuration::get('PS_SHOP_DEFAULT');
        self::assertSame([$first, (int) $this->second->id], array_column($crons, 'id'));
        self::assertSame('sattle.me second shop', $crons[1]['shop']);
        self::assertSame(parse_url(HttpShop::url(), PHP_URL_HOST), parse_url($crons[0]['url'], PHP_URL_HOST));
        self::assertSame('localhost', parse_url($crons[1]['url'], PHP_URL_HOST));
        foreach ($crons as $cron) {
            self::assertStringContainsString('token=' . Config::cronToken($cron['id']), $cron['url']);
            self::assertSame("* * * * * curl -fsS --max-time 120 '" . $cron['url'] . "' > /dev/null", $cron['line']);
        }
        self::assertTrue($page->status()['sattleme_multistore']);
    }

    public function testEachShopsUrlChecksItsOwnShopsInvoicesInItsContext(): void
    {
        // An order of the second shop, its invoice paid and due for a check.
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $second = (int) $this->second->id;
        $this->moved = [
            'orders' => [(int) $order->id],
            'cart' => [(int) $order->id_cart],
            'customer' => [(int) $order->id_customer],
            AttemptRepository::TABLE => [$attempt->id],
        ];
        foreach ($this->moved as $table => $ids) {
            $key = $table === AttemptRepository::TABLE ? 'id_sattleme_payment' : 'id_' . ($table === 'orders' ? 'order' : $table);
            self::assertTrue(Database::execute('UPDATE `' . _DB_PREFIX_ . $table . '` SET `id_shop` = ' . $second . ' WHERE `' . $key . '` IN (' . implode(', ', $ids) . ')'));
        }
        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => 'lightning']);
        Database::execute('UPDATE `' . AttemptRepository::table() . '` SET `next_check_at` = 2000000000 WHERE `final` = 0');
        Database::execute('UPDATE `' . AttemptRepository::table() . '` SET `next_check_at` = ' . (Clock::now() - 60) . ' WHERE `id_sattleme_payment` = ' . $attempt->id);
        [$firstCron, $secondCron] = (new ConfigPage($this->module, 'sattleme', Platform::translator()))->crons();
        $cron = HttpShop::browser();

        // The first shop's URL checks the first shop's invoices only.
        $run = $cron->fetch($firstCron['url']);
        self::assertSame(200, $run->status, $run->body);
        self::assertSame('Checked 0 of 0 invoices due (0 skipped), 0 given up', trim($run->body));
        self::assertSame([], array_filter($this->fake->calls('status'), static fn (array $call): bool => $call['ref'] === $attempt->ref));

        // Without its token: refused.
        self::assertSame(403, $cron->fetch((string) preg_replace('/token=[0-9a-f]{64}/', 'token=' . str_repeat('0', 64), $secondCron['url']))->status);

        // The second shop's URL checks its invoice, and the order is paid in its shop.
        $run = $cron->fetch($secondCron['url']);
        self::assertSame(200, $run->status, $run->body);
        self::assertSame('Checked 1 of 1 invoices due (0 skipped), 0 given up', trim($run->body));
        self::assertSame(self::os('PS_OS_PAYMENT'), Database::int(Database::value('SELECT `current_state` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . (int) $order->id)));
        self::assertSame(1, Database::int(Database::value('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_payment` WHERE `transaction_id` = ' . Database::quote($attempt->ref))));
        self::assertGreaterThan(0, Database::int(Database::value('SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = ' . Database::quote(Config::CRON_LAST_RUN) . ' AND `id_shop` = ' . $second)), 'the second shop\'s last run');
    }
}
