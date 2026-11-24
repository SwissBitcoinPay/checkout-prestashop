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
 * The SDK's order reference of a payment attempt: made on the server, 128 bits from the CSPRNG,
 * never the order's number alone nor anything the browser sends. One attempt, one reference: no
 * deleted test order, restored backup or other installation holding the key ever shares it (the
 * SDK would hand out an earlier invoice of the reference, or report it paid).
 */
final class Reference
{
    /** The SDK's rule of a reference: 1 to 64 of A–Z a–z 0–9 . _ : - */
    public const PATTERN = '/^[A-Za-z0-9._:\-]{1,64}$/D';

    private function __construct()
    {
    }

    /** "prestashop-<cart>-<32 hex characters>": the cart for the merchant's eyes, the rest random. */
    public static function create(int $idCart): string
    {
        return 'prestashop-' . max(0, $idCart) . '-' . bin2hex(random_bytes(16));
    }
}
