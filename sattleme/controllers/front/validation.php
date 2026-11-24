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

use Sattleme\PrestaShop\Payment\CreationRefusedException;
use Sattleme\PrestaShop\Payment\LockTimeoutException;
use Sattleme\PrestaShop\Payment\Orders;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Sdk\Errors;
use Sattleme\PrestaShop\Sdk\Logger;
use Sattleme\PrestaShop\Security\Tokens;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * "Place order" with the method (a POST of the checkout's form, its CSRF token with it): the cart's
 * invoice, then its order, pending; then the order's payment page. An invoice that cannot be made
 * keeps the customer at checkout with a message, and no order is made.
 */
class SattlemeValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        $module = $this->sattleme();
        $context = Platform::context();
        $checkout = Platform::link()->getPageLink('order', true);
        if (strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
            Tools::redirect($checkout);

            return;
        }
        $cart = $context->cart;
        $idCustomer = (int) $context->customer?->id;
        $idCart = Tools::getValue('sattleme_cart');
        $idCart = is_string($idCart) && preg_match('/^[1-9][0-9]{0,9}$/D', $idCart) === 1 ? (int) $idCart : 0;
        if ($idCustomer <= 0 || $idCart <= 0 || !Tokens::equals(Tokens::checkout($idCart), Tools::getValue('sattleme_token'))) {
            $this->back($checkout, Platform::translator()->trans('Your session expired. Please place your order again.', [], 'Modules.Sattleme.Shop'));

            return;
        }

        // An order made of this cart already (a second click, a request sent again): its page.
        // PrestaShop gave this request a new cart then, the old one having an order.
        $existing = Orders::forCart($idCart);
        if ($existing !== []) {
            $first = $existing[0];
            Tools::redirect((int) $first->id_customer === $idCustomer && $first->module === Sattleme::NAME ? $module->payUrl($first) : $checkout);

            return;
        }
        if (!$cart instanceof Cart || (int) $cart->id !== $idCart || (int) $cart->id_customer !== $idCustomer
            || (int) $cart->id_address_delivery === 0 || (int) $cart->id_address_invoice === 0 || !(bool) $module->active) {
            Tools::redirect(Platform::link()->getPageLink('order', true, null, ['step' => 1]));

            return;
        }

        // Still offered: the customer may have changed the address, the carrier or the cart
        // since the checkout showed the method.
        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer) || !$module->isAllowedHere() || $module->offeredAmount($cart) === null) {
            $this->back($checkout, Platform::translator()->trans('This payment method is not available for this order. Please choose another one.', [], 'Modules.Sattleme.Shop'));

            return;
        }

        try {
            $order = new Order($module->creator()->checkout($cart, $customer));
        } catch (CreationRefusedException $refused) {
            $this->back($checkout, Errors::forCustomer($refused->getErrorCode(), Platform::translator()));

            return;
        } catch (LockTimeoutException) {
            $this->back($checkout, Platform::translator()->trans('Your order is still being processed. Please wait a moment, then place it again.', [], 'Modules.Sattleme.Shop'));

            return;
        } catch (Throwable $error) {
            Logger::error('The checkout of cart ' . $idCart . ' failed: ' . get_class($error), 'Cart', $idCart);
            $this->back($checkout, Platform::translator()->trans('We could not place your order. Please try again in a moment.', [], 'Modules.Sattleme.Shop'));

            return;
        }

        Tools::redirect($module->payUrl($order));
    }

    /** Back to the checkout, with a message. */
    private function back(string $url, string $message): void
    {
        $this->errors[] = $message;
        $this->redirectWithNotifications($url);
    }

    private function sattleme(): Sattleme
    {
        if (!$this->module instanceof Sattleme) {
            throw new LogicException('The sattle.me module is not loaded');
        }

        return $this->module;
    }
}
