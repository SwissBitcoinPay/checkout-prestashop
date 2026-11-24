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

namespace Sattleme\PrestaShop;

use PhpEncryption;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The integration key, encrypted at rest: with PrestaShop's own encryption (PhpEncryption, an
 * authenticated encryption, AES-256 and HMAC-SHA256) under the shop's new_cookie_key of
 * app/config/parameters.php, never in the database in the clear. A copy of the database alone
 * does not give it; a shop whose new_cookie_key changed (its database restored on another
 * installation) cannot read it, and its key is entered again.
 *
 * The key never leaves the server: no page, log or exception holds it.
 */
final class KeyVault
{
    /** What a stored key starts with: the version of this encoding. */
    private const PREFIX = 'v1:';

    private function __construct()
    {
    }

    public static function store(#[\SensitiveParameter] string $key, ?int $idShop = null): void
    {
        \Configuration::updateValue(Config::KEY, self::PREFIX . self::cipher()->encrypt($key), false, null, $idShop);
    }

    public static function forget(?int $idShop = null): void
    {
        \Configuration::updateValue(Config::KEY, '', false, null, $idShop);
    }

    /** Whether a key is stored (readable or not). */
    public static function isSet(?int $idShop = null): bool
    {
        $stored = \Configuration::get(Config::KEY, null, null, $idShop);

        return is_string($stored) && $stored !== '';
    }

    /**
     * The key, or null when none is stored or it cannot be read (the shop's encryption key changed).
     */
    public static function read(?int $idShop = null): ?string
    {
        $stored = \Configuration::get(Config::KEY, null, null, $idShop);
        if (!is_string($stored) || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        try {
            $key = self::cipher()->decrypt(substr($stored, strlen(self::PREFIX)));
        } catch (\Throwable) {
            return null;
        }

        return is_string($key) && $key !== '' ? $key : null;
    }

    private static function cipher(): \PhpEncryption
    {
        return new \PhpEncryption(_NEW_COOKIE_KEY_);
    }
}
