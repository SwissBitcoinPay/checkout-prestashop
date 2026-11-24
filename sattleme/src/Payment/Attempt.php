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

namespace Sattleme\PrestaShop\Payment;

use Sattleme\PrestaShop\Database;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * One payment attempt of a cart (and of the orders made from it): its random reference, the
 * invoice the SDK made for it (its JSON, SPEC.md §3) and what the last check found. A row of the
 * table sattleme_payment, as read.
 */
final class Attempt
{
    /** Its row is stored, its invoice not made yet (or the call failed, and is tried again). */
    public const NEW = 'new';
    /** Its invoice may be paid (or its end passed, its final status not known yet). */
    public const OPEN = 'open';
    /** Enough came on-chain in time: being confirmed (3 confirmations). */
    public const UNCONFIRMED = 'unconfirmed';
    /** Paid: final. */
    public const PAID = 'paid';
    /** Never paid, for good: final. */
    public const EXPIRED = 'expired';
    /** No invoice could be made, for good (the SDK refused the order): final. */
    public const FAILED = 'failed';
    /** Never shown (its cart changed before an order was made), or given up after 90 days: final. */
    public const ABANDONED = 'abandoned';

    /** How its invoice came: made by this attempt's call, or an earlier one of its reference. */
    public const ORIGIN_CREATED = 'created';
    public const ORIGIN_PENDING = 'pending';
    public const ORIGIN_PAID = 'paid';

    /**
     * @param array<string, mixed>|null $invoice
     * @param array<string, mixed>|null $status
     * @param list<string> $notes
     */
    public function __construct(
        public readonly int $id,
        public readonly int $idShop,
        public readonly int $idCart,
        public readonly ?int $idOrder,
        public readonly string $orderReference,
        public readonly string $ref,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $state,
        public readonly bool $final,
        public readonly string $origin,
        public readonly ?array $invoice,
        public readonly ?array $status,
        public readonly ?int $expiresAt,
        public readonly int $createdAt,
        public readonly ?int $checkedAt,
        public readonly int $nextCheckAt,
        public readonly ?int $leaseUntil,
        public readonly string $error,
        public readonly int $errors,
        public readonly ?int $completedAt,
        public readonly array $notes,
        public readonly bool $shredded,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $notes = Database::string($row['notes'] ?? null);
        $idOrder = Database::intOrNull($row['id_order'] ?? null);

        return new self(
            Database::int($row['id_sattleme_payment'] ?? null),
            Database::int($row['id_shop'] ?? null),
            Database::int($row['id_cart'] ?? null),
            $idOrder !== null && $idOrder > 0 ? $idOrder : null,
            Database::string($row['order_reference'] ?? null),
            Database::string($row['ref'] ?? null),
            Database::string($row['amount'] ?? null),
            Database::string($row['currency'] ?? null),
            Database::string($row['state'] ?? null),
            Database::int($row['final'] ?? null) === 1,
            Database::string($row['origin'] ?? null) !== '' ? Database::string($row['origin'] ?? null) : self::ORIGIN_CREATED,
            self::json($row['invoice'] ?? null),
            self::json($row['status'] ?? null),
            Database::intOrNull($row['expires_at'] ?? null),
            Database::int($row['created_at'] ?? null),
            Database::intOrNull($row['checked_at'] ?? null),
            Database::int($row['next_check_at'] ?? null),
            Database::intOrNull($row['lease_until'] ?? null),
            Database::string($row['error'] ?? null),
            Database::int($row['errors'] ?? null),
            Database::intOrNull($row['completed_at'] ?? null),
            $notes === '' ? [] : array_values(array_filter(explode(',', $notes), static fn (string $note): bool => $note !== '')),
            Database::int($row['shredded'] ?? null) === 1,
        );
    }

    /**
     * A column of JSON (an invoice, a status), decoded; null when it is empty or not an object.
     *
     * @return array<string, mixed>|null
     */
    private static function json(mixed $text): ?array
    {
        if (!is_string($text) || $text === '') {
            return null;
        }
        $value = json_decode($text, true);
        if (!is_array($value)) {
            return null;
        }
        $typed = [];
        foreach ($value as $key => $item) {
            $typed[(string) $key] = $item;
        }

        return $typed;
    }

    /** When the SDK reports its payment as final at the latest: its end and the grace period. */
    public function end(): ?int
    {
        return $this->expiresAt === null ? null : $this->expiresAt + Schedule::GRACE_SECONDS;
    }

    /** The state of the last status the server's check found: open, unconfirmed, paid, expired. */
    public function statusState(): ?string
    {
        $state = $this->status['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    /** What came in time, in sats (on-chain, and by invoices of the rest): "0" without a check. */
    public function received(): string
    {
        $received = $this->status['received'] ?? null;

        if (!is_string($received) || preg_match('/^[0-9]{1,18}$/D', $received) !== 1) {
            return '0';
        }
        $received = ltrim($received, '0');

        return $received === '' ? '0' : $received;
    }

    public function hasReceived(): bool
    {
        return $this->received() !== '0';
    }

    /**
     * What is left to pay of an on-chain underpayment, and how (sats, bolt11, paymentUri), or null.
     *
     * @return array{sats: string, bolt11: string|null, paymentUri: string|null}|null
     */
    public function rest(): ?array
    {
        $rest = $this->status['rest'] ?? null;
        if (!is_array($rest) || !is_string($rest['sats'] ?? null)) {
            return null;
        }

        return [
            'sats' => $rest['sats'],
            'bolt11' => is_string($rest['bolt11'] ?? null) ? $rest['bolt11'] : null,
            'paymentUri' => is_string($rest['paymentUri'] ?? null) ? $rest['paymentUri'] : null,
        ];
    }

    public function confirmations(): ?int
    {
        $confirmations = $this->status['confirmations'] ?? null;

        return is_int($confirmations) ? $confirmations : null;
    }

    public function method(): ?string
    {
        $method = $this->status['method'] ?? null;

        return is_string($method) ? $method : null;
    }

    /** @return list<string> the transactions the last status names (64 hex characters each) */
    public function txids(): array
    {
        return self::hexList($this->status['txids'] ?? null);
    }

    /** @return list<string> the proofs of payment: the preimage of a Lightning payment, or of the rest's invoices */
    public function preimages(): array
    {
        $preimage = $this->status['preimage'] ?? null;

        return array_values(array_merge(
            is_string($preimage) && preg_match('/^[0-9a-f]{64}$/D', $preimage) === 1 ? [$preimage] : [],
            self::hexList($this->status['preimages'] ?? null),
        ));
    }

    /**
     * The invoice's price, as the SDK made it (its fiat): an amount and its currency; null when the
     * stored invoice has none of the module's.
     *
     * @return array{amount: string, currency: string}|null
     */
    public function price(): ?array
    {
        $fiat = $this->invoice['fiat'] ?? null;
        if (!is_array($fiat) || !is_string($fiat['amount'] ?? null) || !is_string($fiat['currency'] ?? null)
            || !Amount::isDecimal($fiat['amount']) || preg_match('/^[A-Z]{3}$/D', $fiat['currency']) !== 1) {
            return null;
        }

        return ['amount' => $fiat['amount'], 'currency' => $fiat['currency']];
    }

    /**
     * What the payment of a paid invoice brought, in sats: in Lightning, its invoice's amount (a
     * Lightning invoice is paid for its amount); else what the status received in time (on-chain,
     * and by invoices of the rest), which the merchant's tolerance or acceptance may accept below
     * the amount.
     */
    public function paidSats(): ?string
    {
        return $this->method() === 'lightning' || !$this->hasReceived() ? $this->sats() : $this->received();
    }

    /** The invoice's amount in sats. */
    public function sats(): ?string
    {
        $sats = $this->invoice['sats'] ?? null;

        return is_string($sats) && preg_match('/^[0-9]{1,18}$/D', $sats) === 1 ? $sats : null;
    }

    public function bolt11(): ?string
    {
        return $this->invoiceString('bolt11');
    }

    public function paymentHash(): ?string
    {
        return $this->invoiceString('paymentHash');
    }

    public function onchainAddress(): ?string
    {
        return $this->invoiceString('onchainAddress');
    }

    public function paymentUri(): ?string
    {
        return $this->invoiceString('paymentUri');
    }

    /** When the invoice was made, as the SDK dates it (its BOLT11 timestamp). */
    public function invoiceCreatedAt(): ?int
    {
        $createdAt = $this->invoice['createdAt'] ?? null;

        return is_int($createdAt) ? $createdAt : null;
    }

    /** @return array{source: string, currency: string, price: string, fetchedAt: int|null}|null the rate of the price */
    public function rate(): ?array
    {
        $rate = $this->invoice['rate'] ?? null;
        if (!is_array($rate) || !is_string($rate['price'] ?? null) || !is_string($rate['currency'] ?? null)) {
            return null;
        }

        return [
            'source' => is_string($rate['source'] ?? null) ? $rate['source'] : '',
            'currency' => $rate['currency'],
            'price' => $rate['price'],
            'fetchedAt' => is_int($rate['fetchedAt'] ?? null) ? $rate['fetchedAt'] : null,
        ];
    }

    public function hasNote(string $note): bool
    {
        return in_array($note, $this->notes, true);
    }

    private function invoiceString(string $name): ?string
    {
        $value = $this->invoice[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private static function hexList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1));
    }
}
