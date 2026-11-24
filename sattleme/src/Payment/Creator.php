<?php

/**
 * sattle.me for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License that is bundled with this module in the file
 * LICENSE, also available at https://opensource.org/licenses/MIT
 *
 * @author    sattle.me
 * @copyright 2026 sattle.me
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace Sattleme\PrestaShop\Payment;

use Cart;
use Order;
use PaymentModule;
use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\Sdk\Errors;
use Sattleme\PrestaShop\Sdk\GatewayFactory;
use Sattleme\PrestaShop\Sdk\Logger;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\PaymentStatus;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Makes the invoices: at checkout, the cart's invoice, then its order; on the payment page, a new
 * invoice of an order whose last one ended unpaid.
 *
 * Under the cart's lock (in the database): two requests for one cart (a double click, two workers)
 * make one invoice and one order; the second waits, then gets the first one's. Each attempt has its
 * own random reference, stored before the SDK is asked: a call that failed, or whose answer was
 * lost, is tried again with it, and the SDK then hands out the invoice it made (InvoicePending), or
 * says it was paid (OrderPaid). One payable invoice per order: a new one only once the last one
 * ended unpaid.
 *
 * At checkout the invoice comes first: an order the SDK refuses (its amount out of the merchant's
 * limits, its currency without price, the integration removed) is refused with a message, and the
 * customer chooses another method; PrestaShop makes no order. The order is then made as payment
 * modules make it (PaymentModule::validateOrder), pending: "Awaiting bitcoin payment".
 */
final class Creator
{
    /** Of a createInvoice call (seconds): it runs in a web request. */
    public const CREATE_TIMEOUT = 15;

    /** Of the check of an invoice the SDK handed out again (seconds). */
    public const CHECK_TIMEOUT = 10;

    /** How long a request waits for another one of the same cart (seconds). */
    public const LOCK_WAIT = 25;

    /** An invoice made but not shown yet is shown at checkout with this much time left at least. */
    public const MIN_LEFT = 120;

    /** @var \PaymentModule */
    private $module;

    /** @var AttemptRepository */
    private $repository;

    /** @var Settler */
    private $settler;

    /** @var string */
    private $paymentName;

    public function __construct(\PaymentModule $module, AttemptRepository $repository, Settler $settler, string $paymentName)
    {
        $this->module = $module;
        $this->repository = $repository;
        $this->settler = $settler;
        $this->paymentName = $paymentName;
    }

    /**
     * At checkout: the cart's invoice, then its order, pending. The id of the cart's (first) order;
     * an order made already (a second request of a double click) is the one returned.
     *
     * When no invoice could be made, CreationRefusedException: PrestaShop made no order. When
     * another request held the cart too long, LockTimeoutException.
     */
    public function checkout(\Cart $cart, \Customer $customer): int
    {
        return Lock::with('cart', (int) $cart->id, self::LOCK_WAIT, function () use ($cart, $customer): int {
            $existing = Orders::idsForCart((int) $cart->id);
            if ($existing !== []) {
                return $existing[0];
            }
            $currency = new \Currency((int) $cart->id_currency);
            $amount = Amount::fromTotal((float) $cart->getOrderTotal(true, \Cart::BOTH), (int) $currency->precision);
            if (!\Validate::isLoadedObject($currency) || !Availability::accepts($amount, (string) $currency->iso_code)) {
                throw new CreationRefusedException('invalid_order');
            }
            $now = Clock::now();
            $attempt = $this->reusable((int) $cart->id, $amount, (string) $currency->iso_code, $now);
            if ($attempt === null) {
                $attempt = $this->repository->create((int) $cart->id_shop, (int) $cart->id, null, $this->newOrderReference(), Reference::create((int) $cart->id), $amount, (string) $currency->iso_code, $now);
            }
            $status = null;
            if ($attempt->state === Attempt::NEW) {
                [$attempt, $status] = $this->make($attempt);
            }

            // The order, pending, of the reference the invoice's note names.
            $this->module->validateOrder(
                (int) $cart->id,
                Config::awaitingState(),
                (float) $amount,
                $this->paymentName,
                null,
                [],
                (int) $cart->id_currency,
                false,
                $customer->secure_key,
                null,
                $attempt->orderReference,
            );
            $ids = Orders::idsForCart((int) $cart->id);
            if ($ids === []) {
                throw new \RuntimeException('PrestaShop made no order of the cart');
            }
            $this->repository->linkOrder($attempt->id, $ids[0]);
            Logger::info('Payment ' . $attempt->ref . ' made for ' . $amount . ' ' . $attempt->currency, 'Order', $ids[0]);
            if ($status !== null) {
                // An earlier invoice of the reference, paid or being confirmed: its status applies now.
                $this->applyNow($this->repository->find($attempt->id) ?? $attempt, $status);
            }

            return $ids[0];
        });
    }

    /**
     * A new invoice for the orders of a cart whose last attempt ended unpaid (or whose invoice could
     * not be made yet): the attempt as it is after.
     *
     * When no invoice could be made, CreationRefusedException; when another request held the cart
     * too long, LockTimeoutException.
     */
    public function renew(int $idCart): ?Attempt
    {
        return Lock::with('cart', $idCart, self::LOCK_WAIT, function () use ($idCart): ?Attempt {
            $orders = Orders::forCart($idCart);
            $latest = $this->repository->latestForCart($idCart);
            if (!self::mayRenew($latest, $orders)) {
                return $latest;
            }
            // A cancellation of its order let through, its state not written yet (or aborted by
            // another module, for Cancellation::WRITE_SECONDS at most): no new invoice.
            if ($this->repository->isCanceling($idCart)) {
                return $latest;
            }
            if ($latest !== null && $latest->state === Attempt::NEW) {
                $attempt = $latest;
            } else {
                // For what the orders still owe now, in their currency (the merchant may have changed
                // them since the last invoice: a product, a voucher, the currency).
                $first = $orders[0];
                $owed = Orders::owed($orders, null);
                $amount = $owed === null || str_starts_with($owed['amount'], '-') ? null : Amount::round($owed['amount'], Orders::precision($first));
                $currency = $owed['currency'] ?? '';
                if ($amount === null || !Availability::accepts($amount, $currency)) {
                    throw new CreationRefusedException('invalid_order');
                }
                $attempt = $this->repository->create((int) $first->id_shop, $idCart, (int) $first->id, (string) $first->reference, Reference::create($idCart), $amount, $currency, Clock::now());
            }
            [$attempt, $status] = $this->make($attempt);
            if ($status !== null) {
                $attempt = $this->applyNow($attempt, $status);
            }

            return $attempt;
        });
    }

    /**
     * Whether the orders may get a new invoice: none of them paid, closed or partly paid otherwise,
     * and their last attempt ended unpaid (nothing received), failed, or has no invoice yet.
     *
     * @param list<\Order> $orders
     */
    public static function mayRenew(?Attempt $latest, array $orders): bool
    {
        if ($orders === []) {
            return false;
        }
        foreach ($orders as $order) {
            if (Orders::isPaid($order) || Orders::isClosed($order) || Orders::hasPayments($order)) {
                return false;
            }
        }
        if ($latest === null) {
            return true;
        }

        return in_array($latest->state, [Attempt::NEW, Attempt::FAILED], true)
            || ($latest->state === Attempt::EXPIRED && !$latest->hasReceived());
    }

    /**
     * Asks the SDK for the attempt's invoice: [the attempt as stored after, the status that came with
     * an earlier invoice of its reference, or null].
     *
     * @return array{0: Attempt, 1: PaymentStatus|null}
     *
     * @throws CreationRefusedException
     */
    private function make(Attempt $attempt): array
    {
        $order = [
            'ref' => $attempt->ref,
            'fiat' => ['amount' => $attempt->amount, 'currency' => $attempt->currency],
            'durationSeconds' => Config::minutes($attempt->idShop) * 60,
            'note' => self::note((string) \Configuration::get('PS_SHOP_NAME', null, null, $attempt->idShop), $attempt->orderReference),
        ];
        $status = null;
        try {
            $gateway = GatewayFactory::forShop($attempt->idShop);
            if ($gateway === null) {
                throw new CreationRefusedException(Errors::NO_KEY);
            }
            try {
                $invoice = $gateway->createInvoice($order, (float) self::CREATE_TIMEOUT);
                $this->repository->storeInvoice($attempt->id, $invoice->toArray(), Attempt::ORIGIN_CREATED, Clock::now());
            } catch (InvoicePendingException $pending) {
                // An earlier call of this attempt made an invoice, whose answer was lost: shown
                // again only once a check found it open (else the customer could pay twice).
                $declared = $pending->getInvoice();
                $this->repository->storeInvoice($attempt->id, $declared->toArray(), Attempt::ORIGIN_PENDING, Clock::now());
                if ($declared->expiresAt > Clock::now()) {
                    try {
                        $status = $gateway->getStatus($declared->toArray(), (float) self::CHECK_TIMEOUT);
                    } catch (\Throwable $error) {
                        Logger::warning('The check of the pending invoice of payment ' . $attempt->ref . ' failed: ' . Errors::describe($error), 'Cart', $attempt->idCart);
                    }
                }
            } catch (OrderPaidException $paid) {
                // An earlier invoice of this attempt was paid: the order is paid with it.
                $this->repository->storeInvoice($attempt->id, $paid->getInvoice()->toArray(), Attempt::ORIGIN_PAID, Clock::now());
                $status = $paid->getStatus();
            }
        } catch (CreationRefusedException $refused) {
            $this->repository->creationFailed($attempt->id, $refused->getErrorCode());
            Logger::error('No invoice for payment ' . $attempt->ref . ': ' . $refused->getErrorCode(), 'Cart', $attempt->idCart);
            throw $refused;
        } catch (\Throwable $error) {
            $code = Errors::code($error);
            // Nothing was shown: the SDK gives an invoice only once it is declared. Tried again with
            // the same reference when the same call may succeed later, else never again.
            if (Errors::isRetryable($error)) {
                $this->repository->creationFailed($attempt->id, $code);
            } else {
                $this->repository->close($attempt->id, Attempt::FAILED, $code);
            }
            $log = 'No invoice for payment ' . $attempt->ref . ': ' . Errors::describe($error);
            if (in_array($code, ['network', 'rate', 'onchain_unavailable', Errors::TIMEOUT], true) || in_array($code, Errors::ORDER, true)) {
                Logger::warning($log, 'Cart', $attempt->idCart);
            } else {
                Logger::error($log, 'Cart', $attempt->idCart);
            }
            throw new CreationRefusedException($code, $error);
        }

        return [$this->repository->find($attempt->id) ?? $attempt, $status];
    }

    /**
     * Applies the status that came with an earlier invoice of the attempt's reference (Settler).
     * The order is made, and its invoice may be paid already: PrestaShop's failure to take it (the
     * database, a module reacting to the payment) never undoes the order nor sends the customer
     * back to pay; it is counted, logged, and applied again by the next check. The attempt as
     * stored after.
     */
    private function applyNow(Attempt $attempt, PaymentStatus $status): Attempt
    {
        try {
            return $this->settler->apply($attempt, $status, Clock::now());
        } catch (\Throwable $error) {
            $now = Clock::now();
            $this->repository->applyFailed($attempt->id, $now, Schedule::nextCheckAt($attempt->expiresAt ?? $now, $attempt->errors + 1, $now));
            Logger::error('Applying the status of payment ' . $attempt->ref . ' failed: ' . Errors::describe($error), 'Cart', $attempt->idCart);

            return $this->repository->find($attempt->id) ?? $attempt;
        }
    }

    /**
     * The attempt a checkout of the cart goes on with: its last one, for the same amount, not
     * final, made before no order: its invoice not made yet, or made and not shown yet, with time
     * left. The others of the cart without order, never shown, are abandoned.
     */
    private function reusable(int $idCart, string $amount, string $currency, int $now): ?Attempt
    {
        $reusable = null;
        foreach ($this->repository->allForCart($idCart) as $attempt) {
            if ($attempt->final || $attempt->idOrder !== null) {
                continue;
            }
            $same = $attempt->amount === $amount && $attempt->currency === $currency;
            $usable = $attempt->state === Attempt::NEW
                || ($attempt->state === Attempt::OPEN && $attempt->statusState() !== PaymentStatus::UNCONFIRMED
                    && $attempt->origin !== Attempt::ORIGIN_PENDING && $attempt->expiresAt !== null && $attempt->expiresAt - $now >= self::MIN_LEFT);
            if ($reusable === null && $same && $usable) {
                $reusable = $attempt;
                continue;
            }
            if ($attempt->state === Attempt::NEW || ($attempt->state === Attempt::OPEN && !$attempt->hasReceived() && $attempt->statusState() === null)) {
                // Never shown: its cart changed, or it is too close to its end.
                $this->repository->close($attempt->id, Attempt::ABANDONED, 'superseded');
            }
        }

        return $reusable;
    }

    /** A new reference of PrestaShop's for the order (its own generator), unused. */
    private function newOrderReference(): string
    {
        for ($try = 0; $try < 20; ++$try) {
            $reference = (string) \Order::generateReference();
            $used = Database::int(Database::value('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` WHERE `reference` = ' . Database::quote($reference)))
                + Database::int(Database::value('SELECT COUNT(*) FROM `' . AttemptRepository::table() . '` WHERE `order_reference` = ' . Database::quote($reference)));
            if ($reference !== '' && $used === 0) {
                return $reference;
            }
        }
        throw new \RuntimeException('No unused order reference');
    }

    /**
     * What the merchant's app and the customer's wallet show of the sale: the shop's name and the
     * order's reference, without control characters, 100 characters at most.
     */
    public static function note(string $shopName, string $orderReference): string
    {
        $clean = static fn (string $text): string => trim(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $text) ?? '');
        $shopName = $clean($shopName);
        $orderReference = $clean($orderReference);
        if ($shopName === '') {
            return mb_substr($orderReference, 0, 100);
        }
        $suffix = ' · ' . $orderReference;
        $room = 100 - mb_strlen($suffix);
        if ($room < 1) {
            return mb_substr($orderReference, 0, 100);
        }
        if (mb_strlen($shopName) > $room) {
            $shopName = rtrim(mb_substr($shopName, 0, $room - 1)) . '…';
        }

        return $shopName . $suffix;
    }
}
