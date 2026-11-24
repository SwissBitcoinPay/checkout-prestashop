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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Amounts as exact decimal strings ("24.50"), never floats once they leave PrestaShop: the SDK
 * takes an order's price as a string with at most its currency's decimals.
 */
final class Amount
{
    private const DECIMAL = '/^[0-9]{1,18}(\.[0-9]{1,18})?$/D';

    private function __construct()
    {
    }

    /**
     * A total as PrestaShop computes it (a float, already rounded to the currency's precision), as
     * a decimal string with that many decimals: 24.5 at 2 is "24.50". As PaymentModule::validateOrder
     * compares a cart's total with what was paid.
     */
    public static function fromTotal(float $total, int $precision): string
    {
        $precision = max(0, min(6, $precision));

        return number_format(round($total, $precision), $precision, '.', '');
    }

    /**
     * A decimal string of the database (DECIMAL(20,6)), rounded half up to $precision decimals (half
     * away from zero for a negative one, as PrestaShop's Tools::ps_round).
     */
    public static function round(string $value, int $precision): string
    {
        $value = trim($value);
        if (str_starts_with($value, '-')) {
            $rounded = self::round(substr($value, 1), $precision);

            return self::compare($rounded, '0') === 0 ? $rounded : '-' . $rounded;
        }
        $value = self::decimal($value);
        $precision = max(0, min(6, $precision));
        $half = '0.' . str_repeat('0', $precision) . '5';
        if (!is_numeric($half)) {
            throw new \LogicException('Not a number');
        }

        return bcadd(bcadd($value, $half, $precision + 1), '0', $precision);
    }

    /** $a + $b, exactly, with $scale decimals. */
    public static function add(string $a, string $b, int $scale): string
    {
        return bcadd(self::decimal($a), self::decimal($b), $scale);
    }

    /** $a − $b, exactly, with $scale decimals: negative ("-5.000000") when $b is the larger. */
    public static function subtract(string $a, string $b, int $scale): string
    {
        return bcsub(self::decimal($a), self::decimal($b), $scale);
    }

    /** -1, 0 or 1 as $a is below, equal to or above $b. */
    public static function compare(string $a, string $b, int $scale = 6): int
    {
        return bccomp(self::decimal($a), self::decimal($b), $scale);
    }

    public static function isDecimal(string $value): bool
    {
        return preg_match(self::DECIMAL, $value) === 1;
    }

    /** An amount in sats in bitcoins, exactly, with no trailing zero: "49000" is "0.00049". */
    public static function btc(string $sats): string
    {
        if (preg_match('/^[0-9]{1,18}$/D', $sats) !== 1 || !is_numeric($sats)) {
            throw new \InvalidArgumentException('Not an amount in sats');
        }
        $btc = bcdiv($sats, '100000000', 8);

        return str_contains($btc, '.') ? rtrim(rtrim($btc, '0'), '.') : $btc;
    }

    /**
     * A decimal amount as bcmath takes it, else an error.
     *
     * @return numeric-string
     */
    private static function decimal(string $value): string
    {
        if (preg_match(self::DECIMAL, $value) !== 1 || !is_numeric($value)) {
            throw new \InvalidArgumentException('Not a decimal amount');
        }

        return $value;
    }
}
