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

if (!defined('_PS_VERSION_')) {
    exit;
}

// The module's classes and its libraries (the sattle.me SDK, the QR code encoder), prefixed.
require_once __DIR__ . '/vendor/autoload.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Sattleme\PrestaShop\Admin\ConfigPage;
use Sattleme\PrestaShop\Admin\OrderPanel;
use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Cron\Runner;
use Sattleme\PrestaShop\Install\Installer;
use Sattleme\PrestaShop\KeyVault;
use Sattleme\PrestaShop\Payment\Amount;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\Availability;
use Sattleme\PrestaShop\Payment\Cancellation;
use Sattleme\PrestaShop\Payment\CancellationRefusedException;
use Sattleme\PrestaShop\Payment\Checker;
use Sattleme\PrestaShop\Payment\Creator;
use Sattleme\PrestaShop\Payment\Lock;
use Sattleme\PrestaShop\Payment\LockTimeoutException;
use Sattleme\PrestaShop\Payment\Orders;
use Sattleme\PrestaShop\Payment\Phase;
use Sattleme\PrestaShop\Payment\Settler;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Privacy\Gdpr;
use Sattleme\PrestaShop\Sdk\Environment;
use Sattleme\PrestaShop\Sdk\Logger;
use Sattleme\PrestaShop\Security\Tokens;
use Sattleme\PrestaShop\View\PayPage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * sattle.me: customers pay their orders in bitcoin, in Lightning or on-chain, straight to the
 * merchant's sattle.me wallet, on a page of the shop. Built on the sattle.me checkout SDK for PHP,
 * embedded and prefixed: it implements the protocol; this module is PrestaShop's side of it.
 */
class Sattleme extends PaymentModule
{
    public const NAME = 'sattleme';

    public const VERSION = '1.0.0';

    /** The payment method's name on PrestaShop's orders, invoices and payments. */
    public const PAYMENT_NAME = 'Bitcoin (sattle.me)';

    public function __construct()
    {
        $this->name = self::NAME;
        // PrestaShop Addons' module key goes here once the product exists: README.md, "Releases".
        $this->tab = 'payments_gateways';
        $this->version = self::VERSION;
        $this->author = 'sattle.me';
        $this->need_instance = 1;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;
        $this->controllers = ['validation', 'pay', 'status', 'cron'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = 'sattle.me';
        $this->description = $this->translator()->trans('Accept bitcoin payments, in Lightning or on-chain, paid straight to your sattle.me wallet.', [], 'Modules.Sattleme.Admin');
        $this->confirmUninstall = $this->translator()->trans('The records of the bitcoin payments and the module\'s order states stay, for the orders that have them. Uninstall?', [], 'Modules.Sattleme.Admin');
        if ((int) $this->id > 0 && (bool) $this->active) {
            $missing = Environment::missing();
            if ($missing !== null) {
                $this->warning = $this->translator()->trans('This server cannot run the module: %missing%', ['%missing%' => $missing], 'Modules.Sattleme.Admin');
            } elseif (!KeyVault::isSet()) {
                $this->warning = $this->translator()->trans('Enter the integration key to accept bitcoin payments.', [], 'Modules.Sattleme.Admin');
            }
        }
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        $missing = Environment::missing();
        if ($missing !== null) {
            $this->_errors[] = $this->translator()->trans('This server cannot run the module: %missing%', ['%missing%' => $missing], 'Modules.Sattleme.Admin');

            return false;
        }

        if (!(bool) parent::install()) {
            return false;
        }
        if (Installer::install($this->translator(), self::NAME) && (bool) $this->registerHook(Installer::HOOKS)) {
            return true;
        }
        // Half installed: PrestaShop's part is undone, the module's records are kept (see Installer).
        parent::uninstall();

        return false;
    }

    public function uninstall(): bool
    {
        return Installer::uninstall(self::NAME) && (bool) parent::uninstall();
    }

    public function getContent(): string
    {
        return (new ConfigPage($this, self::NAME, $this->translator()))->render();
    }

    /**
     * The method at checkout, where PrestaShop's restrictions of payment methods offer it (it calls
     * this hook only then) and the module can take the cart's total (offeredAmount).
     *
     * @param array<string, mixed> $params
     *
     * @return list<PaymentOption>
     */
    public function hookPaymentOptions(array $params): array
    {
        $cart = $params['cart'] ?? Platform::context()->cart;
        if (!$cart instanceof Cart || $this->offeredAmount($cart) === null) {
            return [];
        }
        $idLang = Platform::idLang();
        $title = Config::title($idLang, (int) $cart->id_shop);
        $lines = preg_split('/\R/u', Config::description($idLang, (int) $cart->id_shop));
        Platform::smarty()->assign([
            'sattleme_description' => array_values(array_filter(array_map('trim', $lines === false ? [] : $lines), static fn (string $line): bool => $line !== '')),
        ]);
        $logo = Media::getMediaPath(_PS_MODULE_DIR_ . self::NAME . '/views/img/sattleme.svg');
        $option = new PaymentOption();
        $option->setModuleName(self::NAME)
            ->setCallToActionText($title !== '' ? $title : $this->translator()->trans('Pay with bitcoin', [], 'Modules.Sattleme.Shop'))
            ->setAction(Platform::link()->getModuleLink(self::NAME, 'validation', [], true))
            ->setInputs([
                'sattleme_cart' => [
                    'name' => 'sattleme_cart',
                    'type' => 'hidden',
                    'value' => (string) (int) $cart->id,
                ],
                'sattleme_token' => [
                    'name' => 'sattleme_token',
                    'type' => 'hidden',
                    'value' => Tokens::checkout((int) $cart->id),
                ],
            ])
            ->setLogo(is_string($logo) ? $logo : '')
            ->setAdditionalInformation(self::text($this->fetch('module:sattleme/views/templates/hook/payment_option.tpl')));

        return [$option];
    }

    /**
     * The amount the method is offered for, or null when it is not offered for this cart: the
     * module set up, the cart's total above 0, in a currency the SDK prices, with at most its
     * decimals. (PrestaShop's restrictions of the method: isAllowedHere.)
     */
    public function offeredAmount(Cart $cart): ?string
    {
        if (!(bool) $this->active || !Config::isEnabled((int) $cart->id_shop) || Environment::missing() !== null || KeyVault::read((int) $cart->id_shop) === null) {
            return null;
        }
        $currency = new Currency((int) $cart->id_currency);
        if (!Validate::isLoadedObject($currency)) {
            return null;
        }
        $amount = Amount::fromTotal((float) $cart->getOrderTotal(true, Cart::BOTH), (int) $currency->precision);
        if (Amount::compare($amount, '0') <= 0 || !Availability::accepts($amount, (string) $currency->iso_code)) {
            return null;
        }

        return $amount;
    }

    /**
     * Whether PrestaShop's restrictions of payment methods (Payment > Preferences: currencies,
     * countries, customer groups, carriers) offer the method here: whether the checkout's
     * paymentOptions hook would call it, as PrestaShop decides it.
     */
    public function isAllowedHere(): bool
    {
        $registrations = Hook::getHookModuleExecList('paymentOptions');
        foreach (is_array($registrations) ? $registrations : [] as $registration) {
            if (is_array($registration) && is_numeric($registration['id_module'] ?? null) && (int) $registration['id_module'] === (int) $this->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * After the order is placed: where its payment is, and the way to its payment page.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayPaymentReturn(array $params): string
    {
        return $this->renderOrderHook($params['order'] ?? null, 'payment_return.tpl');
    }

    /**
     * The order's details, in the customer's account (and the guests' order tracking).
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayOrderDetail(array $params): string
    {
        return $this->renderOrderHook($params['order'] ?? null, 'order_detail.tpl');
    }

    /**
     * The order's page of the back office: each payment attempt, checked again first (at most every
     * few seconds) when one is pending.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayAdminOrderMainBottom(array $params): string
    {
        $idOrder = $params['id_order'] ?? null;
        $order = new Order(is_numeric($idOrder) ? (int) $idOrder : 0);
        if (!Validate::isLoadedObject($order) || $order->module !== self::NAME) {
            return '';
        }
        $repository = new AttemptRepository();
        $latest = $repository->latestForCart((int) $order->id_cart);
        if ($latest !== null) {
            try {
                $this->checker()->requestCheck($latest, Checker::ADMIN_TIMEOUT);
            } catch (Throwable $e) {
                Logger::error('The check of payment ' . $latest->ref . ' from the back office failed: ' . get_class($e), 'Order', (int) $order->id);
            }
        }
        Platform::smarty()->assign([
            'sattleme_attempts' => OrderPanel::rows($repository->allForCart((int) $order->id_cart), $this->translator()),
            'sattleme_logo' => $this->getPathUri() . 'views/img/sattleme.svg',
        ]);

        return self::text($this->display(__FILE__, 'views/templates/hook/admin_order.tpl'));
    }

    /**
     * The customer's personal data, for PrestaShop's GDPR module: their bitcoin payments.
     *
     * @param array<string, mixed> $customer
     */
    public function hookActionExportGDPRData(array $customer): string
    {
        return (string) json_encode((new Gdpr(new AttemptRepository()))->export($customer));
    }

    /**
     * Erases what leads to the customer's coins, for PrestaShop's GDPR module.
     *
     * @param array<string, mixed> $customer
     */
    public function hookActionDeleteGDPRCustomer(array $customer): string
    {
        (new Gdpr(new AttemptRepository()))->erase($customer);

        return (string) json_encode(true);
    }

    /**
     * Refuses to cancel an order whose bitcoin payment may still come or came (Cancellation): its
     * state "Canceled" or "Payment error", by the back office (the order's page, the list, a bulk
     * change), the webservice or another module. PrestaShop runs this hook before it changes
     * anything of the order, and gives no way to leave these states out of the back office's
     * choice for one order: the refusal is here, on the server.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionOrderStatusUpdate(array $params): void
    {
        $new = $params['newOrderStatus'] ?? null;
        $cancels = [(int) Configuration::get('PS_OS_CANCELED'), (int) Configuration::get('PS_OS_ERROR')];
        if (!$new instanceof OrderState || !in_array((int) $new->id, $cancels, true)) {
            return;
        }
        $order = $this->ownOrder($params['id_order'] ?? null);
        if ($order !== null) {
            $this->refuseCancellation($order, false);
        }
    }

    /**
     * Refuses "Cancel products" on the same orders, before PrestaShop changes anything: its form
     * handler (FormHandler::handleFormUpdate) runs this hook before CancellationFormDataHandler
     * dispatches CancelOrderProductCommand, whose handler removes the products, gives their stock
     * back and cancels the order once none is left (PrestaShop 9.0 to 9.2; nothing else dispatches
     * that command). The refunds' forms (partial, standard, return) are the same form, so the same
     * hook: they need an order paid or with a payment (or delivered), which "Cancel products"
     * refuses; such an order is left to them, the merchant records its refunds there.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionBeforeUpdateCancelProductFormHandler(array $params): void
    {
        $order = $this->ownOrder($params['id'] ?? null);
        if ($order === null || (bool) $order->hasBeenPaid() || $order->hasPayments()) {
            return;
        }
        $this->refuseCancellation($order, true);
    }

    /** The order of this id, when it is one of this payment method's; else null, nothing looked up. */
    private function ownOrder(mixed $id): ?Order
    {
        $order = new Order(is_numeric($id) ? (int) $id : 0);

        return Validate::isLoadedObject($order) && $order->module === self::NAME ? $order : null;
    }

    /**
     * Throws the refusal of the order's cancellation ($products: of its products), when its payment
     * may still come or came; nothing when the merchant may cancel it.
     */
    private function refuseCancellation(Order $order, bool $products): void
    {
        // Decided under the cart's lock, which a new invoice's making holds from its check that the
        // order may have one to the invoice stored (Creator::renew): a cancellation meanwhile waits,
        // then finds the invoice. A cancellation of the order let through marks its cart, under the
        // same lock, as being canceled for Cancellation::WRITE_SECONDS: PrestaShop writes the
        // order's state after every module reacted (OrderHistory::changeIdOrderState), and a new
        // invoice asked meanwhile finds the mark (Creator::renew), then the order canceled. No lock
        // is kept across other modules' code: one that aborts the change leaves the mark only.
        $idCart = (int) $order->id_cart;
        try {
            $refusal = Lock::with('cart', $idCart, Creator::LOCK_WAIT, static function () use ($idCart, $products): ?string {
                $repository = new AttemptRepository();
                $refusal = Cancellation::refusal($repository->allForCart($idCart));
                if ($refusal === null && !$products) {
                    $repository->markCanceling($idCart, Cancellation::WRITE_SECONDS);
                }

                return $refusal;
            });
        } catch (LockTimeoutException) {
            $refusal = Cancellation::BEING_MADE;
        }
        if ($refusal === null) {
            return;
        }
        $reference = ['%reference%' => (string) $order->reference];
        if ($refusal === Cancellation::BEING_MADE) {
            Logger::warning('Order ' . (int) $order->id . ' (' . $order->reference . ') was not canceled: a bitcoin invoice of it is being made', 'Order', (int) $order->id);
            if ($products) {
                throw new CancellationRefusedException($this->translator()->trans('sattle.me: the products of order %reference% were not canceled, nor removed: a bitcoin invoice of it is being made right now. Try again in a moment.', $reference, 'Modules.Sattleme.Admin'));
            }

            throw new CancellationRefusedException($this->translator()->trans('sattle.me: order %reference% was not canceled: a bitcoin invoice of it is being made right now. Try again in a moment. If you changed several orders at once, the change stopped at this order: the ones after it were left as they were.', $reference, 'Modules.Sattleme.Admin'));
        }
        Logger::warning(($products ? 'The products of order ' : 'Order ') . (int) $order->id . ' (' . $order->reference . ') ' . ($products ? 'were' : 'was') . ' not canceled: ' . ($refusal === Cancellation::CAME ? 'bitcoin came for it' : 'its bitcoin payment may still come'), 'Order', (int) $order->id);
        if ($products) {
            if ($refusal === Cancellation::CAME) {
                throw new CancellationRefusedException($this->translator()->trans('sattle.me: the products of order %reference% were not canceled, nor removed: bitcoin came for it (paid, or an underpayment that reached your wallet). Settle it with the customer: refund them from your wallet, then record the refund (the state "Refunded").', $reference, 'Modules.Sattleme.Admin'));
            }

            throw new CancellationRefusedException($this->translator()->trans('sattle.me: the products of order %reference% were not canceled, nor removed: its bitcoin invoice can still be paid, at its amount, its payment is being confirmed, or its final status is not known yet. They can be canceled once its invoice has ended unpaid.', $reference, 'Modules.Sattleme.Admin'));
        }
        // PrestaShop's change of several orders' state at once (BulkChangeOrderStatusHandler) stops
        // at the first refusal: the message says which order, and what became of the others.
        if ($refusal === Cancellation::CAME) {
            throw new CancellationRefusedException($this->translator()->trans('sattle.me: order %reference% was not canceled: bitcoin came for it (paid, or an underpayment that reached your wallet). Settle it with the customer: refund them from your wallet, then record the refund (a credit slip, the state "Refunded"). If you changed several orders at once, the change stopped at this order: the ones after it were left as they were.', $reference, 'Modules.Sattleme.Admin'));
        }

        throw new CancellationRefusedException($this->translator()->trans('sattle.me: order %reference% was not canceled: its bitcoin invoice can still be paid, its payment is being confirmed, or its final status is not known yet. It can be canceled once its invoice has ended unpaid. If you changed several orders at once, the change stopped at this order: the ones after it were left as they were.', $reference, 'Modules.Sattleme.Admin'));
    }

    public function settler(): Settler
    {
        return new Settler(new AttemptRepository(), self::PAYMENT_NAME, $this->translator());
    }

    public function checker(): Checker
    {
        return new Checker(new AttemptRepository(), $this->settler());
    }

    public function creator(): Creator
    {
        return new Creator($this, new AttemptRepository(), $this->settler(), self::PAYMENT_NAME);
    }

    public function runner(): Runner
    {
        return new Runner(new AttemptRepository(), $this->checker(), $this->translator());
    }

    /** What the customer's pages show, in the customer's language. */
    public function payPage(): PayPage
    {
        return new PayPage($this->translator(), [$this, 'formatSats'], [$this, 'formatPrice']);
    }

    /** The order's payment page: the order's secure key opens it, as its confirmation page. */
    public function payUrl(Order $order): string
    {
        return Platform::link()->getModuleLink(self::NAME, 'pay', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key], true, null, (int) $order->id_shop);
    }

    /** The order's confirmation page (PrestaShop's), where the paid page sends the customer. */
    public function confirmationUrl(Order $order): string
    {
        return Platform::link()->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $order->id_cart,
            'id_module' => (int) $this->id,
            'id_order' => (int) $order->id,
            'key' => (string) $order->secure_key,
        ], false, (int) $order->id_shop);
    }

    /**
     * The phase of an order's last attempt (Phase), and the attempt.
     *
     * @return array{0: string|null, 1: Attempt|null}
     */
    public function phaseOf(Order $order): array
    {
        $attempt = (new AttemptRepository())->latestForCart((int) $order->id_cart);
        if ($attempt === null) {
            return [null, null];
        }
        $paid = false;
        $closed = false;
        foreach (Orders::forCart((int) $order->id_cart) as $each) {
            $paid = $paid || Orders::isPaid($each);
            $closed = $closed || Orders::isClosed($each);
        }

        return [Phase::of($attempt, $paid, $closed, Clock::now()), $attempt];
    }

    /** Sats as the customer's language groups them: 49 000, 49,000, 49’000. */
    public function formatSats(string $sats): string
    {
        try {
            return Tools::getContextLocale(Platform::context())->formatNumber($sats);
        } catch (Throwable) {
            return $sats;
        }
    }

    /** An amount in a currency as the customer's language writes it: CHF 24.50, 24,50 €. */
    public function formatPrice(string $amount, string $currency): string
    {
        try {
            return Tools::getContextLocale(Platform::context())->formatPrice($amount, $currency);
        } catch (Throwable) {
            return $amount . ' ' . $currency;
        }
    }

    /** PrestaShop's translator: the module's wordings in the request's language. */
    private function translator(): TranslatorInterface
    {
        return Platform::translator();
    }

    /** The order's confirmation and its details: where its payment is, the way to its page. */
    private function renderOrderHook(mixed $order, string $template): string
    {
        if (!$order instanceof Order || $order->module !== self::NAME) {
            return '';
        }
        [$phase, $attempt] = $this->phaseOf($order);
        Platform::smarty()->assign([
            'sattleme' => $this->payPage()->view((string) $order->reference, $attempt, $phase, Creator::mayRenew($attempt, Orders::forCart((int) $order->id_cart)), Clock::now(), false),
            'sattleme_pay_url' => $this->payUrl($order),
        ]);

        return self::text($this->fetch('module:sattleme/views/templates/hook/' . $template));
    }

    /** What PrestaShop renders: a string, or nothing. */
    private static function text(mixed $html): string
    {
        return is_string($html) ? $html : '';
    }
}
