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
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The SDK's errors as the module tells them: a stable code (stored, logged), whether the same
 * call may succeed later, and plain words for the customer and the merchant, never the SDK's
 * internals.
 */
final class Errors
{
    /** The checks of the order itself: its amount or its currency. */
    public const ORDER = ['invalid_order', 'unsupported_currency'];

    /** Codes of the module's own, beside the SDK's. */
    public const TIMEOUT = 'timeout';
    public const ENVIRONMENT = 'environment';
    public const NO_KEY = 'no_key';
    public const APPLY = 'apply';
    public const UNEXPECTED = 'error';

    private function __construct()
    {
    }

    /** A short, stable name of the error of a call: the SDK's code, or one of the module's. */
    public static function code(\Throwable $error): string
    {
        if ($error instanceof SattlemeException) {
            return $error->getErrorCode();
        }
        if ($error instanceof CancelledException) {
            return self::TIMEOUT;
        }
        if ($error instanceof UnsupportedEnvironmentException) {
            return self::ENVIRONMENT;
        }

        return self::UNEXPECTED;
    }

    /**
     * Whether the same call may succeed later, unchanged: the SDK's word for its errors; a call
     * that took too long, or failed unexpectedly, is tried again too.
     */
    public static function isRetryable(\Throwable $error): bool
    {
        if ($error instanceof SattlemeException) {
            return $error->isRetryable();
        }

        return !$error instanceof UnsupportedEnvironmentException;
    }

    /** An error of a call, for the logs: the SDK's messages never hold a key; others say their type. */
    public static function describe(\Throwable $error): string
    {
        if ($error instanceof SattlemeException) {
            return $error->getErrorCode() . ': ' . $error->getMessage();
        }
        if ($error instanceof CancelledException) {
            return self::TIMEOUT . ': the call took too long';
        }
        if ($error instanceof UnsupportedEnvironmentException) {
            return self::ENVIRONMENT . ': ' . $error->getMessage();
        }

        return self::UNEXPECTED . ': ' . get_class($error);
    }

    /** What the customer is told when no invoice could be made for their order. */
    public static function forCustomer(string $code, TranslatorInterface $translator): string
    {
        if (in_array($code, self::ORDER, true)) {
            return $translator->trans('This order cannot be paid with bitcoin. Please choose another payment method.', [], 'Modules.Sattleme.Shop');
        }
        if (in_array($code, ['network', 'rate', 'onchain_unavailable', self::TIMEOUT, self::UNEXPECTED], true)) {
            return $translator->trans('We could not create your bitcoin invoice right now. Please try again in a moment.', [], 'Modules.Sattleme.Shop');
        }

        return $translator->trans('Bitcoin payments are not available right now. Please choose another payment method, or contact us.', [], 'Modules.Sattleme.Shop');
    }

    /** What the merchant reads of the last error of a payment, in plain words. */
    public static function forMerchant(string $code, TranslatorInterface $translator): string
    {
        switch ($code) {
            case 'network':
                return $translator->trans('The merchant\'s relays, its Lightning address, the rate sources, the block explorer (mempool.space, or the merchant\'s Bitcoin node) or BlockCypher did not answer. The payment is checked again later.', [], 'Modules.Sattleme.Admin');
            case 'rate':
                return $translator->trans('No usable bitcoin price was found. Try again later.', [], 'Modules.Sattleme.Admin');
            case 'onchain_unavailable':
                return $translator->trans('No deposit address could be leased for an amount payable on-chain only: open the sattle.me app to fill the integration\'s lot again.', [], 'Modules.Sattleme.Admin');
            case 'invalid_key':
                return $translator->trans('The integration key is not valid.', [], 'Modules.Sattleme.Admin');
            case 'revoked':
                return $translator->trans('The integration was removed in the sattle.me app: create a new one and enter its key.', [], 'Modules.Sattleme.Admin');
            case 'channel_not_found':
                return $translator->trans('The integration could not be found on its relays. If this lasts, update the module.', [], 'Modules.Sattleme.Admin');
            case 'invalid_invoice':
                return $translator->trans('The merchant\'s Lightning address answered with an invoice or a payment that failed the checks.', [], 'Modules.Sattleme.Admin');
            case 'invalid_order':
                return $translator->trans('The order or the stored invoice was refused by the SDK.', [], 'Modules.Sattleme.Admin');
            case 'unsupported_currency':
                return $translator->trans('No rate source has a price in this currency.', [], 'Modules.Sattleme.Admin');
            case self::TIMEOUT:
                return $translator->trans('The check took too long. The payment is checked again later.', [], 'Modules.Sattleme.Admin');
            case self::ENVIRONMENT:
                return $translator->trans('This server\'s PHP lacks what the module needs (see the module\'s settings).', [], 'Modules.Sattleme.Admin');
            case self::NO_KEY:
                return $translator->trans('No integration key is set, or it cannot be read: enter it again in the module\'s settings.', [], 'Modules.Sattleme.Admin');
            case self::APPLY:
                return $translator->trans('PrestaShop could not record what the check found (see PrestaShop\'s logs). It is tried again at the next check.', [], 'Modules.Sattleme.Admin');
            default:
                return $translator->trans('An unexpected error occurred (see PrestaShop\'s logs).', [], 'Modules.Sattleme.Admin');
        }
    }

    /** Whether the relays said the integration's channel is of a newer format than this SDK reads. */
    public static function isNewerFormat(\Throwable $error): bool
    {
        return $error instanceof ChannelNotFoundException && $error->isNewerFormat();
    }
}
