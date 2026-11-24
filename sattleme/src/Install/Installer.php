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

namespace Sattleme\PrestaShop\Install;

use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\OrderStates;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Wordings;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's installation and uninstallation.
 *
 * Installed: its table of payments, its three order states, its settings' defaults (the method off
 * until the merchant enters a key), its secrets (of its forms' tokens and of the cron URL).
 *
 * Uninstalled: its settings and secrets go. Its table and its order states stay (the states
 * flagged deleted, as PrestaShop's back office deletes one; "Bitcoin payment to check" deleted for
 * good when no order ever was in it: OrderStates): they are the records of past and pending
 * payments (references, transactions, proofs of payment), which orders point to; a new
 * installation takes them back and goes on checking the payments being confirmed.
 */
final class Installer
{
    /** The hooks of a payment module today: the method, the confirmation, the order's pages. */
    public const HOOKS = [
        'paymentOptions',
        'displayPaymentReturn',
        'displayOrderDetail',
        'displayAdminOrderMainBottom',
        'actionExportGDPRData',
        'actionDeleteGDPRCustomer',
        'actionOrderStatusUpdate',
        'actionBeforeUpdateCancelProductFormHandler',
    ];

    private function __construct()
    {
    }

    public static function install(TranslatorInterface $translator, string $moduleName): bool
    {
        if (!Database::execute(AttemptRepository::createTableSql())) {
            return false;
        }
        if (!OrderStates::install($translator, $moduleName)) {
            return false;
        }
        if (\Configuration::getGlobalValue(Config::MINUTES) === false) {
            \Configuration::updateGlobalValue(Config::MINUTES, (string) Config::DEFAULT_MINUTES);
        }
        if (\Configuration::getGlobalValue(Config::ENABLED) === false) {
            \Configuration::updateGlobalValue(Config::ENABLED, '0');
        }
        $titles = [];
        $descriptions = [];
        foreach (Platform::languages() as $language) {
            $titles[$language['id']] = Wordings::trans($translator, 'Pay with bitcoin', 'Modules.Sattleme.Shop', $language['locale']);
            $descriptions[$language['id']] = Wordings::trans($translator, 'Pay with your Bitcoin wallet, in Lightning or on-chain. Once you have placed your order, you get a QR code to scan with your wallet.', 'Modules.Sattleme.Shop', $language['locale']);
        }
        if (\Configuration::getGlobalValue(Config::TITLE, (int) \Configuration::get('PS_LANG_DEFAULT')) === false) {
            \Configuration::updateGlobalValue(Config::TITLE, $titles);
            \Configuration::updateGlobalValue(Config::DESCRIPTION, $descriptions);
        }
        Config::secret();
        Config::cronToken();

        return true;
    }

    public static function uninstall(string $moduleName): bool
    {
        $ok = OrderStates::uninstall($moduleName);
        foreach (Config::REMOVED_AT_UNINSTALL as $key) {
            $ok = \Configuration::deleteByName($key) && $ok;
        }

        return $ok;
    }
}
