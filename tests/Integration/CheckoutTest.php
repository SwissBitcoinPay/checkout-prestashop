<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\CreationRefusedException;
use Sattleme\PrestaShop\Payment\Phase;
use Sattleme\PrestaShop\Payment\Reference;
use Sattleme\PrestaShop\Tests\Support\ShopTestCase;

/**
 * "Place order" with the method: the invoice first (a random reference stored before the SDK is
 * asked, the order locked), then PrestaShop's order, pending; refused orders make no order; one
 * payable invoice per order.
 */
final class CheckoutTest extends ShopTestCase
{
    public function testTheInvoiceThenThePendingOrder(): void
    {
        $cart = $this->cart();
        $total = $cart->getOrderTotal(true, \Cart::BOTH);
        $order = $this->checkout($cart);

        self::assertSame('sattleme', $order->module);
        self::assertSame(\Sattleme::PAYMENT_NAME, $order->payment);
        self::assertSame(Config::awaitingState(), (int) $order->current_state);
        self::assertSame([], self::payments($order));
        self::assertSame((string) $cart->secure_key, (string) $order->secure_key);

        $attempt = $this->attempt((int) $cart->id);
        self::assertMatchesRegularExpression('/^prestashop-' . (int) $cart->id . '-[0-9a-f]{32}$/', $attempt->ref);
        self::assertMatchesRegularExpression(Reference::PATTERN, $attempt->ref);
        self::assertSame((int) $order->id, $attempt->idOrder);
        self::assertSame((string) $order->reference, $attempt->orderReference);
        self::assertSame(number_format($total, 2, '.', ''), $attempt->amount);
        self::assertSame('CHF', $attempt->currency);
        self::assertSame(Attempt::OPEN, $attempt->state);
        self::assertSame(Attempt::ORIGIN_CREATED, $attempt->origin);

        // The invoice is stored as the SDK's JSON (SPEC.md §3), every field of it.
        self::assertNotNull($attempt->invoice);
        foreach (['ref', 'bolt11', 'paymentHash', 'verifyUrl', 'onchainAddress', 'paymentUri', 'sats', 'fiat', 'rate', 'note', 'createdAt', 'expiresAt', 'checkoutUrl', 'redirectUrl'] as $field) {
            self::assertArrayHasKey($field, $attempt->invoice);
        }
        self::assertSame($attempt->ref, $attempt->invoice['ref']);
        self::assertSame($attempt->invoice['expiresAt'], $attempt->expiresAt);

        // The SDK was asked once, with that reference, the cart's price, the time to pay.
        $calls = $this->fake->calls('create');
        self::assertCount(1, $calls);
        self::assertSame($attempt->ref, $calls[0]['ref']);
        self::assertSame(15 * 60, (int) $attempt->expiresAt - (int) $attempt->invoiceCreatedAt());
        self::assertSame('sattle.me test shop · ' . $order->reference, $attempt->invoice['note']);
        [$phase] = $this->module->phaseOf($order);
        self::assertSame(Phase::OPEN, $phase);
    }

    public function testEachAttemptHasItsOwnRandomReference(): void
    {
        $refs = [];
        for ($i = 0; $i < 3; ++$i) {
            $cart = $this->cart();
            $this->checkout($cart);
            $refs[] = $this->attempt((int) $cart->id)->ref;
        }
        self::assertCount(3, array_unique($refs));
    }

    public function testASecondPlaceOrderGetsTheFirstOrder(): void
    {
        $cart = $this->cart();
        $first = $this->checkout($cart);
        $second = $this->checkout(new \Cart((int) $cart->id));
        self::assertSame((int) $first->id, (int) $second->id);
        self::assertCount(1, self::orders((int) $cart->id));
        self::assertCount(1, $this->fake->calls('create'));
    }

    public function testAnOrderTheSdkRefusesIsNotMade(): void
    {
        $cart = $this->cart();
        $this->fake->setCreate(['mode' => 'error', 'error' => 'invalid_order']);
        try {
            $this->checkout($cart);
            self::fail('The order was made');
        } catch (CreationRefusedException $e) {
            self::assertSame('invalid_order', $e->getErrorCode());
        }
        self::assertSame([], self::orders((int) $cart->id));
        $attempt = $this->attempt((int) $cart->id);
        self::assertSame(Attempt::FAILED, $attempt->state);
        self::assertTrue($attempt->final);
        self::assertSame('invalid_order', $attempt->error);
    }

    public function testACallThatMayWorkLaterIsTriedAgainWithTheSameReference(): void
    {
        $cart = $this->cart();
        $this->fake->setCreate(['mode' => 'error', 'error' => 'network']);
        try {
            $this->checkout($cart);
            self::fail('The order was made');
        } catch (CreationRefusedException $e) {
            self::assertSame('network', $e->getErrorCode());
        }
        self::assertSame([], self::orders((int) $cart->id));
        $failed = $this->attempt((int) $cart->id);
        self::assertSame(Attempt::NEW, $failed->state);
        self::assertSame(1, $failed->errors);

        // A timeout too: its answer may have been lost, the SDK would hand its invoice out again.
        $this->fake->setCreate(['mode' => 'timeout']);
        try {
            $this->checkout(new \Cart((int) $cart->id));
            self::fail('The order was made');
        } catch (CreationRefusedException $e) {
            self::assertSame('timeout', $e->getErrorCode());
        }

        $this->fake->setCreate([]);
        $order = $this->checkout(new \Cart((int) $cart->id));
        $attempt = $this->attempt((int) $cart->id);
        self::assertSame($failed->id, $attempt->id);
        self::assertSame($failed->ref, $attempt->ref);
        self::assertSame(Attempt::OPEN, $attempt->state);
        self::assertSame(0, $attempt->errors);
        self::assertSame((int) $order->id, $attempt->idOrder);
        self::assertSame([$failed->ref, $failed->ref, $failed->ref], array_column($this->fake->calls('create'), 'ref'));
    }

    public function testAnInvoiceMadeEarlierIsShownOnlyOnceACheckFoundItOpen(): void
    {
        $cart = $this->cart();
        $this->fake->setCreate(['mode' => 'pending']);
        $order = $this->checkout($cart);
        $attempt = $this->attempt((int) $cart->id);
        self::assertSame(Attempt::ORIGIN_PENDING, $attempt->origin);
        // The checkout checked it at once: open, so shown.
        self::assertCount(1, $this->fake->calls('status'));
        self::assertSame('open', $attempt->statusState());
        [$phase] = $this->module->phaseOf($order);
        self::assertSame(Phase::OPEN, $phase);
    }

    public function testAnInvoiceHandedOutAgainIsHiddenUntilACheckFindsItOpen(): void
    {
        $cart = $this->cart();
        $this->fake->setCreate(['mode' => 'pending']);
        // The checkout's own check of it fails.
        $this->fake->setStatus('*', ['state' => 'error', 'error' => 'network']);
        $order = $this->checkout($cart);
        $attempt = $this->attempt((int) $cart->id);
        self::assertNull($attempt->statusState());
        [$phase] = $this->module->phaseOf($order);
        self::assertSame(Phase::CHECKING, $phase, 'not shown: it could have been paid already');

        $this->fake->setStatus('*', ['state' => 'open']);
        self::assertTrue($this->module->checker()->check($attempt->id, 0, 5));
        [$phase] = $this->module->phaseOf($order);
        self::assertSame(Phase::OPEN, $phase);
    }

    public function testAnInvoiceHandedOutAgainBeingConfirmedIsNeverShownToPay(): void
    {
        $cart = $this->cart();
        $this->fake->setCreate(['mode' => 'pending']);
        $this->fake->setStatus('*', ['state' => 'unconfirmed', 'received' => '30000', 'confirmations' => 1]);
        $order = $this->checkout($cart);
        [$phase] = $this->module->phaseOf($order);
        self::assertSame(Phase::UNCONFIRMED, $phase);
        self::assertSame(Config::confirmingState(), self::state($order));
    }

    public function testAnOrderPaidEarlierIsPaidAtOnce(): void
    {
        $cart = $this->cart();
        $this->fake->setCreate(['mode' => 'paid']);
        $order = $this->checkout($cart);
        self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
        $attempt = $this->attempt((int) $cart->id);
        self::assertSame(Attempt::PAID, $attempt->state);
        self::assertTrue($attempt->final);
        self::assertSame(Attempt::ORIGIN_PAID, $attempt->origin);
        $payments = self::payments($order);
        self::assertCount(1, $payments);
        self::assertSame($attempt->ref, $payments[0]['transaction_id']);
    }

    public function testTheTimeToPayIsTheSetting(): void
    {
        \Configuration::updateValue(Config::MINUTES, '1');
        $cart = $this->cart();
        $this->checkout($cart);
        $attempt = $this->attempt((int) $cart->id);
        self::assertSame(60, (int) $attempt->expiresAt - (int) $attempt->invoiceCreatedAt());
    }
}
