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

use Sattleme\PrestaShop\KeyVault;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The gateway of an integration key: the SDK's client.
 *
 * The end-to-end tests replace the SDK with a fake of theirs through a test-only switch, the
 * constant _SATTLEME_TEST_GATEWAY_ (the path of a PHP file that returns a
 * Closure(string $key): Gateway). The module never defines it: only the tests do, in
 * config/defines_custom.inc.php of a test shop.
 */
final class GatewayFactory
{
    /** @var (\Closure(string): Gateway)|null */
    private static $test;

    private function __construct()
    {
    }

    /**
     * @throws InvalidKeyException
     * @throws UnsupportedEnvironmentException
     */
    public static function forKey(#[\SensitiveParameter] string $key): Gateway
    {
        $test = self::testGateway();

        return $test !== null ? $test($key) : new SdkGateway($key);
    }

    /**
     * The gateway of the key stored for a shop, or null when none is set or it cannot be read.
     *
     * @throws InvalidKeyException
     * @throws UnsupportedEnvironmentException
     */
    public static function forShop(?int $idShop = null): ?Gateway
    {
        $key = KeyVault::read($idShop);

        return $key === null ? null : self::forKey($key);
    }

    /** @return (\Closure(string): Gateway)|null */
    private static function testGateway(): ?\Closure
    {
        if (!defined('_SATTLEME_TEST_GATEWAY_')) {
            return null;
        }
        if (self::$test === null) {
            $path = constant('_SATTLEME_TEST_GATEWAY_');
            $factory = is_string($path) && is_file($path) ? require $path : null;
            if (!$factory instanceof \Closure) {
                throw new \LogicException('_SATTLEME_TEST_GATEWAY_ names no file returning a Closure');
            }
            self::$test = $factory;
        }

        return self::$test;
    }
}
