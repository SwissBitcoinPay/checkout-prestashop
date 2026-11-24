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

namespace Sattleme\PrestaShop\Sdk;

use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\PaymentStatus;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The SDK, as this module calls it: the one seam where the tests put a fake in its place. Every
 * call is bounded in time ($seconds): past it, CancelledException.
 */
interface Gateway
{
    /**
     * An invoice for an order (the SDK's createInvoice).
     *
     * @param array<string, mixed> $order ref, fiat, durationSeconds, note
     *
     * @throws InvoicePendingException while an earlier invoice of the reference may still be paid
     * @throws OrderPaidException once an earlier invoice of the reference was paid
     * @throws SattlemeException
     * @throws CancelledException
     */
    public function createInvoice(array $order, float $seconds): Invoice;

    /**
     * The payment status of a stored invoice (the SDK's getStatus).
     *
     * @param array<string, mixed> $invoice the invoice's JSON as stored, decoded
     *
     * @throws SattlemeException
     * @throws CancelledException
     */
    public function getStatus(array $invoice, float $seconds): PaymentStatus;

    /**
     * The merchant as the integration sees it (the SDK's merchant()): reading it checks that the
     * integration is still the merchant's.
     *
     * @throws SattlemeException
     * @throws CancelledException
     */
    public function merchant(float $seconds): Merchant;
}
