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

namespace Sattleme\PrestaShop\Privacy;

use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\AttemptRepository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Removes what leads to a customer's coins from a payment's record, when the customer's personal
 * data is erased (PrestaShop's GDPR module). A bitcoin payment holds no name nor email, but an
 * on-chain one is public: the deposit address its customer paid, and the transactions that paid it,
 * lead anyone to the customer's coins and wallet on the blockchain. Those go; what stays is not
 * personal data on its own, as PrestaShop keeps an order's payments: the reference, amounts, rate,
 * dates and state, the Lightning invoice and its payment hash, the proof of a Lightning payment (a
 * Lightning invoice names the merchant's node, never the customer's).
 *
 * An attempt whose final status is not known yet (an on-chain payment being confirmed) keeps what
 * its checks need until it is final, then loses it.
 */
final class Shredder
{
    /** What replaces a value removed. */
    public const REMOVED = '█';

    /** @var AttemptRepository */
    private $repository;

    public function __construct(AttemptRepository $repository)
    {
        $this->repository = $repository;
    }

    /** Marks the attempt for erasure: done now when it is final, else once it is (finished()). */
    public function shred(Attempt $attempt): void
    {
        if (!$attempt->final) {
            $this->repository->shred($attempt->id, null, null, false);

            return;
        }
        $this->repository->shred($attempt->id, self::invoice($attempt->invoice), self::status($attempt->status), true);
    }

    /** An attempt became final: if marked for erasure, it loses now what its checks needed. */
    public function finished(Attempt $attempt): void
    {
        if ($attempt->shredded && $attempt->final) {
            $this->repository->shred($attempt->id, self::invoice($attempt->invoice), self::status($attempt->status), true);
        }
    }

    /**
     * An invoice without its deposit address, which its payment URI (BIP-21) holds too.
     *
     * @param array<string, mixed>|null $invoice
     *
     * @return array<string, mixed>|null
     */
    public static function invoice(?array $invoice): ?array
    {
        if ($invoice !== null && is_string($invoice['onchainAddress'] ?? null) && $invoice['onchainAddress'] !== '') {
            $invoice['onchainAddress'] = self::REMOVED;
            $invoice['paymentUri'] = self::REMOVED;
        }

        return $invoice;
    }

    /**
     * A status without the transactions that paid the invoice, nor the rest to pay (whose payment
     * URI holds the address).
     *
     * @param array<string, mixed>|null $status
     *
     * @return array<string, mixed>|null
     */
    public static function status(?array $status): ?array
    {
        if ($status === null) {
            return null;
        }
        if (is_array($status['txids'] ?? null) && $status['txids'] !== []) {
            $status['txids'] = [];
        }
        if (($status['rest'] ?? null) !== null) {
            $status['rest'] = null;
        }

        return $status;
    }
}
