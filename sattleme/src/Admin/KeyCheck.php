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

namespace Sattleme\PrestaShop\Admin;

use Sattleme\PrestaShop\Sdk\Errors;
use Sattleme\PrestaShop\Sdk\GatewayFactory;
use Sattleme\PrestaShop\Sdk\Logger;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\RevokedException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Merchant;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Checks a key the merchant enters, before it is saved: an integration key (the SDK refuses any
 * other), of an integration the merchant still has (the SDK reads its channel and its merchant: a
 * removed integration is refused). No frame of this check, and no message, holds the key.
 */
final class KeyCheck
{
    /** Of the merchant's reading (seconds): it runs in the settings' request. */
    public const TIMEOUT = 15;

    private function __construct()
    {
    }

    /**
     * The merchant of the key, or the reason it is refused, in the merchant's words.
     *
     * @return array{merchant: Merchant|null, error: string|null}
     */
    public static function verify(#[\SensitiveParameter] string $key, TranslatorInterface $translator): array
    {
        try {
            $gateway = GatewayFactory::forKey($key);
        } catch (InvalidKeyException) {
            return ['merchant' => null, 'error' => $translator->trans('This is not the secret key of a sattle.me integration (sattleme_ck_…).', [], 'Modules.Sattleme.Admin')];
        } catch (UnsupportedEnvironmentException $e) {
            return ['merchant' => null, 'error' => $translator->trans('This server cannot run the module: %missing%', ['%missing%' => $e->getMessage()], 'Modules.Sattleme.Admin')];
        } catch (\Throwable $e) {
            Logger::error('A key could not be read: ' . get_class($e));

            return ['merchant' => null, 'error' => $translator->trans('This is not the secret key of a sattle.me integration (sattleme_ck_…).', [], 'Modules.Sattleme.Admin')];
        }
        try {
            return ['merchant' => $gateway->merchant((float) self::TIMEOUT), 'error' => null];
        } catch (RevokedException) {
            return ['merchant' => null, 'error' => $translator->trans('This integration was removed in the sattle.me app. Create a new one and enter its key.', [], 'Modules.Sattleme.Admin')];
        } catch (ChannelNotFoundException $e) {
            if ($e->isNewerFormat()) {
                return ['merchant' => null, 'error' => $translator->trans('This integration needs a newer version of the sattle.me module.', [], 'Modules.Sattleme.Admin')];
            }

            return ['merchant' => null, 'error' => $translator->trans('This integration could not be found. Check the key, or try again in a moment.', [], 'Modules.Sattleme.Admin')];
        } catch (\Throwable $e) {
            Logger::warning('A key could not be verified: ' . Errors::describe($e));

            return ['merchant' => null, 'error' => $translator->trans('sattle.me could not be reached to verify this key. Please try again in a moment.', [], 'Modules.Sattleme.Admin')];
        }
    }
}
