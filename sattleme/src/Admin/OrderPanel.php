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

use Sattleme\PrestaShop\Payment\Amount;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Privacy\Shredder;
use Sattleme\PrestaShop\Sdk\Errors;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the merchant sees of an order's payments in the back office (the order's page): each
 * attempt's reference, amount and rate, how it can be paid, its address, its transactions (as
 * text to copy into the merchant's block explorer: no link to another site, as PrestaShop Addons
 * asks; an underpayment's, which its status does not list, by its address), each proof of payment
 * (preimage) with the payment hash it proves, its last check (failed, if it did) and its error in
 * plain words.
 */
final class OrderPanel
{
    /** A transaction id or a preimage: 32 bytes, in hex. */
    private const HEX32 = '/^[0-9a-f]{64}$/D';

    private function __construct()
    {
    }

    /**
     * @param list<Attempt> $attempts the latest first
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $attempts, TranslatorInterface $translator): array
    {
        return array_map(static fn (Attempt $attempt): array => self::row($attempt, $translator), $attempts);
    }

    /** @return array<string, mixed> */
    private static function row(Attempt $attempt, TranslatorInterface $translator): array
    {
        $address = $attempt->onchainAddress();
        $shredded = $address === Shredder::REMOVED;
        $sats = $attempt->sats();
        $txids = array_values(array_filter($attempt->txids(), static fn (string $txid): bool => preg_match(self::HEX32, $txid) === 1));
        $preimages = array_values(array_filter($attempt->preimages(), static fn (string $preimage): bool => preg_match(self::HEX32, $preimage) === 1));

        return [
            'ref' => $attempt->ref,
            'state' => $attempt->state,
            'status_state' => $attempt->statusState(),
            'method' => $attempt->method(),
            'final' => $attempt->final,
            'amount' => $attempt->amount,
            'currency' => $attempt->currency,
            'sats' => $sats,
            'btc' => $sats === null ? null : Amount::btc($sats),
            'rate' => $attempt->rate(),
            'lightning' => $attempt->bolt11() !== null,
            'payment_hash' => $attempt->paymentHash(),
            'address' => $address,
            'address_removed' => $shredded,
            'received' => $attempt->received(),
            'has_received' => $attempt->hasReceived(),
            // A failed check's date is its last check's: said as such.
            'check_failed' => $attempt->checkedAt !== null && !in_array($attempt->error, ['', 'abandoned', 'superseded'], true),
            'confirmations' => $attempt->confirmations(),
            'txids' => $txids,
            // Each proof of payment with the payment hash it proves (SHA-256 of the preimage): the
            // invoice's, or one of the rest's invoices.
            'proofs' => array_map(static fn (string $preimage): array => ['preimage' => $preimage, 'hash' => hash('sha256', (string) hex2bin($preimage))], $preimages),
            // Something came on-chain and the status lists no transaction (an underpayment's
            // statuses never do): its transactions are those of the deposit address.
            'txids_at_address' => $txids === [] && $attempt->hasReceived() && $address !== null && !$shredded,
            'created_at' => self::date($attempt->invoiceCreatedAt() ?? $attempt->createdAt),
            'expires_at' => self::date($attempt->expiresAt),
            'checked_at' => self::date($attempt->checkedAt),
            'next_check_at' => self::date($attempt->final || $attempt->invoice === null ? null : $attempt->nextCheckAt),
            'error' => $attempt->error,
            // Why an attempt ended without payment is its state: abandoned, superseded are no errors.
            'error_text' => in_array($attempt->error, ['', 'abandoned', 'superseded'], true) ? '' : Errors::forMerchant($attempt->error, $translator),
            'confirming' => $attempt->state === Attempt::UNCONFIRMED,
            // Found paid by a check, not recorded in PrestaShop yet: the next check records it.
            'recording' => !$attempt->final && $attempt->statusState() === 'paid',
            // Paid, its orders in "Bitcoin payment to check": the order's note says why.
            'to_check' => $attempt->state === Attempt::PAID && $attempt->hasNote('to_check'),
            'underpaid' => $attempt->state === Attempt::EXPIRED && $attempt->hasReceived(),
            'abandoned' => $attempt->state === Attempt::ABANDONED && $attempt->error === 'abandoned',
            'shredded' => $attempt->shredded,
        ];
    }

    /** A time as the back office shows dates, in the shop's time zone; null: none. */
    private static function date(?int $time): ?string
    {
        return $time === null || $time <= 0 ? null : date('Y-m-d H:i:s', $time);
    }
}
