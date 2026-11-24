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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Who may see an order's payment page and its status: whoever holds the order's secure key, as
 * PrestaShop's order confirmation page decides it (its link holds the key). The customer's session
 * plays no part: a guest is logged out once the order is confirmed, and the customer may pay from
 * another device. The order's number alone opens nothing.
 */
final class OrderAccess
{
    private function __construct()
    {
    }

    /**
     * The order a request names (its id_order and key), if the key is the order's, the order is
     * paid with this module and is of this shop; else null, whatever is wrong.
     */
    public static function order(string $moduleName, int $idShop, mixed $idOrder, mixed $key): ?\Order
    {
        if (!is_string($key) || $key === '' || strlen($key) > 64
            || !(is_string($idOrder) || is_int($idOrder)) || preg_match('/^[1-9][0-9]{0,9}$/D', (string) $idOrder) !== 1) {
            return null;
        }
        $order = new \Order((int) $idOrder);
        if (!\Validate::isLoadedObject($order) || $order->module !== $moduleName || (int) $order->id_shop !== $idShop) {
            return null;
        }

        return Tokens::equals((string) $order->secure_key, $key) ? $order : null;
    }
}
