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
 * The module's settings, in PrestaShop's configuration (per shop where PrestaShop has several).
 * The integration key is kept encrypted (KeyVault) and read through it only.
 */
final class Config
{
    /** Whether customers are offered the method (the module may be installed and set up first). */
    public const ENABLED = 'SATTLEME_ENABLED';

    /** The integration key, encrypted by KeyVault: never read directly. */
    public const KEY = 'SATTLEME_KEY';

    /** How many minutes the customer has to pay an invoice. */
    public const MINUTES = 'SATTLEME_MINUTES';

    /** The method's title and description at checkout, per language. */
    public const TITLE = 'SATTLEME_TITLE';
    public const DESCRIPTION = 'SATTLEME_DESCRIPTION';

    /** The secret of the module's own tokens (HMAC): the forms' CSRF tokens. Global. */
    public const SECRET = 'SATTLEME_SECRET';

    /** The secret of the background checks' URL (the system's cron). Per shop. */
    public const CRON_TOKEN = 'SATTLEME_CRON_TOKEN';

    /** The name of the merchant's business in the sattle.me app, as the key's check read it. */
    public const BUSINESS = 'SATTLEME_BUSINESS';

    /** When the background checks last ran (Unix seconds), shown in the settings. Per shop. */
    public const CRON_LAST_RUN = 'SATTLEME_CRON_LAST_RUN';

    /** The module's order states: "awaiting payment", "being confirmed", "to check". Global. */
    public const OS_AWAITING = 'SATTLEME_OS_AWAITING';
    public const OS_CONFIRMING = 'SATTLEME_OS_CONFIRMING';
    public const OS_TO_CHECK = 'SATTLEME_OS_TO_CHECK';

    public const MIN_MINUTES = 1;
    public const MAX_MINUTES = 60;
    public const DEFAULT_MINUTES = 15;

    /** The configuration keys the uninstallation removes (the order states' stay: see Installer). */
    public const REMOVED_AT_UNINSTALL = [
        self::ENABLED,
        self::KEY,
        self::MINUTES,
        self::TITLE,
        self::DESCRIPTION,
        self::SECRET,
        self::CRON_TOKEN,
        self::CRON_LAST_RUN,
        self::BUSINESS,
    ];

    private function __construct()
    {
    }

    public static function isEnabled(?int $idShop = null): bool
    {
        return (bool) \Configuration::get(self::ENABLED, null, null, $idShop);
    }

    /** The time to pay, in minutes: the setting, within its bounds. */
    public static function minutes(?int $idShop = null): int
    {
        $minutes = \Configuration::get(self::MINUTES, null, null, $idShop);
        if (!is_numeric($minutes)) {
            return self::DEFAULT_MINUTES;
        }

        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, (int) $minutes));
    }

    public static function title(int $idLang, ?int $idShop = null): string
    {
        $title = \Configuration::get(self::TITLE, $idLang, null, $idShop);

        return is_string($title) ? trim($title) : '';
    }

    public static function description(int $idLang, ?int $idShop = null): string
    {
        $description = \Configuration::get(self::DESCRIPTION, $idLang, null, $idShop);

        return is_string($description) ? trim($description) : '';
    }

    /** The secret of the module's tokens: made at installation, made again if lost. */
    public static function secret(): string
    {
        $secret = \Configuration::getGlobalValue(self::SECRET);
        if (!is_string($secret) || preg_match('/^[0-9a-f]{64}$/D', $secret) !== 1) {
            $secret = bin2hex(random_bytes(32));
            \Configuration::updateGlobalValue(self::SECRET, $secret);
        }

        return $secret;
    }

    /** The secret of a shop's cron URL: made at installation, made again if lost. */
    public static function cronToken(?int $idShop = null): string
    {
        $token = \Configuration::get(self::CRON_TOKEN, null, null, $idShop);
        if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            \Configuration::updateValue(self::CRON_TOKEN, $token, false, null, $idShop);
        }

        return $token;
    }

    public static function lastCronRun(?int $idShop = null): ?int
    {
        $time = \Configuration::get(self::CRON_LAST_RUN, null, null, $idShop);

        return is_numeric($time) && (int) $time > 0 ? (int) $time : null;
    }

    public static function setLastCronRun(int $time, ?int $idShop = null): void
    {
        \Configuration::updateValue(self::CRON_LAST_RUN, (string) $time, false, null, $idShop);
    }

    public static function awaitingState(): int
    {
        return (int) \Configuration::getGlobalValue(self::OS_AWAITING);
    }

    public static function confirmingState(): int
    {
        return (int) \Configuration::getGlobalValue(self::OS_CONFIRMING);
    }

    public static function toCheckState(): int
    {
        return (int) \Configuration::getGlobalValue(self::OS_TO_CHECK);
    }
}
