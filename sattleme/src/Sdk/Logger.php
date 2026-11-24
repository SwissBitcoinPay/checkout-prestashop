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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PrestaShop's logs (Advanced parameters > Logs), for the module and the SDK's warnings. Never a
 * key: the SDK never puts one in a message, and the module never logs one. A message that names
 * an order is attached to it. A log that cannot be written is let go: it never fails a payment.
 */
final class Logger
{
    public const INFO = 1;
    public const WARNING = 2;
    public const ERROR = 3;

    private function __construct()
    {
    }

    public static function info(string $message, ?string $objectType = null, ?int $objectId = null): void
    {
        self::log(self::INFO, $message, $objectType, $objectId);
    }

    public static function warning(string $message, ?string $objectType = null, ?int $objectId = null): void
    {
        self::log(self::WARNING, $message, $objectType, $objectId);
    }

    public static function error(string $message, ?string $objectType = null, ?int $objectId = null): void
    {
        self::log(self::ERROR, $message, $objectType, $objectId);
    }

    /**
     * The SDK's logger: its warnings (a rate source that failed and was replaced, a check that
     * failed and is tried again…).
     *
     * @return \Closure(string, array<string, mixed>): void
     */
    public static function forSdk(): \Closure
    {
        return static function (string $message, array $context): void {
            self::warning('SDK: ' . $message);
        };
    }

    private static function log(int $severity, string $message, ?string $objectType, ?int $objectId): void
    {
        if (!class_exists(\PrestaShopLogger::class)) {
            return;
        }
        try {
            \PrestaShopLogger::addLog('sattle.me: ' . $message, $severity, null, $objectType, $objectId, false);
        } catch (\Throwable) {
            // The log is lost; the payment goes on.
        }
    }
}
