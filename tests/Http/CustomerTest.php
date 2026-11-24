<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\Browser;
use Sattleme\PrestaShop\Tests\Support\Form;
use Sattleme\PrestaShop\Tests\Support\HttpShop;
use Sattleme\PrestaShop\Tests\Support\Page;
use Sattleme\PrestaShop\Tests\Support\QrReader;

/**
 * A customer's payment, over HTTP, through the shop's own pages and without JavaScript: the
 * method at checkout, "Place order", the payment page (the QR code, the invoice, the amount, the
 * time left; private, never cached), its status endpoint, the payment being confirmed then paid
 * (the SDK's fake answering, through the module's test-only switch), the order's confirmation;
 * then the merchant's view of the order.
 */
final class CustomerTest extends TestCase
{
    /** Seconds the tests wait at most for what the server's checks find (a busy machine). */
    private const PATIENCE = 120;

    public static function setUpBeforeClass(): void
    {
        HttpShop::fake()->reset();
        HttpShop::configure(HttpShop::merchant());
    }

    public function testAGuestPaysAnOrderWithBitcoin(): void
    {
        $fake = HttpShop::fake();
        $customer = HttpShop::browser();
        $step = HttpShop::paymentStep($customer);
        self::assertStringContainsString('Pay with bitcoin', $step->text());
        $form = HttpShop::validationForm($step);
        self::assertSame('POST', $form->method);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $form->value('sattleme_token'));

        // "Place order": the invoice, the order, then its payment page.
        $placed = HttpShop::placeOrder($customer, $step);
        self::assertSame(302, $placed->status, substr($placed->text(), 0, 500));
        $payUrl = (string) $placed->location();
        self::assertTrue(HttpShop::isPage($payUrl, 'pay', 'sattleme'), $payUrl);
        self::assertMatchesRegularExpression('/[?&]key=[0-9a-f]{32}(&|$)/', $payUrl);
        self::assertCount(1, $fake->calls('create'));

        $page = $customer->fetch($payUrl);
        self::assertSame(200, $page->status);
        self::assertPrivate($page);
        $section = $page->element('sattleme-pay');
        self::assertNotNull($section, substr($page->text(), 0, 500));
        $text = (string) preg_replace('/\s+/u', ' ', $section->textContent);
        self::assertStringContainsString('Scan the QR code with your Bitcoin wallet', $text);
        self::assertMatchesRegularExpression('/Amount to pay [0-9,’\' .]+ sats [0-9.]+ BTC/u', $text);
        self::assertMatchesRegularExpression('/Time left to pay 1[45]:[0-5][0-9]/', $text);
        self::assertStringContainsString('Lightning invoice lnbc', $text);
        // The QR code: an SVG made by the server, an <img>'s data URI, that a scanner reads as the
        // invoice's payment URI, the wallet's link.
        $qr = $page->byClass('div', 'sattleme-pay__qr');
        self::assertCount(1, $qr);
        $image = $qr[0]->getElementsByTagName('img')->item(0);
        self::assertNotNull($image);
        self::assertSame('QR code of the bitcoin invoice', $image->getAttribute('alt'));
        $wallet = $page->byClass('a', 'sattleme-pay__wallet');
        self::assertCount(1, $wallet);
        self::assertSame($wallet[0]->getAttribute('href'), QrReader::image($image->getAttribute('src')));
        self::assertStringStartsWith('bitcoin:', $wallet[0]->getAttribute('href'));
        self::assertStringContainsString('&lightning=lnbc', $wallet[0]->getAttribute('href'));
        self::assertMatchesRegularExpression('/^bc1/', (string) $page->element('sattleme-address')?->getAttribute('value'));
        // Without JavaScript: a link to check again, the page itself.
        $check = $page->byClass('a', 'sattleme-pay__check');
        self::assertCount(1, $check);
        self::assertSame($payUrl, Page::resolve($page->url, $check[0]->getAttribute('href')));
        // Its script, a file of the module; nothing of the module inline, nothing from elsewhere.
        $scripts = array_values(array_filter($page->urls('script', 'src'), static fn (string $url): bool => str_contains($url, 'sattleme')));
        self::assertCount(1, $scripts);
        self::assertStringStartsWith(HttpShop::url() . '/modules/sattleme/views/js/pay.js', $scripts[0]);
        foreach ($page->urls('script', 'src') as $url) {
            self::assertStringStartsWith(HttpShop::url() . '/', $url, 'a script of the shop');
        }
        self::assertSame(0, $section->getElementsByTagName('script')->length, 'no script in the module\'s content');
        $ref = self::reference($page);

        // The merchant cannot cancel it while it can be paid: the back office says why. (Later in
        // this test, the order is paid as "Payment accepted", never "to check": it was not canceled.)
        $merchant = HttpShop::merchant();
        $view = $merchant->orderPage(self::idOrder($payUrl));
        $state = 'update_order_status[new_order_status_id]';
        $change = $view->form(static fn (Form $form): bool => $form->has($state), 'to change the order\'s state');
        $refused = $merchant->browser->follow($change->with([$state => HttpShop::choice($change, $state, 'Canceled', $view)])->submit($merchant->browser));
        self::assertSame(200, $refused->status);
        self::assertMatchesRegularExpression('/sattle\\.me: order [A-Z]{9} was not canceled: its bitcoin invoice can still be paid/', $refused->text(), 'the order named by its reference');
        // One order changed from its page: the sentence on a change of several orders is a condition,
        // not a statement (the hook cannot tell the one from the other: PrestaShop gives it the same).
        self::assertStringContainsString('If you changed several orders at once, the change stopped at this order: the ones after it were left as they were.', $refused->text());
        self::assertStringNotContainsString('A change of several orders at once stops', $refused->text());
        self::assertStringContainsString('Scan the QR code with your Bitcoin wallet', $customer->fetch($payUrl)->text(), 'still to pay');

        // Nor "Cancel products" (the order page's form, posted to its cancellation as the page's
        // script does): refused before PrestaShop changes anything, its total and its stock as they were.
        $idOrder = self::idOrder($payUrl);
        $view = $merchant->orderPage($idOrder);
        $products = $view->form(static fn (Form $form): bool => $form->has('cancel_product[_token]'), 'to cancel the order\'s products');
        $selected = array_values(array_filter(array_keys($products->choices), static fn (string $name): bool => preg_match('/^cancel_product\[selected_\d+\]$/', $name) === 1));
        self::assertCount(1, $selected);
        $detail = (string) preg_replace('/\D/', '', $selected[0]);
        $shown = static fn (Page $page): array => [
            trim((string) $page->element('orderTotal')?->textContent),
            array_map(static fn (\DOMElement $cell): string => trim($cell->textContent), $page->byClass('td', 'cellProductAvailableQuantity')),
        ];
        $before = $shown($view);
        self::assertNotSame('', $before[0]);
        $answer = $merchant->browser->follow($merchant->browser->post($merchant->url('sell/orders/' . $idOrder . '/cancellation'), $products->with([
            'cancel_product[selected_' . $detail . ']' => '1',
            'cancel_product[quantity_' . $detail . ']' => '1',
        ])->fields()));
        self::assertSame(200, $answer->status);
        self::assertMatchesRegularExpression('/sattle\.me: the products of order [A-Z]{9} were not canceled, nor removed: its bitcoin invoice can still be paid/', $answer->text());
        self::assertSame($before, $shown($merchant->orderPage($idOrder)), 'its total and its stock as they were');

        // Its status endpoint, as the page's script asks it.
        $status = $customer->fetch(self::statusUrl($section), true);
        self::assertSame(200, $status->status);
        self::assertPrivate($status);
        self::assertSame('open', $status->json()['phase'] ?? null);

        // Paid on-chain, being confirmed: "Check again" shows it.
        $fake->setStatus($ref, ['state' => 'unconfirmed', 'received' => '1000', 'confirmations' => 1]);
        $page = self::until($customer, $payUrl, static fn (Page $page): bool => str_contains($page->text(), 'Your payment came in time.'));
        self::assertStringContainsString('The Bitcoin network is confirming its on-chain transaction', $page->text());
        self::assertStringContainsString('1 of 3 confirmations', $page->text());
        // Nothing final while it is unconfirmed: it may still be replaced or dropped.
        self::assertStringNotContainsString('was received', $page->text());
        self::assertNull($page->element('sattleme-lightning'), 'nothing to pay any more');

        // Paid: the status sends the page to the order's confirmation.
        $fake->setStatus($ref, ['state' => 'paid', 'method' => 'onchain', 'received' => '1000']);
        $deadline = time() + self::PATIENCE;
        do {
            $answer = $customer->fetch(self::statusUrl($section), true)->json();
        } while (($answer['phase'] ?? null) !== 'paid' && time() < $deadline && self::pause());
        self::assertSame('paid', $answer['phase'] ?? null);
        $confirmation = $answer['redirect'] ?? null;
        self::assertIsString($confirmation);
        self::assertStringStartsWith(HttpShop::url() . '/', $confirmation);
        self::assertTrue(HttpShop::isPage($confirmation, 'order-confirmation'), $confirmation);
        $confirmed = $customer->get($confirmation);
        self::assertSame(200, $confirmed->status);
        self::assertStringContainsString('Your bitcoin payment was received. Thank you!', $confirmed->text());
        // The payment page, once paid, leads there too.
        self::assertTrue(HttpShop::isPage((string) $customer->fetch($payUrl)->location(), 'order-confirmation'));

        // The merchant's view of the order: paid, by PrestaShop's own path, with the reference.
        $idOrder = self::idOrder($payUrl);
        $view = HttpShop::merchant()->orderPage($idOrder);
        self::assertSame(200, $view->status);
        $merchantText = $view->text();
        self::assertStringContainsString('Payment accepted', $merchantText);
        self::assertStringContainsString('Bitcoin payments (sattle.me)', $merchantText);
        self::assertStringContainsString($ref, $merchantText);
        self::assertStringContainsString('On-chain', $merchantText);
        // Its transactions as text to copy, linked to no other site (PrestaShop Addons).
        self::assertMatchesRegularExpression('#<code class="text-break user-select-all">[0-9a-f]{64}</code>#', $view->body);
        self::assertStringNotContainsString('mempool.space', $view->body);
    }

    /**
     * An order of a virtual product, which PrestaShop completes at once (no delivery), paid while
     * no page of it is open: the background checks alone (the cron URL, a request with no
     * customer nor employee) record it, through PrestaShop's own path ("Payment accepted", its
     * e-mails, its invoice), once.
     */
    public function testAVirtualProductsOrderIsPaidByTheBackgroundChecksAlone(): void
    {
        $fake = HttpShop::fake();
        $customer = HttpShop::browser();
        $placed = HttpShop::placeOrder($customer, HttpShop::paymentStep($customer, 'Grace', HttpShop::VIRTUAL_PRODUCT));
        self::assertSame(302, $placed->status, substr($placed->text(), 0, 500));
        $payUrl = (string) $placed->location();
        self::assertTrue(HttpShop::isPage($payUrl, 'pay', 'sattleme'), $payUrl);
        $creates = $fake->calls('create');
        $ref = $creates[count($creates) - 1]['ref'];
        // Paid in Lightning; its payment page never opened again.
        $fake->setStatus($ref, ['state' => 'paid', 'method' => 'lightning']);

        $merchant = HttpShop::merchant();
        $code = $merchant->configurePage()->byClass('code', 'sattleme-cron-url');
        self::assertCount(1, $code);
        $cron = HttpShop::browser();
        $checked = static fn (): int => count(array_filter($fake->calls('status'), static fn (array $call): bool => $call['ref'] === $ref));
        $deadline = time() + self::PATIENCE;
        do {
            $run = $cron->fetch(trim($code[0]->textContent));
            self::assertSame(200, $run->status, $run->body);
        } while ($checked() === 0 && time() < $deadline && self::pause());
        self::assertSame(1, $checked(), 'checked once, by the background checks');

        $view = $merchant->orderPage(self::idOrder($payUrl));
        self::assertSame(200, $view->status);
        $text = (string) preg_replace('/\s+/u', ' ', $view->text());
        // "Paid": the attempt is final only once PrestaShop took the payment and the order went to
        // "Payment accepted" (else "Paid, not recorded in PrestaShop yet").
        $attempts = $view->byClass('div', 'sattleme-attempt');
        self::assertCount(1, $attempts);
        self::assertSame('Paid', trim((string) $attempts[0]->getElementsByTagName('h4')->item(0)?->textContent));
        self::assertStringContainsString($ref, $text);
        self::assertStringNotContainsString('Paid, not recorded in PrestaShop yet', $text);
        self::assertStringNotContainsString('paid otherwise already', $text);
        // Final: the next runs leave it alone.
        $cron->fetch(trim($code[0]->textContent));
        self::assertSame(1, $checked());
        // Its customer's confirmation says it was received.
        self::assertStringContainsString('Your bitcoin payment was received. Thank you!', $customer->get((string) $customer->fetch($payUrl)->location())->text());
    }

    public function testAnOrderTheSdkRefusesStaysAtCheckoutWithoutAnOrder(): void
    {
        $fake = HttpShop::fake();
        $customer = HttpShop::browser();
        $step = HttpShop::paymentStep($customer, 'Grace');
        $fake->setCreate(['mode' => 'error', 'error' => 'invalid_order']);
        try {
            $placed = HttpShop::placeOrder($customer, $step);
        } finally {
            $fake->setCreate([]);
        }
        self::assertSame(302, $placed->status);
        self::assertFalse(HttpShop::isPage((string) $placed->location(), 'pay', 'sattleme'));
        $back = $customer->follow($placed);
        self::assertStringContainsString('This order cannot be paid with bitcoin. Please choose another payment method.', $back->text());
        // Still at checkout, its cart unchanged: another method may be chosen.
        self::assertSame('POST', HttpShop::validationForm($back)->method);
    }

    /** The headers of a private page: never stored, never indexed, its address never sent away. */
    private static function assertPrivate(Page $page): void
    {
        self::assertStringContainsString('no-store', (string) $page->header('cache-control'));
        self::assertStringContainsString('noindex', (string) $page->header('x-robots-tag'));
        self::assertSame('same-origin', $page->header('referrer-policy'));
    }

    private static function reference(Page $page): string
    {
        if (preg_match('/Payment reference: (prestashop-[0-9]+-[0-9a-f]{32})/', $page->text(), $match) !== 1) {
            self::fail('No payment reference on the page');
        }

        return $match[1];
    }

    private static function statusUrl(\DOMElement $section): string
    {
        $url = $section->getAttribute('data-sattleme-status-url');
        self::assertTrue(HttpShop::isPage($url, 'status', 'sattleme'), $url);

        return $url;
    }

    private static function idOrder(string $payUrl): int
    {
        parse_str((string) parse_url($payUrl, PHP_URL_QUERY), $query);
        $id = $query['id_order'] ?? null;
        self::assertIsString($id);

        return (int) $id;
    }

    /**
     * The page, asked again (as its "Check again" button does) until $done says so, or fails.
     *
     * @param callable(Page): bool $done
     */
    private static function until(Browser $browser, string $url, callable $done): Page
    {
        $deadline = time() + self::PATIENCE;
        do {
            $page = $browser->fetch($url);
            if ($done($page)) {
                return $page;
            }
        } while (time() < $deadline && self::pause());
        self::fail('Not shown within ' . self::PATIENCE . ' s: ' . substr($page->text(), 0, 500));
    }

    /** A second between two asks: the server checks an invoice at most every few seconds anyway. */
    private static function pause(): bool
    {
        sleep(1);

        return true;
    }
}
