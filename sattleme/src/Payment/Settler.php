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

use Order;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Privacy\Shredder;
use Sattleme\PrestaShop\Sdk\Logger;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\PaymentStatus;
use Sattleme\PrestaShop\Wordings;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Applies what a check of the server found (the SDK's getStatus, never the browser) to an attempt
 * and to the orders of its cart. Its caller holds the cart's lock: the payment page, the background
 * checks and the merchant's view never apply two statuses at once.
 *
 * - paid: the payment recorded once (its transaction id the attempt's reference), in the invoice's
 *   currency; then, when the invoice's price is what the orders still owe, the orders paid through
 *   PrestaShop's own path: the order state "Payment accepted" ("On backorder (paid)" for an order
 *   on backorder), its email, its invoice, the hooks of other modules. Else (their total or
 *   currency changed, another payment or a refund recorded, an order canceled or refunded
 *   meanwhile), "Bitcoin payment to check" with a note for the merchant (complete). An order paid
 *   otherwise is not changed: the merchant is told in the order's note.
 * - unconfirmed: the orders awaiting payment go to "Bitcoin payment being confirmed";
 * - expired: the attempt ends, never paid; what reached the merchant's wallet of an underpayment is
 *   noted on the orders, to settle by hand;
 * - open: an order "being confirmed" goes back to awaiting payment (its transaction was replaced or
 *   dropped).
 *
 * An attempt is final only once its orders were updated: a failure on the way (PrestaShop, a module
 * reacting to the payment) is applied again at the next check, and what was done is never done twice.
 */
final class Settler
{
    /** @var AttemptRepository */
    private $repository;

    /** @var string */
    private $paymentName;

    /** @var TranslatorInterface */
    private $translator;

    public function __construct(AttemptRepository $repository, string $paymentName, TranslatorInterface $translator)
    {
        $this->repository = $repository;
        $this->paymentName = $paymentName;
        $this->translator = $translator;
    }

    /**
     * @param int|null $scheduleFrom the start of the run (or of the check) the status came from:
     *                               the next background check counts from it (Schedule::nextCheckAt)
     */
    public function apply(Attempt $attempt, PaymentStatus $status, int $now, ?int $scheduleFrom = null): Attempt
    {
        if ($attempt->final) {
            return $attempt;
        }
        $state = self::stateOf($status, $attempt);
        $expiresAt = $attempt->expiresAt ?? $now;
        $this->repository->storeStatus($attempt->id, $status->toArray(), $state, $now, Schedule::nextCheckAt($expiresAt, 0, $scheduleFrom ?? $now));
        $attempt = $this->reload($attempt);
        $orders = Orders::forCart($attempt->idCart);
        if ($orders === []) {
            // Before its order is made (the checkout makes the invoice first): applied once it is.
            // An invoice whose order was never made was never shown: it ends with its status.
            // (The checkout holds the cart's lock from the invoice to the order: no check comes between.)
            if ($status->state === PaymentStatus::EXPIRED) {
                $this->repository->finish($attempt->id, Attempt::EXPIRED, false, $now);
            } elseif ($status->state === PaymentStatus::PAID) {
                Logger::error('Payment ' . $attempt->ref . ' was paid, and its cart ' . $attempt->idCart . ' has no order: settle it by hand', 'Cart', $attempt->idCart);
                $this->repository->finish($attempt->id, Attempt::PAID, false, $now);
            }

            return $this->ended($attempt);
        }
        switch ($status->state) {
            case PaymentStatus::PAID:
                $this->complete($attempt, $orders, $now);
                break;
            case PaymentStatus::EXPIRED:
                $this->expire($attempt, $orders, $now);
                break;
            case PaymentStatus::UNCONFIRMED:
                $this->move($orders, Config::awaitingState(), Config::confirmingState());
                break;
            default:
                $this->move($orders, Config::confirmingState(), Config::awaitingState());
        }

        return $this->ended($attempt);
    }

    /** The attempt as stored now: one that became final loses what its erasure asked for. */
    private function ended(Attempt $attempt): Attempt
    {
        $attempt = $this->reload($attempt);
        if ($attempt->final && $attempt->shredded) {
            (new Shredder($this->repository))->finished($attempt);
            $attempt = $this->reload($attempt);
        }

        return $attempt;
    }

    /**
     * The attempt's state while its status is stored: paid and expired are its final states only
     * once its orders were updated (finish).
     */
    private static function stateOf(PaymentStatus $status, Attempt $attempt): string
    {
        return match ($status->state) {
            PaymentStatus::OPEN => Attempt::OPEN,
            PaymentStatus::UNCONFIRMED => Attempt::UNCONFIRMED,
            default => in_array($attempt->state, [Attempt::OPEN, Attempt::UNCONFIRMED], true) ? $attempt->state : Attempt::OPEN,
        };
    }

    /**
     * A paid invoice, applied to the orders of its cart. Its price (the invoice's fiat: an amount
     * and a currency) is compared, exactly, with what the orders still owe now (Orders::owed):
     *
     * - the same, and no order canceled or refunded meanwhile: the orders are paid;
     * - else (the total raised or lowered in the back office, its currency changed, another payment
     *   or a credit slip recorded meanwhile, an order canceled, refunded or ended in a payment
     *   error): never "Payment accepted". The orders go to "Bitcoin payment to check", with a note
     *   for the merchant, who settles it with the customer, then sets the order's state.
     *
     * An order paid otherwise already is left as it is, with a note. The payment is recorded once,
     * in the invoice's currency, whatever the orders' is now. A completion that stopped half-way is
     * done again by the next check, the same way: what was decided (the note "to check") and done
     * (the payment, by its transaction id; an order's state) is never done twice.
     *
     * @param list<\Order> $orders
     */
    private function complete(Attempt $attempt, array $orders, int $now): void
    {
        $last = $orders[count($orders) - 1];
        $recorded = Orders::hasTransaction($last, $attempt->ref);
        $paidBefore = [];
        $closed = [];
        foreach ($orders as $order) {
            $paidBefore[(int) $order->id] = Orders::isPaid($order);
            $closed[(int) $order->id] = Orders::isClosed($order);
        }
        $price = $attempt->price();
        $owed = Orders::owed($orders, $attempt->ref);
        $exact = $price !== null && $owed !== null && $price['currency'] === $owed['currency']
            && !str_starts_with($owed['amount'], '-') && Amount::compare($price['amount'], $owed['amount']) === 0;
        $toCheck = $attempt->hasNote('to_check') || !$exact || in_array(true, $closed, true);
        // What came (a Lightning payment: its invoice's sats; on-chain: what the check received),
        // how, and what the invoice asked (its sats and its price).
        $sats = $attempt->paidSats() ?? '0';
        $how = $this->how($attempt);
        $invoiceSats = $attempt->sats() ?? '?';
        $priceText = $price === null ? $attempt->amount . ' ' . $attempt->currency : $price['amount'] . ' ' . $price['currency'];

        // 1. The payment, recorded once, as PrestaShop records a payment (by the orders' reference),
        // in the invoice's currency: what came is the invoice's price.
        if (!$recorded) {
            $currency = self::currency($price['currency'] ?? $attempt->currency);
            if ($last->addOrderPayment($price['amount'] ?? $attempt->amount, $this->paymentName, $attempt->ref, $currency) !== true) {
                throw new \RuntimeException('PrestaShop could not record the payment');
            }
        }

        $waiting = array_filter($orders, static fn (\Order $order): bool => !$paidBefore[(int) $order->id]);
        if ($toCheck && $waiting !== []) {
            // 2. What the merchant looks at, decided and told before any order moves: the next
            // check, after a failure, decides the same.
            if (!$attempt->hasNote('to_check')) {
                if (in_array(true, $closed, true)) {
                    $text = $this->trans(
                        'Bitcoin payment %ref% came for this order, which was canceled or refunded: %sats% sats came %how%, for an invoice of %invoice_sats% sats (%price%), and reached your wallet. The order waits in "Bitcoin payment to check" (taken out of Canceled or Payment error, it takes its products from the stock again): reinstate it, or refund the customer from your wallet.',
                        ['%ref%' => $attempt->ref, '%sats%' => $sats, '%how%' => $how, '%invoice_sats%' => $invoiceSats, '%price%' => $priceText],
                        'Modules.Sattleme.Admin',
                    );
                } elseif ($owed === null) {
                    $text = $this->trans(
                        'Bitcoin payment %ref% came: %sats% sats came %how%, for an invoice of %invoice_sats% sats (%price%), and reached your wallet. What the order owes now cannot be told exactly (a payment in another currency is recorded on it), so it waits in "Bitcoin payment to check": settle it with the customer, then set the order\'s state.',
                        ['%ref%' => $attempt->ref, '%sats%' => $sats, '%how%' => $how, '%invoice_sats%' => $invoiceSats, '%price%' => $priceText],
                        'Modules.Sattleme.Admin',
                    );
                } else {
                    $text = $this->trans(
                        'Bitcoin payment %ref% came: %sats% sats came %how%, for an invoice of %invoice_sats% sats (%price%), and reached your wallet. The order now owes %owed% (its total, less its other payments and its credit slips): it changed after the invoice was made, so it waits in "Bitcoin payment to check". Settle the difference with the customer, then set the order\'s state.',
                        ['%ref%' => $attempt->ref, '%sats%' => $sats, '%how%' => $how, '%invoice_sats%' => $invoiceSats, '%price%' => $priceText, '%owed%' => Amount::round($owed['amount'], Orders::precision($orders[0])) . ' ' . $owed['currency']],
                        'Modules.Sattleme.Admin',
                    );
                }
                $this->note($attempt, $orders, 'to_check', $text);
                Logger::warning('Payment ' . $attempt->ref . ' paid, its orders to check: ' . (in_array(true, $closed, true) ? 'canceled or refunded' : 'they owe another amount now'), 'Order', (int) $orders[0]->id);
            }
            $this->moveTo($waiting, Config::toCheckState(), false);
        } elseif (!$toCheck) {
            // 2. The orders paid, by PrestaShop's own path (its email, its invoice, the other modules' hooks).
            $this->moveTo($waiting, 0, true);
        }

        // 3. What else the merchant settles by hand, told once.
        // What came on-chain (and by the rest's invoices) other than the invoice's amount: the
        // merchant's tolerance or acceptance (in the sattle.me app) accepted less, or more reached
        // the wallet.
        if ($attempt->method() !== 'lightning' && $attempt->hasReceived() && $attempt->sats() !== null) {
            $difference = Amount::compare($attempt->received(), $attempt->sats(), 0);
            if ($difference < 0 && !$attempt->hasNote('received_less')) {
                $this->note($attempt, $orders, 'received_less', $this->trans(
                    'Bitcoin payment %ref%: %received% of %sats% sats came %how%, which the sattle.me app accepted as the payment (your tolerance, or your acceptance of what came).',
                    ['%ref%' => $attempt->ref, '%received%' => $attempt->received(), '%sats%' => $attempt->sats(), '%how%' => $how],
                    'Modules.Sattleme.Admin',
                ));
            } elseif ($difference > 0 && !$attempt->hasNote('received_more')) {
                $this->note($attempt, $orders, 'received_more', $this->trans(
                    'Bitcoin payment %ref%: %received% sats came %how% for an invoice of %sats% sats, %extra% sats more, which reached your wallet: refund them to the customer if needed.',
                    ['%ref%' => $attempt->ref, '%received%' => $attempt->received(), '%sats%' => $attempt->sats(), '%how%' => $how, '%extra%' => Amount::subtract($attempt->received(), $attempt->sats(), 0)],
                    'Modules.Sattleme.Admin',
                ));
            }
        }
        if (!$recorded && in_array(true, $paidBefore, true) && !$attempt->hasNote('paid_twice')) {
            $this->note($attempt, $orders, 'paid_twice', $this->trans(
                'This order was paid otherwise already, and bitcoin payment %ref% came too: %sats% sats came %how%, for an invoice of %invoice_sats% sats (%price%). Refund one of them.',
                ['%ref%' => $attempt->ref, '%sats%' => $sats, '%how%' => $how, '%invoice_sats%' => $invoiceSats, '%price%' => $priceText],
                'Modules.Sattleme.Admin',
            ));
        }
        $this->repository->finish($attempt->id, Attempt::PAID, true, $now);
        Logger::info('Payment ' . $attempt->ref . ' paid (' . ($attempt->method() ?? '?') . ')', 'Order', (int) $orders[0]->id);
    }

    /**
     * Moves the orders to "Bitcoin payment to check" ($state), or, $paid, to PrestaShop's
     * "Payment accepted" ("On backorder (paid)" for an order on backorder) with its email. An
     * order in that state already, or in "to check" (the merchant's to settle), is left as it is.
     *
     * @param array<\Order> $orders
     */
    private function moveTo(array $orders, int $state, bool $paid): void
    {
        foreach ($orders as $order) {
            $order = new \Order((int) $order->id);
            $current = (int) $order->current_state;
            $toCheck = Config::toCheckState();
            if ($paid) {
                $target = $current === (int) \Configuration::get('PS_OS_OUTOFSTOCK_UNPAID')
                    ? (int) \Configuration::get('PS_OS_OUTOFSTOCK_PAID')
                    : (int) \Configuration::get('PS_OS_PAYMENT');
            } else {
                $target = $state;
            }
            if ($target <= 0 || $current === $target || ($toCheck > 0 && $current === $toCheck)) {
                continue;
            }
            $history = new \OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState($target, $order, true);
            if ($paid) {
                $history->addWithemail(true);
            } else {
                $history->add();
            }
        }
    }

    /** A currency of the shop by its ISO code (one deleted since included), else an error. */
    private static function currency(string $iso): \Currency
    {
        $id = (int) \Currency::getIdByIsoCode($iso, 0, false, true);
        $currency = new \Currency($id);
        if ($id <= 0 || !\Validate::isLoadedObject($currency)) {
            throw new \RuntimeException('The invoice\'s currency ' . $iso . ' is not a currency of the shop');
        }

        return $currency;
    }

    /** @param list<\Order> $orders */
    private function expire(Attempt $attempt, array $orders, int $now): void
    {
        if ($attempt->hasReceived() && !$attempt->hasNote('underpaid')) {
            // Less than the amount came, and reached the merchant's wallet: not paid, settled by hand.
            $this->note($attempt, $orders, 'underpaid', $this->trans(
                // How it came is not said: an expired status names no method (its "received" may be
                // on-chain, or in Lightning for the rest's invoices).
                'The bitcoin invoice ended underpaid: %received% of %sats% sats came in time, and reached your wallet. Settle the difference with the customer (refund it, or have the rest paid otherwise). Reference: %ref%.',
                ['%received%' => $attempt->received(), '%sats%' => $attempt->sats() ?? '?', '%ref%' => $attempt->ref],
                'Modules.Sattleme.Admin',
            ));
            Logger::warning('Payment ' . $attempt->ref . ' ended underpaid: ' . $attempt->received() . ' of ' . ($attempt->sats() ?? '?') . ' sats', 'Order', (int) $orders[0]->id);
        }
        $this->move($orders, Config::confirmingState(), Config::awaitingState());
        $this->repository->finish($attempt->id, Attempt::EXPIRED, false, $now);
    }

    /**
     * How a paid invoice's bitcoin came, from its status's method, for the notes: in Lightning (the
     * invoice's amount, which has no "received"), on-chain, or both (on-chain, and the rest of an
     * underpayment by its Lightning invoices). The SDK makes a paid status with its method only
     * (PaymentStatus::lightning, onchain, mixed); the other statuses name none, and the notes then
     * say nothing of how.
     */
    private function how(Attempt $attempt): string
    {
        return match ($attempt->method()) {
            'lightning' => $this->trans('in Lightning', [], 'Modules.Sattleme.Admin'),
            'onchain' => $this->trans('on-chain', [], 'Modules.Sattleme.Admin'),
            'mixed' => $this->trans('on-chain and in Lightning (the rest of an underpayment)', [], 'Modules.Sattleme.Admin'),
            default => throw new \LogicException('A paid status names its method'),
        };
    }

    /**
     * Moves the orders in state $from to state $to (the module's states: they send no email).
     *
     * @param list<\Order> $orders
     */
    private function move(array $orders, int $from, int $to): void
    {
        if ($from <= 0 || $to <= 0) {
            return;
        }
        foreach ($orders as $order) {
            $order = new \Order((int) $order->id);
            if ((int) $order->current_state !== $from) {
                continue;
            }
            $history = new \OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState($to, $order, true);
            $history->add();
        }
    }

    /** @param list<\Order> $orders */
    private function note(Attempt $attempt, array $orders, string $note, string $text): void
    {
        Orders::note($orders, $text);
        $this->repository->addNote($attempt->id, $note);
    }

    /**
     * A note in the shop's language (the merchant reads it in the back office).
     *
     * @param array<string, string> $parameters
     */
    private function trans(string $text, array $parameters, string $domain): string
    {
        return Wordings::trans($this->translator, $text, $domain, Platform::defaultLocale(), $parameters);
    }

    private function reload(Attempt $attempt): Attempt
    {
        return $this->repository->find($attempt->id) ?? $attempt;
    }
}
