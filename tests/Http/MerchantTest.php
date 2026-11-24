<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Fake\FakeGateway;
use Sattleme\PrestaShop\Tests\Support\HttpShop;

/**
 * The merchant's side, over HTTP, in the shop's own back office: the module's settings (the key
 * checked when saved, never shown again, the form of the merchant's session only), and the URL of
 * the background checks its page gives.
 */
final class MerchantTest extends TestCase
{
    public function testTheKeyIsCheckedSavedAndNeverShownAgain(): void
    {
        $fake = HttpShop::fake();
        $merchant = HttpShop::merchant();
        $page = $merchant->configurePage();
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Background checks', $page->text());
        $form = HttpShop::settingsForm($page);
        // A write-only field: a password field, empty.
        self::assertSame('', $form->value('SATTLEME_KEY'));
        self::assertMatchesRegularExpression('/<input[^>]*type="password"[^>]*name="SATTLEME_KEY"|<input[^>]*name="SATTLEME_KEY"[^>]*type="password"/', $page->body);

        // A key the SDK refuses: not saved, never repeated.
        $refused = 'sattleme_ck_not-a-key-' . bin2hex(random_bytes(8));
        $page = $merchant->browser->follow(HttpShop::settingsForm($page)->with(['SATTLEME_KEY' => $refused, 'SATTLEME_ENABLED' => '1', 'submitSattlemeSettings' => '1'])->submit($merchant->browser));
        self::assertStringContainsString('This is not the secret key of a sattle.me integration', $page->text());
        self::assertStringNotContainsString($refused, $page->body);

        // A form of another session (no token, or a forged one): refused.
        $forged = HttpShop::settingsForm($page)->with(['SATTLEME_FORM_TOKEN' => str_repeat('0', 64), 'SATTLEME_KEY' => FakeGateway::KEY, 'submitSattlemeSettings' => '1']);
        self::assertStringContainsString('The form expired. Please try again.', $merchant->browser->follow($forged->submit($merchant->browser))->text());

        // The key: checked with the SDK (its merchant read), saved, then never shown again.
        $fake->setMerchant(['businessName' => 'Bitcoin <b>Bakery</b>']);
        $before = count($fake->calls('merchant'));
        $saved = HttpShop::configure($merchant);
        self::assertCount($before + 1, $fake->calls('merchant'));
        // Its business's name, from its app, shown as it is, escaped.
        self::assertStringContainsString('The integration key of Bitcoin <b>Bakery</b> is saved. It is never shown again.', $saved->text());
        $secret = explode('.', FakeGateway::KEY)[1];
        foreach ([$saved, $merchant->configurePage()] as $shown) {
            self::assertStringNotContainsString(FakeGateway::KEY, $shown->body);
            self::assertStringNotContainsString($secret, $shown->body);
            self::assertSame('', HttpShop::settingsForm($shown)->value('SATTLEME_KEY'));
            self::assertStringNotContainsString('<b>Bakery</b>', $shown->body);
        }
        self::assertStringContainsString('Customers are offered to pay with bitcoin.', $merchant->configurePage()->text());

        // Saved again with the field empty: the key stays, the SDK is not asked.
        $again = $merchant->browser->follow(HttpShop::settingsForm($merchant->configurePage())->with(['SATTLEME_KEY' => '', 'SATTLEME_MINUTES' => '15', 'submitSattlemeSettings' => '1'])->submit($merchant->browser));
        self::assertStringContainsString('Settings saved.', $again->text());
        self::assertCount($before + 1, $fake->calls('merchant'));
        $fake->setMerchant([]);
    }

    public function testTheSettingsChangeByAPostOnly(): void
    {
        $merchant = HttpShop::merchant();
        HttpShop::configure($merchant, '15');
        $form = HttpShop::settingsForm($merchant->configurePage())->with(['SATTLEME_MINUTES' => '7', 'SATTLEME_KEY' => '', 'submitSattlemeSettings' => '1']);
        // The form's fields, its tokens included, in a link the merchant follows (a GET).
        $query = http_build_query(array_map(static fn (array $field): string => $field[1], array_column($form->fields(), null, 0)));
        $answer = $merchant->browser->follow($merchant->browser->fetch($form->action . (str_contains($form->action, '?') ? '&' : '?') . $query));
        self::assertSame(200, $answer->status);
        self::assertStringNotContainsString('Settings saved.', $answer->text());
        self::assertSame('15', HttpShop::settingsForm($merchant->configurePage())->value('SATTLEME_MINUTES'));
        // The same form posted: saved.
        self::assertStringContainsString('Settings saved.', $merchant->browser->follow($form->submit($merchant->browser))->text());
        self::assertSame('7', HttpShop::settingsForm($merchant->configurePage())->value('SATTLEME_MINUTES'));
        HttpShop::configure($merchant, '15');
    }

    public function testTheBackgroundChecksRunByTheirSecretUrlOnly(): void
    {
        $merchant = HttpShop::merchant();
        HttpShop::configure($merchant);
        $page = $merchant->configurePage();
        $code = $page->byClass('code', 'sattleme-cron-url');
        self::assertCount(1, $code);
        // Wrapped within its panel, never wider than it: the legacy page's theme has no text-break.
        self::assertStringContainsString('word-break: break-all', $code[0]->getAttribute('style'));
        $url = trim($code[0]->textContent);
        self::assertMatchesRegularExpression('/[?&]token=[0-9a-f]{64}(&|$)/', $url);
        $cron = HttpShop::browser();

        $run = $cron->fetch($url);
        self::assertSame(200, $run->status, $run->body);
        self::assertMatchesRegularExpression('/^(Checked \d+ of \d+ invoices due \(\d+ skipped\), \d+ given up|Busy: another run of the checks is going on)$/', trim($run->body));
        self::assertStringContainsString('no-store', (string) $run->header('cache-control'));
        self::assertMatchesRegularExpression('/^text\/plain/', (string) $run->header('content-type'));

        foreach ([preg_replace('/token=[0-9a-f]{64}/', 'token=' . str_repeat('0', 64), $url), preg_replace('/token=[0-9a-f]{64}&?/', '', $url)] as $forged) {
            $refused = $cron->fetch((string) $forged);
            self::assertSame(403, $refused->status, (string) $forged);
            self::assertSame('Forbidden', trim($refused->body));
        }
        // The back office says when they last ran.
        self::assertStringContainsString('Last run:', $merchant->configurePage()->text());
    }
}
