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

use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Cron\Runner;
use Sattleme\PrestaShop\Payment\Checker;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Security\PrivatePage;
use Sattleme\PrestaShop\Security\Tokens;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The background checks of a shop's pending invoices (the SDK has no webhooks): the system's cron
 * calls this URL every minute, with the shop's secret token (compared in constant time). One run
 * at a time per shop, bounded in time and in number of invoices (Runner). It runs while the shop
 * is in maintenance too: payments go on reaching the merchant's wallet then.
 */
class SattlemeCronModuleFrontController extends ModuleFrontController
{
    public function init(): void
    {
        $this->ajax = true;
        parent::init();
    }

    public function initContent(): void
    {
        // Plain text only: none of the theme's variables.
    }

    public function displayAjax(): void
    {
        PrivatePage::sendHeaders();
        header('Content-Type: text/plain; charset=utf-8');
        $idShop = Platform::idShop();
        if (!Tokens::equals(Config::cronToken($idShop), Tools::getValue('token'))) {
            http_response_code(403);
            $this->ajaxRender("Forbidden\n");

            return;
        }
        // A run ends what it started even if the cron stops waiting for it.
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            set_time_limit(Runner::BUDGET + Checker::CRON_TIMEOUT + 60);
        }
        $result = $this->sattleme()->runner()->run($idShop);
        $this->ajaxRender($result['busy']
            ? "Busy: another run of the checks is going on\n"
            : sprintf("Checked %d of %d invoices due (%d skipped), %d given up\n", $result['checked'], $result['due'], $result['skipped'], $result['abandoned']));
    }

    /** The checks go on while the shop is in maintenance. */
    protected function displayMaintenancePage(): void
    {
    }

    /** The checks go on whatever country the cron's address seems to be in. */
    protected function displayRestrictedCountryPage(): void
    {
    }

    private function sattleme(): Sattleme
    {
        if (!$this->module instanceof Sattleme) {
            throw new LogicException('The sattle.me module is not loaded');
        }

        return $this->module;
    }
}
