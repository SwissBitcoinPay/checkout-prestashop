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

namespace Sattleme\PrestaShop\Security;

use Sattleme\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's secrets compared: always in constant time (hash_equals).
 *
 * - The CSRF tokens of its forms: an HMAC of what the form acts on, under the module's secret, so
 *   another site can neither read nor make one (the checkout's "Place order" makes an order and its
 *   invoice; the payment page's buttons make a new invoice).
 * - The secret of a shop's cron URL.
 * - The secret of an order that opens its payment page: the order's own secure key, PrestaShop's,
 *   as its order confirmation page checks it.
 */
final class Tokens
{
    private function __construct()
    {
    }

    /**
     * The token of the checkout's form of the method: for one cart. Not for its customer: a checkout
     * may show the form before the customer's account exists (PrestaShop 9.2's One Page Checkout
     * shows the payment methods on its first page, before it makes the guest's account), and the
     * token must not change once the account is made. The validation checks that the cart is the
     * session's, and the customer the cart's.
     */
    public static function checkout(int $idCart): string
    {
        return self::mac('checkout|' . $idCart);
    }

    /** The token of the payment page's forms: for one order and its key. */
    public static function page(int $idOrder, string $secureKey): string
    {
        return self::mac('page|' . $idOrder . '|' . $secureKey);
    }

    /** Whether $given is $expected: a string, compared in constant time. */
    public static function equals(string $expected, mixed $given): bool
    {
        return is_string($given) && $expected !== '' && hash_equals($expected, $given);
    }

    private static function mac(string $data): string
    {
        return hash_hmac('sha256', $data, Config::secret());
    }
}
