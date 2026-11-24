<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\Browser;
use Sattleme\PrestaShop\Tests\Support\HttpShop;

/**
 * Who may do what, over HTTP: the payment page and its status opened by the order's key only (its
 * number alone opens nothing), the status endpoint asking the server at most every few seconds,
 * "Place order" refused without its form's token or for another session's cart, the new invoice's
 * form refused without its token.
 */
final class AccessTest extends TestCase
{
    /** @var array{0: Browser, 1: string}|null a customer and its order's payment page */
    private static ?array $order = null;

    public static function setUpBeforeClass(): void
    {
        HttpShop::fake()->reset();
        HttpShop::configure(HttpShop::merchant());
    }

    /** @return array{0: Browser, 1: string} */
    private static function order(): array
    {
        if (self::$order === null) {
            $customer = HttpShop::browser();
            $placed = HttpShop::placeOrder($customer, HttpShop::paymentStep($customer, 'Alan'));
            self::assertSame(302, $placed->status);
            self::$order = [$customer, (string) $placed->location()];
        }

        return self::$order;
    }

    public function testThePaymentPageOpensWithTheOrdersKeyOnly(): void
    {
        [, $payUrl] = self::order();
        $stranger = HttpShop::browser();
        // The order's key opens it, in any session (the customer may pay from another device).
        $page = $stranger->get($payUrl);
        self::assertNotNull($page->element('sattleme-pay'));

        $wrongKey = (string) preg_replace('/key=[0-9a-f]{32}/', 'key=' . str_repeat('0', 32), $payUrl);
        $noKey = (string) preg_replace('/&?key=[0-9a-f]{32}/', '', $payUrl);
        $otherOrder = (string) preg_replace_callback('/id_order=(\d+)/', static fn (array $m): string => 'id_order=' . ((int) $m[1] - 1), $payUrl);
        $longKey = (string) preg_replace('/key=([0-9a-f]{32})/', 'key=$1' . str_repeat('a', 40), $payUrl);
        foreach ([$wrongKey, $noKey, $otherOrder, $longKey] as $url) {
            $refused = $stranger->get($url);
            self::assertSame(404, $refused->status, $url);
            self::assertNull($refused->element('sattleme-pay'), $url);
            self::assertStringNotContainsString('lnbc', $refused->body, $url);
        }
    }

    public function testTheStatusEndpointAnswersTheOrdersKeyOnlyAndAsksTheServerAtMostEverySoOften(): void
    {
        [$customer, $payUrl] = self::order();
        $page = $customer->fetch($payUrl);
        $section = $page->element('sattleme-pay');
        self::assertNotNull($section);
        $statusUrl = $section->getAttribute('data-sattleme-status-url');

        $refused = $customer->fetch((string) preg_replace('/key=[0-9a-f]{32}/', 'key=' . str_repeat('0', 32), $statusUrl), true);
        self::assertSame(404, $refused->status);
        self::assertSame(['error' => 'not_found'], $refused->json());
        self::assertStringContainsString('no-store', (string) $refused->header('cache-control'));

        // Asked many times in a row (pages open in several tabs): the server checks the invoice at
        // most once every 6 seconds, whatever the requests' pace.
        $fake = HttpShop::fake();
        $before = count($fake->calls('status'));
        $started = microtime(true);
        for ($i = 0; $i < 8; ++$i) {
            $answer = $customer->fetch($statusUrl, true);
            self::assertSame(200, $answer->status);
            self::assertSame('open', $answer->json()['phase'] ?? null);
        }
        $elapsed = microtime(true) - $started;
        $checks = count($fake->calls('status')) - $before;
        self::assertLessThanOrEqual(1 + (int) floor($elapsed / 6), $checks, "{$checks} checks in {$elapsed} s");
    }

    public function testPlaceOrderNeedsItsFormsTokenAndTheSessionsOwnCart(): void
    {
        $customer = HttpShop::browser();
        $step = HttpShop::paymentStep($customer, 'Edsger');
        $form = HttpShop::validationForm($step);
        $fake = HttpShop::fake();
        $before = count($fake->calls('create'));

        // A GET (a link, a prefetch): back to the checkout, nothing made.
        $get = $customer->fetch($form->action);
        self::assertSame(302, $get->status);
        self::assertTrue(HttpShop::isPage((string) $get->location(), 'order'), (string) $get->location());

        // Without its token, or with another one: refused.
        foreach ([$form->without('sattleme_token'), $form->with(['sattleme_token' => str_repeat('0', 64)])] as $forged) {
            $refused = $forged->submit($customer);
            self::assertSame(302, $refused->status);
            self::assertFalse(HttpShop::isPage((string) $refused->location(), 'pay', 'sattleme'));
            self::assertStringContainsString('Your session expired. Please place your order again.', $customer->follow($refused)->text());
        }

        // This cart's form, sent from another session (its token with it): refused, nothing made.
        $other = HttpShop::browser();
        HttpShop::paymentStep($other, 'Barbara');
        $stolen = $form->submit($other);
        self::assertSame(302, $stolen->status);
        self::assertFalse(HttpShop::isPage((string) $stolen->location(), 'pay', 'sattleme'));
        self::assertCount($before, $fake->calls('create'));

        // The customer's own: the order.
        $placed = $form->submit($customer);
        self::assertTrue(HttpShop::isPage((string) $placed->location(), 'pay', 'sattleme'));
        self::assertCount($before + 1, $fake->calls('create'));
    }

    public function testANewInvoiceNeedsThePagesToken(): void
    {
        [$customer, $payUrl] = self::order();
        // Its invoice ended unpaid: the page offers a new one, by a form with its token.
        $page = $customer->fetch($payUrl);
        if (preg_match('/Payment reference: (prestashop-[0-9]+-[0-9a-f]{32})/', $page->text(), $match) !== 1) {
            self::fail('No payment reference');
        }
        $fake = HttpShop::fake();
        $fake->setStatus($match[1], ['state' => 'expired', 'received' => '0']);
        $deadline = time() + 120;
        do {
            $page = $customer->fetch($payUrl);
        } while (!str_contains($page->text(), 'This invoice expired before it was paid.') && time() < $deadline && self::pause());
        $renew = null;
        foreach ($page->forms() as $form) {
            if ($form->has('sattleme_token')) {
                $renew = $form;
            }
        }
        self::assertNotNull($renew, substr($page->text(), 0, 500));
        $before = count($fake->calls('create'));

        $refused = $customer->follow($renew->with(['sattleme_token' => str_repeat('0', 64), 'sattleme_renew' => '1'])->submit($customer));
        self::assertStringContainsString('This page expired. Please try again.', $refused->text());
        self::assertCount($before, $fake->calls('create'));

        $renewed = $customer->follow($renew->with(['sattleme_renew' => '1'])->submit($customer));
        self::assertCount($before + 1, $fake->calls('create'));
        self::assertNotNull($renewed->element('sattleme-lightning'), 'a new invoice to pay');
        self::assertStringNotContainsString($match[1], $renewed->text(), 'of a new reference');
    }

    private static function pause(): bool
    {
        sleep(1);

        return true;
    }
}
