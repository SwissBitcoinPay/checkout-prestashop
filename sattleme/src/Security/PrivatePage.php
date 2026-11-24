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
 * The headers of the module's pages and endpoints: their addresses hold a secret (an order's key,
 * the cron token) and they show an invoice. Never stored by a cache, never indexed, their address
 * never sent to another site.
 */
final class PrivatePage
{
    private function __construct()
    {
    }

    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: same-origin');
    }
}
