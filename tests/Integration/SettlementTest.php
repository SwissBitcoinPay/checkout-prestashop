<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\BulkChangeOrderStatusCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\CancelOrderProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\ChangeOrderCurrencyCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\IssuePartialRefundCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\UpdateOrderStatusCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Payment\Command\AddPaymentCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Product\Command\AddProductToOrderCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Product\Command\UpdateProductInOrderCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\VoucherRefundType;
use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\Payment\Amount;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\CancellationRefusedException;
use Sattleme\PrestaShop\Payment\Creator;
use Sattleme\PrestaShop\Payment\Orders;
use Sattleme\PrestaShop\Payment\Phase;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Tests\Support\ShopTestCase;

/**
 * A paid invoice compared with what the orders of its cart still owe then (the CEO's decision):
 * the same amount in the same currency pays them; anything else (their total raised or lowered in
 * the back office, their currency changed, another payment or a credit slip recorded meanwhile, an
 * order canceled) never does: the payment is recorded in the invoice's currency, the orders go to
 * "Bitcoin payment to check" with a note for the merchant, and the customer is told the payment
 * was received and the shop will contact them. The orders are changed as the back office changes
 * them: through PrestaShop's command bus, with the commands of its order page.
 */
final class SettlementTest extends ShopTestCase
{
    /** A product of PrestaShop's demo data without combinations, beside PRODUCT: "Mug Today is a good day". */
    private const OTHER_PRODUCT = 7;

    /** PrestaShop's demo carrier without a price ("Click and collect"). */
    private const OTHER_CARRIER = 1;

    /** @var list<int> the products whose carriers a test restricted */
    private array $restricted = [];

    protected function tearDown(): void
    {
        foreach ($this->restricted as $product) {
            // Every carrier again, as PrestaShop's demo data has them.
            (new \Product($product))->setCarriers([]);
        }
        \Cache::clean('Carrier::getAvailableCarrierList_*');
        Platform::context()->employee = null;
        parent::tearDown();
    }

    /** PrestaShop's command bus, as the back office's order page uses it, for its first employee. */
    private function handle(object $command): void
    {
        global $kernel;
        self::assertInstanceOf(\AdminKernel::class, $kernel);
        Platform::context()->employee = new \Employee(1);
        $bus = $kernel->getContainer()->get('prestashop.core.command_bus');
        self::assertInstanceOf(CommandBusInterface::class, $bus);
        $bus->handle($command);
    }

    private function pay(\Order $order, string $method = 'lightning'): Attempt
    {
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'paid', 'method' => $method, 'received' => (string) $attempt->sats()]);
        self::assertTrue($this->module->checker()->check($attempt->id, 0, 5));

        return $this->attempt((int) $order->id_cart);
    }

    /**
     * "Cancel products" of every product of the order, in the order PrestaShop's form handler runs
     * it (FormHandler::handleFormUpdate): the hook actionBeforeUpdateCancelProductFormHandler, then,
     * nothing refused, CancelOrderProductCommand. (The form handler itself needs a shop context a
     * request builds; CustomerTest posts the order page's real form.)
     */
    private function cancelProducts(\Order $order): void
    {
        $data = [];
        \Hook::exec('actionBeforeUpdateCancelProductFormHandler', ['form_data' => &$data, 'id' => (int) $order->id]);
        $quantities = [];
        foreach (self::details($order) as $detail) {
            $quantities[Database::int($detail['id_order_detail'])] = Database::int($detail['product_quantity']);
        }
        $this->handle(new CancelOrderProductCommand($quantities, (int) $order->id));
    }

    /** @return list<int> the order's states, oldest first */
    private static function history(\Order $order): array
    {
        return array_map(
            static fn (array $row): int => Database::int($row['id_order_state'] ?? null),
            Database::rows('SELECT `id_order_state` FROM `' . _DB_PREFIX_ . 'order_history` WHERE `id_order` = ' . (int) $order->id . ' ORDER BY `id_order_history`'),
        );
    }

    /** @return list<array<string, mixed>> */
    private static function details(\Order $order): array
    {
        return Database::rows('SELECT * FROM `' . _DB_PREFIX_ . 'order_detail` WHERE `id_order` = ' . (int) $order->id . ' ORDER BY `id_order_detail`');
    }

    private static function total(\Order $order): string
    {
        return number_format((float) self::reload($order)->total_paid_tax_incl, 2, '.', '');
    }

    /**
     * What the customer is told: received, the shop will contact them; never paid, nothing to pay.
     */
    private function assertTheCustomerIsToldReceivedNotPaid(\Order $order): void
    {
        [$phase, $attempt] = $this->module->phaseOf(self::reload($order));
        self::assertSame(Phase::REVIEW, $phase);
        $view = $this->module->payPage()->view((string) $order->reference, $attempt, $phase, Creator::mayRenew($attempt, Orders::forCart((int) $order->id_cart)), Clock::now(), true);
        self::assertFalse($view['payable']);
        self::assertFalse($view['may_renew']);
        self::assertTrue($view['contact']);
        $html = $this->module->hookDisplayOrderDetail(['order' => self::reload($order)]);
        self::assertStringContainsString('Your bitcoin payment was received. We will contact you about your order.', $html);
        self::assertStringNotContainsString('Thank you', $html);
        self::assertStringNotContainsString('Pay now', $html);
    }

    /** The payment recorded once, at the invoice's price, in the invoice's currency. */
    private function assertThePaymentIsTheInvoicesPrice(\Order $order, Attempt $attempt): void
    {
        $ours = array_values(array_filter(self::payments($order), static fn (array $payment): bool => ($payment['transaction_id'] ?? null) === $attempt->ref));
        self::assertCount(1, $ours);
        self::assertSame(0, Amount::compare($attempt->amount, Database::string($ours[0]['amount'])));
        self::assertSame($attempt->currency, \Currency::getIsoCodeById(Database::int($ours[0]['id_currency'])));
    }

    public function testATotalRaisedInTheBackOfficeIsToCheck(): void
    {
        $order = $this->checkout();
        $invoice = $this->attempt((int) $order->id_cart);
        \StockAvailable::setQuantity(self::OTHER_PRODUCT, 0, 100000);
        $this->handle(AddProductToOrderCommand::withNewInvoice((int) $order->id, self::OTHER_PRODUCT, 0, '11.90', '11.90', 1));
        $total = self::total($order);
        self::assertSame(1, Amount::compare($total, $invoice->amount), 'the order\'s total grew');

        $paid = $this->pay($order);
        self::assertSame(Attempt::PAID, $paid->state);
        self::assertSame(Config::toCheckState(), self::state($order));
        self::assertSame([Config::awaitingState(), Config::toCheckState()], self::history($order));
        $this->assertThePaymentIsTheInvoicesPrice($order, $invoice);
        $note = self::note($order);
        // What came, how, and what the invoice asked: a Lightning payment is its invoice's sats.
        self::assertStringContainsString('Bitcoin payment ' . $invoice->ref . ' came: ' . $invoice->sats() . ' sats came in Lightning, for an invoice of ' . $invoice->sats() . ' sats (' . $invoice->amount . ' CHF), and reached your wallet.', $note);
        self::assertStringContainsString('The order now owes ' . $total . ' CHF', $note);
        $this->assertTheCustomerIsToldReceivedNotPaid($order);

        // Checked again (the page, the background): nothing more.
        self::assertFalse($this->module->checker()->check($paid->id, 0, 5));
        self::assertSame($note, self::note($order));
    }

    public function testATotalLoweredInTheBackOfficeIsToCheckToo(): void
    {
        $order = $this->checkout($this->cart(null, [self::PRODUCT => 2]));
        $invoice = $this->attempt((int) $order->id_cart);
        $line = self::details($order)[0];
        $this->handle(new UpdateProductInOrderCommand((int) $order->id, Database::int($line['id_order_detail']), '11.90', '11.90', 1));
        $total = self::total($order);
        self::assertSame(-1, Amount::compare($total, $invoice->amount), 'the order\'s total fell');

        $this->pay($order);
        self::assertSame(Config::toCheckState(), self::state($order), 'never "Payment accepted" for an order whose total changed');
        self::assertNotContains(self::os('PS_OS_PAYMENT'), self::history($order));
        $this->assertThePaymentIsTheInvoicesPrice($order, $invoice);
        self::assertStringContainsString('The order now owes ' . $total . ' CHF', self::note($order));
        $this->assertTheCustomerIsToldReceivedNotPaid($order);
    }

    public function testACurrencyChangedIsToCheckAndThePaymentIsInTheInvoicesCurrency(): void
    {
        $eur = (int) \Currency::getIdByIsoCode('EUR', 0, true, true);
        if ($eur <= 0) {
            $currency = new \Currency();
            $currency->iso_code = 'EUR';
            $currency->numeric_iso_code = '978';
            $currency->precision = 2;
            $currency->conversion_rate = 0.5;
            $currency->name = 'Euro';
            $currency->active = true;
            // ObjectModel::add() and update() give 1, not true, for a model with shop or
            // translated fields in PrestaShop 9.0 (their `$result &=`): its test shop has no euro.
            self::assertNotFalse($currency->add());
            $eur = (int) $currency->id;
            self::assertGreaterThan(0, $eur);
        }
        $currency = new \Currency($eur);
        $currency->active = true;
        $currency->deleted = false;
        self::assertNotFalse($currency->update());
        try {
            $order = $this->checkout();
            $invoice = $this->attempt((int) $order->id_cart);
            // An order awaiting payment is not valid (its state is not logable): the back office
            // lets the merchant change its currency.
            $this->handle(new ChangeOrderCurrencyCommand((int) $order->id, $eur));
            self::assertSame($eur, (int) self::reload($order)->id_currency);
            $total = self::total($order);

            $this->pay($order);
            self::assertSame(Config::toCheckState(), self::state($order));
            $this->assertThePaymentIsTheInvoicesPrice($order, $invoice);
            self::assertStringContainsString('came in Lightning, for an invoice of ' . $invoice->sats() . ' sats (' . $invoice->amount . ' CHF)', self::note($order));
            self::assertStringContainsString('The order now owes ' . $total . ' EUR', self::note($order));
            $this->assertTheCustomerIsToldReceivedNotPaid($order);
        } finally {
            // The other tests' shop has one currency.
            $currency = new \Currency($eur);
            $currency->active = false;
            $currency->update();
        }
    }

    public function testAPaymentAndACreditSlipRecordedMeanwhileAreDeducted(): void
    {
        $order = $this->checkout();
        $invoice = $this->attempt((int) $order->id_cart);
        // Part of it paid in cash, then a part of a product refunded (a credit slip): PrestaShop
        // refunds an order only once it has a payment, or was paid.
        $this->handle(new AddPaymentCommand((int) $order->id, date('Y-m-d'), 'Cash', '5.00', (int) self::reload($order)->id_currency, 1));
        $line = self::details($order)[0];
        $this->handle(new IssuePartialRefundCommand((int) $order->id, [Database::int($line['id_order_detail']) => ['quantity' => 1, 'amount' => 2.0]], '0', false, true, false, VoucherRefundType::PRODUCT_PRICES_REFUND));
        self::assertSame(1, Database::int(Database::value('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_slip` WHERE `id_order` = ' . (int) $order->id)));
        $owed = Amount::subtract(Amount::subtract(self::total($order), '5.00', 2), '2.00', 2);

        $this->pay($order);
        self::assertSame(Config::toCheckState(), self::state($order));
        self::assertCount(2, self::payments($order));
        $this->assertThePaymentIsTheInvoicesPrice($order, $invoice);
        self::assertStringContainsString('The order now owes ' . $owed . ' CHF (its total, less its other payments and its credit slips)', self::note($order));
    }

    /**
     * A cart whose products go by different carriers: PrestaShop makes an order per package, of
     * one reference, and one invoice is made for the cart.
     *
     * @return list<\Order>
     */
    private function splitCheckout(): array
    {
        // In France: PrestaShop's demo carriers deliver there.
        $cart = $this->cart(null, [self::PRODUCT => 1, self::OTHER_PRODUCT => 1], 'CHF', 'FR');
        foreach ([self::PRODUCT => self::CARRIER, self::OTHER_PRODUCT => self::OTHER_CARRIER] as $product => $carrier) {
            (new \Product($product))->setCarriers([(new \Carrier($carrier))->id_reference]);
            $this->restricted[] = $product;
        }
        // PrestaShop keeps a product's carriers for the request: read again.
        \Cache::clean('Carrier::getAvailableCarrierList_*');
        $cart = new \Cart((int) $cart->id);
        \Cart::resetStaticCache();
        $packages = $cart->getPackageList(true)[(int) $cart->id_address_delivery] ?? null;
        self::assertIsArray($packages);
        self::assertCount(2, $packages, 'two packages');
        // The delivery option of both carriers, the one PrestaShop gives the address.
        $options = $cart->getDeliveryOptionList(null, true)[(int) $cart->id_address_delivery] ?? null;
        self::assertIsArray($options);
        $option = array_key_first($options);
        self::assertIsString($option);
        $cart->setDeliveryOption([(int) $cart->id_address_delivery => $option]);
        self::assertTrue($cart->update());
        Platform::context()->cart = new \Cart((int) $cart->id);
        $this->checkout(new \Cart((int) $cart->id));
        $orders = self::orders((int) $cart->id);
        self::assertCount(2, $orders, 'two orders, one per package');
        self::assertSame($orders[0]->reference, $orders[1]->reference);

        return $orders;
    }

    public function testACartSplitIntoTwoOrdersIsPaidByOneInvoice(): void
    {
        $orders = $this->splitCheckout();
        $invoice = $this->attempt((int) $orders[0]->id_cart);
        self::assertSame(Amount::add(self::total($orders[0]), self::total($orders[1]), 2), $invoice->amount, 'the invoice is for both orders');

        $this->pay($orders[0]);
        foreach ($orders as $order) {
            self::assertSame(self::os('PS_OS_PAYMENT'), self::state($order));
        }
        $this->assertThePaymentIsTheInvoicesPrice($orders[0], $invoice);
        // One payment for both, by their reference: PrestaShop added none for "the rest" of either
        // order's invoice (OrderHistory::changeIdOrderState adds one where an invoice is not paid).
        self::assertCount(1, self::payments($orders[0]));
        foreach ($orders as $order) {
            self::assertSame('', self::note($order));
        }
    }

    public function testACartSplitIntoTwoOrdersOneOfThemChangedIsToCheckWhole(): void
    {
        $orders = $this->splitCheckout();
        $invoice = $this->attempt((int) $orders[0]->id_cart);
        \StockAvailable::setQuantity(8, 0, 100000);
        $this->handle(AddProductToOrderCommand::withNewInvoice((int) $orders[1]->id, 8, 0, '11.90', '11.90', 1));
        $owed = Amount::add(self::total($orders[0]), self::total($orders[1]), 2);

        $this->pay($orders[0]);
        foreach ($orders as $order) {
            self::assertSame(Config::toCheckState(), self::state($order));
            self::assertStringContainsString('The order now owes ' . $owed . ' CHF', self::note($order));
        }
        $this->assertThePaymentIsTheInvoicesPrice($orders[0], $invoice);
    }

    /**
     * PrestaShop's "Canceled" and "Payment error" (both give the products back to the stock),
     * refused while a payment of the order may still come, and once bitcoin came: by
     * changeIdOrderState as any caller runs it, and by the back office's own command. Nothing of
     * the order changes: its state, its history, its stock.
     */
    public function testAnOrderIsNotCanceledWhileItsPaymentMayComeNorOnceBitcoinCame(): void
    {
        $stock = static fn (): int => (int) \StockAvailable::getQuantityAvailableByProduct(self::PRODUCT, 0);
        $refused = function (\Order $order, string $state, string $message): void {
            $before = [self::state($order), self::history($order)];
            $history = new \OrderHistory();
            $history->id_order = (int) $order->id;
            try {
                $history->changeIdOrderState(self::os($state), self::reload($order));
                self::fail($state . ' was not refused');
            } catch (CancellationRefusedException $e) {
                self::assertStringContainsString($message, $e->getMessage());
                self::assertStringContainsString('order ' . self::reload($order)->reference . ' was not canceled', $e->getMessage());
            }
            try {
                $this->handle(new UpdateOrderStatusCommand((int) $order->id, self::os($state)));
                self::fail($state . ' was not refused by the back office\'s command');
            } catch (CancellationRefusedException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
            self::assertSame($before, [self::state($order), self::history($order)]);
        };

        // Its invoice can be paid, then its payment is being confirmed.
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $ordered = $stock();
        foreach (['PS_OS_CANCELED', 'PS_OS_ERROR'] as $state) {
            $refused($order, $state, 'its bitcoin invoice can still be paid');
        }
        $this->fake->setStatus($attempt->ref, ['state' => 'unconfirmed', 'received' => (string) $attempt->sats(), 'confirmations' => 1]);
        self::assertTrue($this->module->checker()->check($attempt->id, 0, 5));
        self::assertSame(Config::confirmingState(), self::state($order));
        $refused($order, 'PS_OS_CANCELED', 'its payment is being confirmed');
        self::assertSame($ordered, $stock(), 'its product still ordered');

        // "Cancel products", every one, as PrestaShop's form handler runs it: refused before anything
        // changes, its stock and its total included.
        $other = $this->checkout();
        $stockBefore = $stock();
        $totalBefore = self::total($other);
        $detailsBefore = self::details($other);
        self::assertCount(1, $detailsBefore);
        try {
            $this->cancelProducts($other);
            self::fail('"Cancel products" was not refused');
        } catch (CancellationRefusedException $e) {
            self::assertStringContainsString('sattle.me: the products of order ' . self::reload($other)->reference . ' were not canceled, nor removed: its bitcoin invoice can still be paid', $e->getMessage());
        }
        self::assertSame($stockBefore, $stock(), 'its product not given back to the stock');
        self::assertSame($totalBefore, self::total($other), 'its total unchanged');
        self::assertSame($detailsBefore, self::details($other), 'its products unchanged');
        self::assertSame([Config::awaitingState()], self::history($other));

        // Paid.
        $paid = $this->pay($order);
        self::assertSame(Attempt::PAID, $paid->state);
        $refused($order, 'PS_OS_CANCELED', 'bitcoin came for it');

        // Paid: its refunds are the merchant's to record (they need a payment, "Cancel products"
        // refuses one): the form's hook leaves the order to PrestaShop.
        \Hook::exec('actionBeforeUpdateCancelProductFormHandler', ['form_data' => [], 'id' => (int) $order->id]);

        // Ended underpaid: what came is in the merchant's wallet.
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'expired', 'received' => '12000']);
        self::assertTrue($this->module->checker()->check($attempt->id, 0, 5));
        self::assertSame(Attempt::EXPIRED, $this->attempt((int) $order->id_cart)->state);
        $refused($order, 'PS_OS_CANCELED', 'bitcoin came for it');
        $stockBefore = $stock();
        try {
            $this->cancelProducts($order);
            self::fail('"Cancel products" was not refused once bitcoin came');
        } catch (CancellationRefusedException $e) {
            self::assertStringContainsString('were not canceled, nor removed: bitcoin came for it', $e->getMessage());
        }
        self::assertSame($stockBefore, $stock());
        self::assertCount(1, self::details($order));

        // An order of another payment method is not the module's: canceled as PrestaShop does,
        // whatever attempts its cart has.
        $other = $this->checkout();
        Database::execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET `module` = ' . Database::quote('ps_wirepayment') . ' WHERE `id_order` = ' . (int) $other->id);
        // This process loaded the order already: PrestaShop's object cache would give it back as it was.
        \Cache::clean('objectmodel_Order_' . (int) $other->id . '_*');
        $this->handle(new UpdateOrderStatusCommand((int) $other->id, self::os('PS_OS_CANCELED')));
        self::assertSame(self::os('PS_OS_CANCELED'), self::state($other));

        // Ended unpaid, nothing received: the merchant cancels it, as any order.
        $order = $this->checkout();
        $attempt = $this->attempt((int) $order->id_cart);
        $this->fake->setStatus($attempt->ref, ['state' => 'expired', 'received' => '0']);
        self::assertTrue($this->module->checker()->check($attempt->id, 0, 5));
        $ordered = $stock();
        $this->handle(new UpdateOrderStatusCommand((int) $order->id, self::os('PS_OS_CANCELED')));
        self::assertSame(self::os('PS_OS_CANCELED'), self::state($order));
        self::assertSame($ordered + 1, $stock(), 'canceled: its product back in stock');
    }

    /**
     * PrestaShop's change of several orders' state at once (BulkChangeOrderStatusHandler) stops at
     * the order refused: the ones before it changed, it and the ones after it did not; the message
     * names it.
     */
    public function testABulkCancellationStopsAtTheOrderRefusedAndNamesIt(): void
    {
        $before = $this->checkout();
        $expired = $this->attempt((int) $before->id_cart);
        $this->fake->setStatus($expired->ref, ['state' => 'expired', 'received' => '0']);
        self::assertTrue($this->module->checker()->check($expired->id, 0, 5));
        $refused = $this->checkout();
        $after = $this->checkout();
        $afterAttempt = $this->attempt((int) $after->id_cart);
        $this->fake->setStatus($afterAttempt->ref, ['state' => 'expired', 'received' => '0']);
        self::assertTrue($this->module->checker()->check($afterAttempt->id, 0, 5));
        try {
            $this->handle(new BulkChangeOrderStatusCommand([(int) $before->id, (int) $refused->id, (int) $after->id], self::os('PS_OS_CANCELED')));
            self::fail('the bulk change was not refused');
        } catch (CancellationRefusedException $e) {
            self::assertStringContainsString('sattle.me: order ' . self::reload($refused)->reference . ' was not canceled: its bitcoin invoice can still be paid', $e->getMessage());
            self::assertStringContainsString('If you changed several orders at once, the change stopped at this order: the ones after it were left as they were.', $e->getMessage());
        }
        self::assertSame(self::os('PS_OS_CANCELED'), self::state($before));
        self::assertSame(Config::awaitingState(), self::state($refused));
        self::assertSame(Config::awaitingState(), self::state($after), 'after the order refused: left as it was, though it may be canceled');
    }

    /**
     * An order canceled while an invoice of it is open (by a way that is not PrestaShop's, which the
     * module refuses: here its database): its pages, which read its state at each display, never
     * show the invoice as payable.
     */
    public function testAnOpenInvoiceOfACanceledOrderIsNeverShownAsPayable(): void
    {
        $order = $this->checkout();
        Database::execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET `current_state` = ' . self::os('PS_OS_CANCELED') . ' WHERE `id_order` = ' . (int) $order->id);
        \Cache::clean('objectmodel_Order_' . (int) $order->id . '_*');
        [$phase, $attempt] = $this->module->phaseOf(self::reload($order));
        self::assertSame(Attempt::OPEN, $attempt?->state);
        self::assertSame(Phase::CANCELED, $phase);
        $view = $this->module->payPage()->view((string) $order->reference, $attempt, $phase, false, Clock::now(), false);
        self::assertFalse($view['payable']);
        self::assertIsArray($view['payment']);
        self::assertNull($view['payment']['uri'], 'no invoice to pay shown');
        self::assertNull($view['payment']['lightning']);
    }

    /** "Refunded", which the module never refuses, then paid all the same: to check. */
    public function testAnOrderRefundedThenPaidIsToCheck(): void
    {
        $order = $this->checkout();
        $invoice = $this->attempt((int) $order->id_cart);
        $history = new \OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState(self::os('PS_OS_REFUND'), self::reload($order));
        self::assertTrue($history->add());
        self::assertSame(Phase::CANCELED, $this->module->phaseOf(self::reload($order))[0]);
        // PrestaShop's "Refunded" numbers an invoice of the order (its state's "invoice" flag).
        $invoiceNumber = (int) self::reload($order)->invoice_number;

        $this->pay($order, 'onchain');
        self::assertSame(Config::toCheckState(), self::state($order), 'never paid, never left refunded unseen: for the merchant to look at');
        self::assertSame([Config::awaitingState(), self::os('PS_OS_REFUND'), Config::toCheckState()], self::history($order));
        // Nothing else: not valid, no other invoice, no other payment than the bitcoin one.
        self::assertFalse((bool) self::reload($order)->valid);
        self::assertSame($invoiceNumber, (int) self::reload($order)->invoice_number);
        self::assertCount(1, self::payments($order));
        $this->assertThePaymentIsTheInvoicesPrice($order, $invoice);
        self::assertStringContainsString('Bitcoin payment ' . $invoice->ref . ' came for this order, which was canceled or refunded: ' . $invoice->sats() . ' sats came on-chain, for an invoice of ' . $invoice->sats() . ' sats (' . $invoice->amount . ' CHF), and reached your wallet.', self::note($order));
        self::assertStringContainsString('reinstate it, or refund the customer from your wallet', self::note($order));
        $this->assertTheCustomerIsToldReceivedNotPaid($order);
    }

    public function testACompletionStoppedHalfWayIsDoneAgainTheSameWay(): void
    {
        $order = $this->checkout();
        $invoice = $this->attempt((int) $order->id_cart);
        \StockAvailable::setQuantity(self::OTHER_PRODUCT, 0, 100000);
        $this->handle(AddProductToOrderCommand::withNewInvoice((int) $order->id, self::OTHER_PRODUCT, 0, '11.90', '11.90', 1));
        $this->pay($order);
        $note = self::note($order);
        // As if it had stopped once the note was written: the attempt not final, the order not
        // moved yet, its total now the invoice's again (the merchant took the product back out).
        Database::execute('UPDATE `' . AttemptRepository::table() . '` SET `state` = ' . Database::quote(Attempt::OPEN) . ', `final` = 0 WHERE `id_sattleme_payment` = ' . $invoice->id);
        Database::execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET `current_state` = ' . Config::awaitingState() . ', `total_paid_tax_incl` = ' . Database::quote($invoice->amount) . ' WHERE `id_order` = ' . (int) $order->id);
        \Cache::clean('objectmodel_Order_' . (int) $order->id . '_*');

        self::assertTrue($this->module->checker()->check($invoice->id, 0, 5));
        self::assertSame(Config::toCheckState(), self::state($order), 'what was decided stays: never "Payment accepted"');
        self::assertSame($note, self::note($order), 'told once');
        $this->assertThePaymentIsTheInvoicesPrice($order, $invoice);
    }
}
