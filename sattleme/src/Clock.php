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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The time of the module's decisions, in Unix seconds: the server's clock, which the SDK needs
 * synchronized (NTP) anyway. Tests may set it.
 */
final class Clock
{
    /** @var int|null */
    private static $frozen;

    private function __construct()
    {
    }

    public static function now(): int
    {
        return self::$frozen ?? time();
    }

    /**
     * Sets the time the module sees (null: the server's clock again). For tests only.
     */
    public static function freeze(?int $time): void
    {
        self::$frozen = $time;
    }
}
