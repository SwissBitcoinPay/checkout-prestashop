<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use Sattleme\PrestaShop\Admin\ConfigPage;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\KeyVault;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Tests\Fake\FakeGateway;
use Sattleme\PrestaShop\Tests\Support\ShopTestCase;

/**
 * The settings: the key checked with the SDK before it is saved, stored encrypted, never shown
 * again; the time to pay; the title and description; a form of the employee's only (CSRF).
 */
final class SettingsTest extends ShopTestCase
{
    private ConfigPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        Platform::context()->employee = new \Employee(1);
        $this->page = new ConfigPage($this->module, 'sattleme', Platform::translator());
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        // As tests/bootstrap.php has it: the console's request.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tearDown();
    }

    /**
     * A submission of the form as the back office's page posts it.
     *
     * @param array<string, string> $fields
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function submit(array $fields): array
    {
        $form = [];
        foreach (Platform::languages() as $language) {
            $form['SATTLEME_TITLE_' . $language['id']] = 'Bitcoin';
            $form['SATTLEME_DESCRIPTION_' . $language['id']] = '';
        }
        $_POST = $fields + $form + [
            ConfigPage::SUBMIT => '1',
            ConfigPage::TOKEN_FIELD => $this->token(),
            'SATTLEME_ENABLED' => '1',
            'SATTLEME_KEY' => '',
            'SATTLEME_MINUTES' => '15',
        ];

        return $this->page->process();
    }

    /** The form's token, as the page puts it in the form (its hidden field). */
    private function token(): string
    {
        $token = (new \ReflectionMethod(ConfigPage::class, 'formToken'))->invoke($this->page);
        self::assertIsString($token);

        return $token;
    }

    public function testAFormOfAnotherOriginIsRefused(): void
    {
        KeyVault::forget();
        [$errors] = $this->submit([ConfigPage::TOKEN_FIELD => 'forged', 'SATTLEME_KEY' => FakeGateway::KEY]);
        self::assertSame(['The form expired. Please try again.'], $errors);
        self::assertFalse(KeyVault::isSet());
        // The token is the employee's: another employee's is refused too.
        $token = $this->token();
        Platform::context()->employee = new \Employee(2);
        [$errors] = $this->submit([ConfigPage::TOKEN_FIELD => $token]);
        self::assertSame(['The form expired. Please try again.'], $errors);
    }

    public function testTheSettingsChangeByAPostOnly(): void
    {
        // The form's fields, token included, in a link the employee follows (a GET): nothing changes.
        $this->submit(['SATTLEME_MINUTES' => '15']);
        self::assertSame(15, Config::minutes());
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = $_POST;
        $_POST = [];
        $_GET['SATTLEME_MINUTES'] = '7';
        $_GET['SATTLEME_ENABLED'] = '0';
        self::assertSame([[], []], $this->page->process());
        self::assertSame(15, Config::minutes());
        self::assertTrue(Config::isEnabled());

        // The same fields posted: saved.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $_GET;
        $_GET = [];
        self::assertSame([[], ['Settings saved.']], $this->page->process());
        self::assertSame(7, Config::minutes());
        self::assertFalse(Config::isEnabled());
    }

    public function testAKeyIsCheckedThenStoredEncryptedAndNeverShownAgain(): void
    {
        KeyVault::forget();
        $this->fake->setMerchant(['businessName' => 'Bitcoin Bakery']);
        [$errors, $confirmations] = $this->submit(['SATTLEME_KEY' => '  ' . FakeGateway::KEY . "\n"]);
        self::assertSame([], $errors);
        self::assertSame(['The integration key of Bitcoin Bakery is saved. It is never shown again.', 'Settings saved.'], $confirmations);
        self::assertCount(1, $this->fake->calls('merchant'));

        $stored = \Configuration::get(Config::KEY);
        self::assertIsString($stored);
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString(FakeGateway::KEY, $stored);
        self::assertStringNotContainsString(explode('.', FakeGateway::KEY)[1], $stored);
        self::assertSame(FakeGateway::KEY, KeyVault::read());
        self::assertSame('Bitcoin Bakery', \Configuration::get(Config::BUSINESS));

        // What the page shows of it: its state and its business, never the key.
        $status = $this->page->status();
        self::assertTrue($status['sattleme_key_set']);
        self::assertTrue($status['sattleme_key_readable']);
        self::assertSame('Bitcoin Bakery', $status['sattleme_business']);
        self::assertStringNotContainsString(explode('.', FakeGateway::KEY)[1], (string) json_encode($status));
    }

    public function testAKeyTheSdkRefusesIsNotSaved(): void
    {
        KeyVault::store(FakeGateway::KEY);
        [$errors] = $this->submit(['SATTLEME_KEY' => 'sk_live_not_ours']);
        self::assertSame(['This is not the secret key of a sattle.me integration (sattleme_ck_…).'], $errors);
        self::assertSame(FakeGateway::KEY, KeyVault::read(), 'the saved key stays');
        self::assertSame([], $this->fake->calls('merchant'), 'refused before any request');
    }

    public function testTheKeyOfARemovedIntegrationIsRefused(): void
    {
        KeyVault::forget();
        $this->fake->setMerchant(['error' => 'revoked']);
        [$errors] = $this->submit(['SATTLEME_KEY' => FakeGateway::KEY]);
        self::assertSame(['This integration was removed in the sattle.me app. Create a new one and enter its key.'], $errors);
        self::assertFalse(KeyVault::isSet());

        $this->fake->setMerchant(['error' => 'channel_newer']);
        [$errors] = $this->submit(['SATTLEME_KEY' => FakeGateway::KEY]);
        self::assertSame(['This integration needs a newer version of the sattle.me module.'], $errors);

        $this->fake->setMerchant(['error' => 'network']);
        [$errors] = $this->submit(['SATTLEME_KEY' => FakeGateway::KEY]);
        self::assertSame(['sattle.me could not be reached to verify this key. Please try again in a moment.'], $errors);
        self::assertFalse(KeyVault::isSet());
    }

    public function testAnEmptyKeyFieldKeepsTheSavedKey(): void
    {
        [$errors] = $this->submit(['SATTLEME_KEY' => '', 'SATTLEME_MINUTES' => '30']);
        self::assertSame([], $errors);
        self::assertSame(FakeGateway::KEY, KeyVault::read());
        self::assertSame(30, Config::minutes());
        self::assertSame([], $this->fake->calls('merchant'));
    }

    public function testTheMethodIsTurnedOnOnlyWithAKey(): void
    {
        KeyVault::forget();
        \Configuration::updateValue(Config::ENABLED, '0');
        [$errors] = $this->submit(['SATTLEME_ENABLED' => '1']);
        self::assertSame(['Enter the integration key to accept bitcoin payments.'], $errors);
        self::assertFalse(Config::isEnabled());
        [$errors] = $this->submit(['SATTLEME_ENABLED' => '0']);
        self::assertSame([], $errors);
    }

    public function testTheTimeToPayIsOneToSixtyMinutes(): void
    {
        foreach (['0', '61', 'abc', '1.5', '', '-5'] as $minutes) {
            [$errors] = $this->submit(['SATTLEME_MINUTES' => $minutes]);
            self::assertSame(['The time to pay is a number of minutes, from 1 to 60.'], $errors, $minutes);
        }
        self::assertSame(15, Config::minutes());
        foreach (['1' => 1, '60' => 60, ' 45 ' => 45] as $minutes => $expected) {
            [$errors] = $this->submit(['SATTLEME_MINUTES' => (string) $minutes]);
            self::assertSame([], $errors);
            self::assertSame($expected, Config::minutes());
        }
    }

    public function testTheTitleAndDescriptionPerLanguage(): void
    {
        $french = (int) \Language::getIdByIso('fr');
        [$errors] = $this->submit([
            'SATTLEME_TITLE_' . $french => 'Bitcoin <b>Lightning</b>',
            'SATTLEME_DESCRIPTION_' . $french => "Ligne 1\nLigne 2",
        ]);
        self::assertSame([], $errors);
        self::assertSame('Bitcoin Lightning', Config::title($french));
        self::assertSame("Ligne 1\nLigne 2", Config::description($french));

        [$errors] = $this->submit(['SATTLEME_TITLE_' . $french => str_repeat('a', ConfigPage::TITLE_MAX + 1)]);
        self::assertSame(['The title is 128 characters at most, the description 1000.'], $errors);
        self::assertSame('Bitcoin Lightning', Config::title($french));
    }

    public function testTheCronLineOfTheBackgroundChecks(): void
    {
        [$cron] = $this->page->crons();
        self::assertSame(Platform::idShop(), $cron['id']);
        $url = $cron['url'];
        self::assertStringContainsString('module=sattleme', $url);
        self::assertStringContainsString('controller=cron', $url);
        self::assertStringContainsString('token=' . Config::cronToken(), $url);
        self::assertSame("* * * * * curl -fsS --max-time 120 '" . $url . "' > /dev/null", $cron['line']);
        self::assertFalse($this->page->status()['sattleme_multistore']);
        Config::setLastCronRun(time() - 30);
        self::assertFalse($this->page->crons()[0]['late']);
        Config::setLastCronRun(time() - 3600);
        self::assertTrue($this->page->crons()[0]['late']);
    }
}
