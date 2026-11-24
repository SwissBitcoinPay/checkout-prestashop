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

use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\Phase;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the customer's pages show of an order's payment (the payment page, the order's confirmation
 * and its details): PaymentView's values, the amount in the order's currency, and the sentences
 * that hold values, translated here. Values only: the templates escape every one of them.
 */
final class PayPage
{
    /** The phase of an order whose invoice is not made yet: no attempt at all. */
    public const NONE = 'none';

    /** @var TranslatorInterface */
    private $translator;

    /** @var callable(string): string */
    private $formatSats;

    /** @var callable(string, string): string */
    private $formatPrice;

    /**
     * @param callable(string): string $formatSats sats as the customer's language groups them
     * @param callable(string, string): string $formatPrice an amount in a currency, as the customer's language writes it
     */
    public function __construct(TranslatorInterface $translator, callable $formatSats, callable $formatPrice)
    {
        $this->translator = $translator;
        $this->formatSats = $formatSats;
        $this->formatPrice = $formatPrice;
    }

    /**
     * @param string|null $phase the attempt's (Phase), null without attempt
     *
     * @return array<string, mixed>
     */
    public function view(string $orderReference, ?Attempt $attempt, ?string $phase, bool $mayRenew, int $now, bool $withQr): array
    {
        $phase = $attempt === null || $phase === null ? self::NONE : $phase;
        $payment = $attempt === null ? null : PaymentView::of(
            $attempt,
            $phase,
            $now,
            $this->formatSats,
            $this->translator->trans('QR code of the bitcoin invoice', [], 'Modules.Sattleme.Shop'),
            $withQr,
        );
        $sats = $payment['sats'] ?? '0';
        $received = $payment['received_sats'] ?? '0';
        $toPay = $payment['to_pay_sats'] ?? '0';

        $mayRenew = $mayRenew && in_array($phase, [self::NONE, Phase::EXPIRED, Phase::CREATING, Phase::FAILED], true);

        return [
            'phase' => $phase,
            'payable' => in_array($phase, Phase::PAYABLE, true) && $payment !== null && $payment['uri'] !== null,
            // The page's "Check again": while the server's checks may still change what it shows.
            'checkable' => Phase::pollInterval($phase) !== null,
            'may_renew' => $mayRenew,
            // The confirmation and the order's details lead to the page while there is something to do there.
            // What only the merchant can settle: the page leads to the shop's contact form.
            'contact' => in_array($phase, [Phase::EXPIRED, Phase::UNDERPAID_EXPIRED, Phase::FAILED, Phase::ABANDONED, Phase::REVIEW], true),
            'to_page' => $mayRenew || in_array($phase, [self::NONE, Phase::OPEN, Phase::UNDERPAID, Phase::CHECKING, Phase::ENDED, Phase::CREATING, Phase::UNCONFIRMED, Phase::RECORDING], true),
            'payment' => $payment,
            'amount' => $attempt === null ? '' : ($this->formatPrice)($attempt->amount, $attempt->currency),
            'texts' => [
                'order' => $this->translator->trans('Order %reference%', ['%reference%' => $orderReference], 'Modules.Sattleme.Shop'),
                'sats' => $this->translator->trans('%sats% sats', ['%sats%' => $sats], 'Modules.Sattleme.Shop'),
                'btc' => $this->translator->trans('%btc% BTC', ['%btc%' => $payment['btc'] ?? '0'], 'Modules.Sattleme.Shop'),
                'to_pay_sats' => $this->translator->trans('%sats% sats', ['%sats%' => $toPay], 'Modules.Sattleme.Shop'),
                'to_pay_btc' => $this->translator->trans('%btc% BTC', ['%btc%' => $payment['to_pay_btc'] ?? '0'], 'Modules.Sattleme.Shop'),
                'underpaid' => $this->translator->trans(
                    '%received% of %sats% sats came. Pay the rest to complete your payment: %rest% sats.',
                    ['%received%' => $received, '%sats%' => $sats, '%rest%' => $toPay],
                    'Modules.Sattleme.Shop',
                ),
                'underpaid_expired' => $this->translator->trans(
                    'This invoice ended before it was paid in full: %received% of %sats% sats came. We will contact you to settle the difference.',
                    ['%received%' => $received, '%sats%' => $sats],
                    'Modules.Sattleme.Shop',
                ),
                'confirmations' => $this->translator->trans(
                    '%confirmations% of 3 confirmations',
                    ['%confirmations%' => (string) min(3, is_int($payment['confirmations'] ?? null) ? $payment['confirmations'] : 0)],
                    'Modules.Sattleme.Shop',
                ),
                'reference' => $this->translator->trans('Payment reference: %reference%', ['%reference%' => $attempt->ref ?? ''], 'Modules.Sattleme.Shop'),
                'copied' => $this->translator->trans('Copied', [], 'Modules.Sattleme.Shop'),
            ],
        ];
    }
}
