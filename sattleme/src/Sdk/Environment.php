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

namespace Sattleme\PrestaShop\Sdk;

use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Client;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the SDK needs of PHP: PHP 8.2 or later, 64-bit, bcmath, OpenSSL with secp256k1 and ChaCha20.
 * PrestaShop 9 runs on PHP 8.1 too: there, the module refuses to install, and offers nothing.
 */
final class Environment
{
    public const MIN_PHP_VERSION_ID = 80200;

    /** @var string|false|null false: checked, nothing missing */
    private static $missing;

    private function __construct()
    {
    }

    /**
     * What this server lacks, in the SDK's words, or null when nothing. Checked once per request,
     * with no request to anyone: the SDK checks its environment when a client is made.
     */
    public static function missing(): ?string
    {
        if (self::$missing === null) {
            self::$missing = self::check();
        }

        return self::$missing === false ? null : self::$missing;
    }

    private static function check(): string|false
    {
        if (\PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            return 'PHP 8.2 or later (this server runs PHP ' . PHP_VERSION . ')';
        }
        try {
            // The environment is checked before the key: an empty key is refused only once it passed.
            new Client(['key' => '']);
        } catch (UnsupportedEnvironmentException $e) {
            return $e->getMessage();
        } catch (InvalidKeyException) {
            return false;
        }

        return false;
    }
}
