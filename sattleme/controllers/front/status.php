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

use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\Checker;
use Sattleme\PrestaShop\Payment\Phase;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Sdk\Logger;
use Sattleme\PrestaShop\Security\OrderAccess;
use Sattleme\PrestaShop\Security\PrivatePage;
use Sattleme\PrestaShop\View\PaymentView;
use Sattleme\PrestaShop\View\PayPage;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The status of an order's payment, for its page's script (JSON): the phase, a version of what the
 * page shows (the page reloads when it changes), how soon to ask again, and where to go once paid.
 * Authorized as the page is, by the order's secure key. It has the invoice checked by the server
 * first: at most every few seconds per invoice, however many pages ask, never two checks at once.
 */
class SattlemeStatusModuleFrontController extends ModuleFrontController
{
    public function init(): void
    {
        $this->ajax = true;
        parent::init();
    }

    public function initContent(): void
    {
        // JSON only: none of the theme's variables.
    }

    public function displayAjax(): void
    {
        PrivatePage::sendHeaders();
        header('Content-Type: application/json; charset=utf-8');
        $order = OrderAccess::order(Sattleme::NAME, Platform::idShop(), Tools::getValue('id_order'), Tools::getValue('key'));
        if ($order === null) {
            http_response_code(404);
            $this->ajaxRender((string) json_encode(['error' => 'not_found']));

            return;
        }
        $module = $this->sattleme();
        $latest = (new AttemptRepository())->latestForCart((int) $order->id_cart);
        if ($latest !== null) {
            try {
                $module->checker()->requestCheck($latest, Checker::WEB_TIMEOUT);
            } catch (Throwable $error) {
                Logger::error('The check of payment ' . $latest->ref . ' for its page failed: ' . get_class($error), 'Order', (int) $order->id);
            }
        }
        [$phase, $attempt] = $module->phaseOf($order);
        $phase = $attempt === null || $phase === null ? PayPage::NONE : $phase;
        $body = [
            'phase' => $phase,
            'version' => $attempt === null ? PayPage::NONE : PaymentView::version($attempt, $phase),
            'poll' => Phase::pollInterval($phase),
        ];
        if ($phase === Phase::PAID) {
            $body['redirect'] = $module->confirmationUrl($order);
        }
        $this->ajaxRender((string) json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    private function sattleme(): Sattleme
    {
        if (!$this->module instanceof Sattleme) {
            throw new LogicException('The sattle.me module is not loaded');
        }

        return $this->module;
    }
}
