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

namespace Sattleme\PrestaShop\View;

use Sattleme\PrestaShop\Payment\Amount;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\Phase;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the customer's pages show of an attempt and its payment: the payment page, the order's
 * confirmation and its details. Values only: the templates escape them.
 */
final class PaymentView
{
    /** What the SDK writes as a payment URI: BIP-21 (with lightning=), or a Lightning one. */
    private const URI_SCHEMES = ['bitcoin:', 'lightning:'];

    private function __construct()
    {
    }

    /**
     * @param callable(string): string $formatSats sats as the customer's language groups them
     *
     * @return array<string, mixed>
     */
    public static function of(Attempt $attempt, string $phase, int $now, callable $formatSats, string $qrLabel, bool $withQr): array
    {
        $rest = $attempt->rest();
        if ($phase === Phase::UNDERPAID && $rest !== null) {
            $uri = self::uri($rest['paymentUri']);
            $lightning = $rest['bolt11'];
            $toPay = preg_match('/^[0-9]{1,18}$/D', $rest['sats']) === 1 ? $rest['sats'] : '0';
        } else {
            $uri = self::uri($attempt->paymentUri());
            $lightning = $attempt->bolt11();
            $toPay = $attempt->sats() ?? '0';
        }
        $show = in_array($phase, Phase::PAYABLE, true);
        // On-chain to the invoice's address: its BIP-21 URI says so (a rest below 1 000 sats is
        // payable in Lightning only).
        $onchain = $uri !== null && str_starts_with($uri, 'bitcoin:') && $attempt->onchainAddress() !== null;
        $left = max(0, ($attempt->expiresAt ?? $now) - $now);

        return [
            'phase' => $phase,
            'reference' => $attempt->ref,
            'sats' => $formatSats($attempt->sats() ?? '0'),
            'btc' => Amount::btc($attempt->sats() ?? '0'),
            'received_sats' => $formatSats($attempt->received()),
            'has_received' => $attempt->hasReceived(),
            'to_pay_sats' => $formatSats($toPay),
            'to_pay_btc' => Amount::btc($toPay),
            'uri' => $show ? $uri : null,
            'qr' => $show && $withQr && $uri !== null ? Qr::dataUri($uri, $qrLabel) : null,
            'qr_label' => $qrLabel,
            'lightning' => $show ? $lightning : null,
            'address' => $show && $onchain ? $attempt->onchainAddress() : null,
            'has_onchain' => $attempt->onchainAddress() !== null,
            'seconds_left' => $left,
            'time_left' => intdiv($left, 60) . ':' . str_pad((string) ($left % 60), 2, '0', STR_PAD_LEFT),
            'expires_at' => $attempt->expiresAt,
            'confirmations' => $attempt->confirmations() ?? 0,
            'poll_interval' => Phase::pollInterval($phase),
            'version' => self::version($attempt, $phase),
        ];
    }

    /**
     * What the page shows, in a few characters: the page reloads when it changes.
     */
    public static function version(Attempt $attempt, string $phase): string
    {
        $rest = $attempt->rest();
        $shown = [$phase, $attempt->received(), $rest['bolt11'] ?? null, $rest['paymentUri'] ?? null, $attempt->confirmations(), $attempt->id];

        return substr(hash('sha256', (string) json_encode($shown)), 0, 16);
    }

    /** A payment URI the SDK wrote, else null: never a link of another scheme. */
    public static function uri(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        foreach (self::URI_SCHEMES as $scheme) {
            if (str_starts_with($value, $scheme) && preg_match('/^[A-Za-z0-9:?=&.\-]+$/D', $value) === 1) {
                return $value;
            }
        }

        return null;
    }
}
