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

use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\Checker;
use Sattleme\PrestaShop\Payment\CreationRefusedException;
use Sattleme\PrestaShop\Payment\Creator;
use Sattleme\PrestaShop\Payment\LockTimeoutException;
use Sattleme\PrestaShop\Payment\Orders;
use Sattleme\PrestaShop\Payment\Phase;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Sdk\Errors;
use Sattleme\PrestaShop\Sdk\Logger;
use Sattleme\PrestaShop\Security\OrderAccess;
use Sattleme\PrestaShop\Security\PrivatePage;
use Sattleme\PrestaShop\Security\Tokens;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The order's payment page, in the shop's theme and language: the QR code of the invoice, the
 * button that opens the customer's wallet, the Lightning invoice and the address to copy, the
 * amount, the time left; then "being confirmed", or the way to a new invoice once one ended unpaid.
 *
 * Opened by the order's secure key (as PrestaShop's order confirmation). Each visit has the invoice
 * checked by the server first (at most every few seconds), so the page works without JavaScript:
 * its "Check again" button reloads it. Its script (views/js/pay.js) only asks the status endpoint
 * for news and reloads the page when it changed. Never cached, never indexed. Once paid: the order's
 * confirmation page.
 */
class SattlemePayModuleFrontController extends ModuleFrontController
{
    /** @var Order|null */
    private $order;

    public function init(): void
    {
        parent::init();
        $this->order = OrderAccess::order(Sattleme::NAME, Platform::idShop(), Tools::getValue('id_order'), Tools::getValue('key'));
    }

    /** A new invoice, asked by the page's button (a POST with the page's CSRF token). */
    public function postProcess(): void
    {
        $order = $this->order;
        if ($order === null || Tools::isSubmit('sattleme_renew') !== true) {
            return;
        }
        $module = $this->sattleme();
        $page = $module->payUrl($order);
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        if (!is_string($method) || strtoupper($method) !== 'POST'
            || !Tokens::equals(Tokens::page((int) $order->id, (string) $order->secure_key), Tools::getValue('sattleme_token'))) {
            $this->errors[] = Platform::translator()->trans('This page expired. Please try again.', [], 'Modules.Sattleme.Shop');
            $this->redirectWithNotifications($page);

            return;
        }
        try {
            $module->creator()->renew((int) $order->id_cart);
        } catch (CreationRefusedException $refused) {
            $this->errors[] = Errors::forCustomer($refused->getErrorCode(), Platform::translator());
        } catch (LockTimeoutException) {
            $this->errors[] = Platform::translator()->trans('Your invoice is being created. Please wait a moment.', [], 'Modules.Sattleme.Shop');
        } catch (Throwable $error) {
            Logger::error('A new invoice for order ' . (int) $order->id . ' failed: ' . get_class($error), 'Order', (int) $order->id);
            $this->errors[] = Platform::translator()->trans('We could not create your bitcoin invoice right now. Please try again in a moment.', [], 'Modules.Sattleme.Shop');
        }
        $this->redirectWithNotifications($page);
    }

    public function initContent(): void
    {
        PrivatePage::sendHeaders();
        $order = $this->order;
        if ($order === null) {
            Tools::redirect('pagenotfound');

            return;
        }
        $module = $this->sattleme();
        $latest = (new AttemptRepository())->latestForCart((int) $order->id_cart);
        if ($latest !== null) {
            try {
                $module->checker()->requestCheck($latest, Checker::WEB_TIMEOUT);
            } catch (Throwable $error) {
                Logger::error('The check of payment ' . $latest->ref . ' from its page failed: ' . get_class($error), 'Order', (int) $order->id);
            }
        }
        [$phase, $attempt] = $module->phaseOf($order);
        if ($phase === Phase::PAID) {
            Tools::redirect($module->confirmationUrl($order));

            return;
        }

        parent::initContent();
        $mayRenew = Creator::mayRenew($attempt, Orders::forCart((int) $order->id_cart));
        Platform::smarty()->assign([
            'sattleme' => $module->payPage()->view((string) $order->reference, $attempt, $phase, $mayRenew, Clock::now(), true),
            'sattleme_urls' => [
                'page' => $module->payUrl($order),
                'status' => Platform::link()->getModuleLink(Sattleme::NAME, 'status', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key], true),
                'contact' => Platform::link()->getPageLink('contact', true),
            ],
            'sattleme_token' => Tokens::page((int) $order->id, (string) $order->secure_key),
        ]);
        $this->setTemplate('module:sattleme/views/templates/front/pay.tpl');
    }

    public function setMedia(): bool
    {
        parent::setMedia();
        $this->registerStylesheet('module-sattleme-pay', 'modules/' . Sattleme::NAME . '/views/css/pay.css', ['media' => 'all', 'priority' => 200]);
        $this->registerJavascript('module-sattleme-pay', 'modules/' . Sattleme::NAME . '/views/js/pay.js', ['position' => 'bottom', 'priority' => 200, 'attributes' => 'defer']);

        return true;
    }

    /** @return array<mixed> */
    public function getTemplateVarPage(): array
    {
        $page = parent::getTemplateVarPage();
        $page = is_array($page) ? $page : [];
        $title = Platform::translator()->trans('Bitcoin payment', [], 'Modules.Sattleme.Shop');
        $meta = is_array($page['meta'] ?? null) ? $page['meta'] : [];
        $meta['robots'] = 'noindex, nofollow';
        $meta['title'] = $title;
        $page['meta'] = $meta;
        $page['title'] = $title;

        return $page;
    }

    /** @return array<mixed> */
    public function getBreadcrumbLinks(): array
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb = is_array($breadcrumb) ? $breadcrumb : [];
        if ($this->order !== null) {
            $links = is_array($breadcrumb['links'] ?? null) ? $breadcrumb['links'] : [];
            $links[] = [
                'title' => Platform::translator()->trans('Bitcoin payment', [], 'Modules.Sattleme.Shop'),
                'url' => $this->sattleme()->payUrl($this->order),
            ];
            $breadcrumb['links'] = $links;
        }

        return $breadcrumb;
    }

    private function sattleme(): Sattleme
    {
        if (!$this->module instanceof Sattleme) {
            throw new LogicException('The sattle.me module is not loaded');
        }

        return $this->module;
    }
}
