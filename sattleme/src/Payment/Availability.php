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

use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Client;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Whether an amount in a currency can be priced by the SDK, as SPEC.md §25 ("The price of an
 * order") says and the SDK checks it: a currency of its table (Client::currencyDigits, never
 * PrestaShop's locale data), an amount of 1 to 15 digits with a fraction of at most 15, at most the
 * currency's decimals once its trailing zeros are dropped, and of 1 to 2^53 − 1 minor units (the
 * amount × 10^decimals). Checked before any request: an order the SDK would refuse (invalid_order)
 * is never offered the method. The merchant's limits (the sats its Lightning address takes) are
 * known only when the invoice is made: an order out of them is refused then, with a message,
 * before PrestaShop creates the order.
 */
final class Availability
{
    /** The most minor units an order's price has: 2^53 − 1. */
    public const MAX_MINOR = '9007199254740991';

    private function __construct()
    {
    }

    public static function accepts(string $amount, string $currency): bool
    {
        $digits = Client::currencyDigits($currency);
        if ($digits === null || preg_match('/^([0-9]{1,15})(?:\.([0-9]{1,15}))?$/D', $amount, $match) !== 1) {
            return false;
        }
        $fraction = rtrim($match[2] ?? '', '0');
        if (strlen($fraction) > $digits) {
            return false;
        }
        // Its minor units, exactly: the digits of the amount, its fraction padded to the decimals.
        $minor = ltrim($match[1] . str_pad($fraction, $digits, '0'), '0');

        return $minor !== '' && (strlen($minor) < strlen(self::MAX_MINOR)
            || (strlen($minor) === strlen(self::MAX_MINOR) && strcmp($minor, self::MAX_MINOR) <= 0));
    }
}
