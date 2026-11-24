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

use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Wordings;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's three order states, created at installation:
 *
 * - "Awaiting bitcoin payment": the order is made, its invoice may be paid;
 * - "Bitcoin payment being confirmed": enough came on-chain in time, 3 confirmations to wait for
 *   (about 30 minutes). An order in it is not one PrestaShop or a module of automatic cancellation
 *   treats as unpaid;
 * - "Bitcoin payment to check": its invoice was paid, at a price other than what the order owed
 *   then (its total changed after the invoice was made), or after it was canceled or refunded
 *   (Settler). PrestaShop has no "on hold": the merchant settles it with the customer, then sets
 *   the order's state.
 *
 * None is paid, logable, shipped, nor sends an email (PrestaShop sends its order confirmation, then
 * its "Payment accepted" email once the payment is), so none changes stock: PrestaShop's
 * OrderHistory::changeIdOrderState gives stock back or takes it again only on a move into or out of
 * "Canceled" or "Payment error", and records a stock movement only when "shipped" changes. Uninstalled, PrestaShop leaves a module's order
 * states as they are (Module::uninstall knows none): the module flags its first two deleted, as
 * PrestaShop's back office deletes an order state (the orders that went through them keep their
 * history), and deletes "to check" only when no order is or was in it (else orders wait in it for
 * the merchant). A new installation takes them back.
 */
final class OrderStates
{
    public const AWAITING_COLOR = '#F7931A';
    public const CONFIRMING_COLOR = '#3498D8';
    public const TO_CHECK_COLOR = '#E74C3C';

    private function __construct()
    {
    }

    public static function install(TranslatorInterface $translator, string $moduleName): bool
    {
        $awaiting = self::ensure(
            Config::OS_AWAITING,
            static fn (string $locale): string => Wordings::trans($translator, 'Awaiting bitcoin payment', 'Modules.Sattleme.Admin', $locale),
            self::AWAITING_COLOR,
            $moduleName,
        );
        $confirming = self::ensure(
            Config::OS_CONFIRMING,
            static fn (string $locale): string => Wordings::trans($translator, 'Bitcoin payment being confirmed', 'Modules.Sattleme.Admin', $locale),
            self::CONFIRMING_COLOR,
            $moduleName,
        );
        $toCheck = self::ensure(
            Config::OS_TO_CHECK,
            static fn (string $locale): string => Wordings::trans($translator, 'Bitcoin payment to check', 'Modules.Sattleme.Admin', $locale),
            self::TO_CHECK_COLOR,
            $moduleName,
        );

        return $awaiting > 0 && $confirming > 0 && $toCheck > 0;
    }

    /**
     * The module's states at its uninstallation: "awaiting" and "being confirmed" flagged deleted
     * (hidden from the lists, kept for the orders' history); "to check" deleted for good when no
     * order is or was in it, else left for the orders that wait in it.
     */
    public static function uninstall(string $moduleName): bool
    {
        $ok = true;
        foreach ([Config::OS_AWAITING, Config::OS_CONFIRMING] as $key) {
            $state = new \OrderState((int) \Configuration::getGlobalValue($key));
            if (\Validate::isLoadedObject($state) && $state->module_name === $moduleName) {
                $state->deleted = true;
                $ok = $state->update() && $ok;
            }
        }
        $state = new \OrderState(Config::toCheckState());
        if (\Validate::isLoadedObject($state) && $state->module_name === $moduleName && !self::isUsed((int) $state->id)) {
            $ok = $state->delete() && \Configuration::deleteByName(Config::OS_TO_CHECK) && $ok;
        }

        return $ok;
    }

    /** Whether an order is in the state, or was. */
    public static function isUsed(int $idState): bool
    {
        return Database::int(Database::value('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` WHERE `current_state` = ' . $idState)) > 0
            || Database::int(Database::value('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_history` WHERE `id_order_state` = ' . $idState)) > 0;
    }

    /**
     * The id of the state $key names: its own of an earlier installation, else a new one.
     *
     * @param \Closure(string): string $name the state's name in a locale
     */
    private static function ensure(string $key, \Closure $name, string $color, string $moduleName): int
    {
        $state = new \OrderState((int) \Configuration::getGlobalValue($key));
        if (!\Validate::isLoadedObject($state) || $state->module_name !== $moduleName) {
            $state = new \OrderState();
        }
        $names = [];
        foreach (Platform::languages() as $language) {
            $names[$language['id']] = $name($language['locale']);
        }
        $state->name = $names;
        $state->module_name = $moduleName;
        $state->color = $color;
        $state->send_email = false;
        $state->logable = false;
        $state->invoice = false;
        $state->paid = false;
        $state->shipped = false;
        $state->delivery = false;
        $state->hidden = false;
        $state->pdf_invoice = false;
        $state->pdf_delivery = false;
        $state->unremovable = true;
        $state->deleted = false;
        $saved = (int) $state->id > 0 ? $state->update() : $state->add();
        if (!$saved || (int) $state->id <= 0) {
            return 0;
        }
        \Configuration::updateGlobalValue($key, (int) $state->id);

        return (int) $state->id;
    }
}
