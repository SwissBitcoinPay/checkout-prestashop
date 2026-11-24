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

use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Client;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\PaymentStatus;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\TimeoutCancellation;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The SDK's own client. It holds the integration key the way the SDK does: in no property one can
 * dump nor serialize.
 */
final class SdkGateway implements Gateway
{
    /** Per relay request and per HTTP request, connection and body included, in milliseconds. */
    public const RELAY_TIMEOUT_MS = 5000;
    public const HTTP_TIMEOUT_MS = 8000;

    /** @var Client */
    private $client;

    /**
     * @param array<string, mixed> $options more options of the SDK's client (tests: a connector)
     *
     * @throws \Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvalidKeyException
     * @throws \Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException
     */
    public function __construct(#[\SensitiveParameter] string $key, array $options = [])
    {
        $this->client = new Client(array_merge([
            'relayTimeoutMs' => self::RELAY_TIMEOUT_MS,
            'httpTimeoutMs' => self::HTTP_TIMEOUT_MS,
            'logger' => Logger::forSdk(),
        ], $options, ['key' => $key]));
    }

    public function createInvoice(array $order, float $seconds): Invoice
    {
        return $this->client->createInvoice($order, new TimeoutCancellation($seconds));
    }

    public function getStatus(array $invoice, float $seconds): PaymentStatus
    {
        return $this->client->getStatus($invoice, new TimeoutCancellation($seconds));
    }

    public function merchant(float $seconds): Merchant
    {
        return $this->client->merchant(new TimeoutCancellation($seconds));
    }

    /** @return array<string, mixed> never the key */
    public function __debugInfo(): array
    {
        return ['client' => $this->client];
    }
}
