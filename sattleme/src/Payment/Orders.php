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

use Sattleme\PrestaShop\Database;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The PrestaShop orders of a cart. PrestaShop makes one order per package of a cart (products
 * shipped by different carriers), all of one reference and one payment: an attempt pays them all
 * (PaymentModule::validateOrder makes an order per package, of one reference, and records a
 * payment by that reference: Order::addOrderPayment).
 */
final class Orders
{
    private function __construct()
    {
    }

    /** @return list<int> the orders made from a cart, the first first */
    public static function idsForCart(int $idCart): array
    {
        $rows = Database::rows('SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . $idCart . ' ORDER BY `id_order` ASC');

        return array_map(static fn (array $row): int => Database::int($row['id_order'] ?? null), $rows);
    }

    /** @return list<\Order> */
    public static function forCart(int $idCart): array
    {
        $orders = [];
        foreach (self::idsForCart($idCart) as $id) {
            $order = new \Order($id);
            if (\Validate::isLoadedObject($order)) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    /**
     * What the orders of a cart still owe, in their currency: their total (total_paid_tax_incl:
     * products, shipping and wrapping, less the vouchers applied to them, at checkout or since in
     * the back office), less what the payments recorded on their reference paid (another method's,
     * a part paid in cash; an attempt's own, by its transaction id $ownTransaction, excepted),
     * less what their credit slips refunded (an OrderSlip's products and shipping, tax included,
     * as PrestaShop's order page counts a credit slip). A decimal string with 6 decimals, below 0
     * when payments and refunds exceed the total. Null when it cannot be told exactly: orders in
     * two currencies, or a payment in another currency than theirs (PrestaShop converts it through
     * its rates, with floats).
     *
     * @param list<\Order> $orders
     *
     * @return array{amount: string, currency: string}|null
     */
    public static function owed(array $orders, ?string $ownTransaction): ?array
    {
        if ($orders === []) {
            return null;
        }
        $currencies = array_values(array_unique(array_map(static fn (\Order $order): int => (int) $order->id_currency, $orders)));
        $iso = self::currencyIso($orders[0]);
        if (count($currencies) !== 1 || $iso === '') {
            return null;
        }
        $ids = implode(', ', array_map(static fn (\Order $order): int => (int) $order->id, $orders));
        $references = implode(', ', array_map(static fn (string $reference): string => Database::quote($reference), array_values(array_unique(array_map(static fn (\Order $order): string => (string) $order->reference, $orders)))));
        $totals = array_map(static fn (array $row): string => Database::string($row['total_paid_tax_incl'] ?? null), Database::rows('SELECT `total_paid_tax_incl` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` IN (' . $ids . ')'));
        $deductions = [];
        foreach (Database::rows('SELECT `amount`, `id_currency`, `transaction_id` FROM `' . _DB_PREFIX_ . 'order_payment` WHERE `order_reference` IN (' . $references . ')') as $row) {
            if ($ownTransaction !== null && Database::string($row['transaction_id'] ?? null) === $ownTransaction) {
                continue;
            }
            if (Database::int($row['id_currency'] ?? null) !== $currencies[0]) {
                return null;
            }
            $deductions[] = Database::string($row['amount'] ?? null);
        }
        foreach (Database::rows('SELECT `total_products_tax_incl`, `total_shipping_tax_incl` FROM `' . _DB_PREFIX_ . 'order_slip` WHERE `id_order` IN (' . $ids . ')') as $row) {
            $deductions[] = Database::string($row['total_products_tax_incl'] ?? null);
            $deductions[] = Database::string($row['total_shipping_tax_incl'] ?? null);
        }
        $total = self::sum($totals);
        $deducted = self::sum($deductions);
        if ($total === null || $deducted === null) {
            return null;
        }

        return ['amount' => Amount::subtract($total, $deducted, 6), 'currency' => $iso];
    }

    /**
     * The sum of amounts as PrestaShop stores them (DECIMAL(20,6)), exactly; null when one is not
     * a decimal of the module's (a negative payment).
     *
     * @param list<string> $values
     */
    private static function sum(array $values): ?string
    {
        $sum = '0';
        foreach ($values as $value) {
            if (!Amount::isDecimal($value)) {
                return null;
            }
            $sum = Amount::add($sum, $value, 6);
        }

        return $sum;
    }

    /** The decimals of an order's currency, as PrestaShop rounds its amounts. */
    public static function precision(\Order $order): int
    {
        $currency = new \Currency((int) $order->id_currency);

        return \Validate::isLoadedObject($currency) ? (int) $currency->precision : 2;
    }

    public static function currencyIso(\Order $order): string
    {
        $currency = new \Currency((int) $order->id_currency);

        return \Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '';
    }

    /** Whether PrestaShop has the order paid: its state is a paid one. */
    public static function isPaid(\Order $order): bool
    {
        $state = new \OrderState((int) $order->current_state);

        return \Validate::isLoadedObject($state) && (bool) $state->paid;
    }

    /** Whether the order was canceled, refunded, or ended in a payment error. */
    public static function isClosed(\Order $order): bool
    {
        return in_array((int) $order->current_state, [
            (int) \Configuration::get('PS_OS_CANCELED'),
            (int) \Configuration::get('PS_OS_REFUND'),
            (int) \Configuration::get('PS_OS_ERROR'),
        ], true);
    }

    /** Whether something was paid on the order already (a payment recorded, by any method). */
    public static function hasPayments(\Order $order): bool
    {
        return Database::int(Database::value('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_payment` WHERE `order_reference` = '
            . Database::quote((string) $order->reference))) > 0;
    }

    /** Whether the order's payments hold one with this transaction id (the attempt's reference). */
    public static function hasTransaction(\Order $order, string $transactionId): bool
    {
        return Database::int(Database::value('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_payment` WHERE `order_reference` = '
            . Database::quote((string) $order->reference) . ' AND `transaction_id` = ' . Database::quote($transactionId))) > 0;
    }

    /**
     * Adds a line to the internal note of the orders (the merchant's only, in the order's page of
     * the back office): what the merchant has to settle by hand. One statement per order, so that
     * two lines written at once both stay.
     *
     * @param list<\Order> $orders
     */
    public static function note(array $orders, string $line): void
    {
        $line = '[sattle.me ' . date('Y-m-d H:i') . '] ' . trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $line) ?? '');
        foreach ($orders as $order) {
            Database::execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET `note` = IF(`note` IS NULL OR `note` = \'\', ' . Database::quote($line)
                . ', CONCAT(`note`, \'\\n\', ' . Database::quote($line) . ')) WHERE `id_order` = ' . (int) $order->id);
            // PrestaShop keeps the orders it loaded in this request: they are read again with the note.
            \Cache::clean('objectmodel_Order_' . (int) $order->id . '_*');
        }
    }
}
