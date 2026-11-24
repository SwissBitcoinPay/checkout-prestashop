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

use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\PaymentStatus;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the customer is told of an attempt and its payment, from what the server's checks found
 * (never from the browser). An invoice is shown to pay only while the last status a check stored
 * is none or open (the SDK's rule): one found paid, being confirmed or expired never is.
 *
 * - PAID: the order is paid;
 * - REVIEW: the invoice was paid, and its orders wait for the merchant ("Bitcoin payment to check":
 *   they changed after it was made, or were canceled): received, the shop contacts the customer,
 *   never "paid";
 * - RECORDING: a check found the invoice paid, and PrestaShop has not taken the payment yet (its
 *   recording failed, and is tried again by the next checks): received, nothing to pay;
 * - UNCONFIRMED: enough came on-chain, being confirmed;
 * - EXPIRED: never paid, for good: the customer may ask for a new invoice;
 * - UNDERPAID_EXPIRED: ended before it was paid in full: the merchant settles the difference;
 * - CANCELED: the order was canceled or refunded, its invoice no longer shown;
 * - ENDED: its time is over, its final status not known yet;
 * - UNDERPAID: less than the amount came on-chain: the rest may still be paid;
 * - CHECKING: an earlier invoice of the attempt's reference came back from the SDK: shown only
 *   once a check found it open (the customer would otherwise pay twice);
 * - OPEN: waiting for the payment;
 * - CREATING: no invoice yet (its making failed and may be tried again);
 * - FAILED: no invoice can be made for this order;
 * - ABANDONED: its checks were given up on: the merchant settles it.
 */
final class Phase
{
    public const PAID = 'paid';
    public const REVIEW = 'review';
    public const RECORDING = 'recording';
    public const UNCONFIRMED = 'unconfirmed';
    public const EXPIRED = 'expired';
    public const UNDERPAID_EXPIRED = 'underpaid_expired';
    public const CANCELED = 'canceled';
    public const ENDED = 'ended';
    public const UNDERPAID = 'underpaid';
    public const CHECKING = 'checking';
    public const OPEN = 'open';
    public const CREATING = 'creating';
    public const FAILED = 'failed';
    public const ABANDONED = 'abandoned';

    /** Phases whose page shows the invoice to pay. */
    public const PAYABLE = [self::OPEN, self::UNDERPAID];

    /** Phases whose page asks for news, and how often (seconds). */
    public const POLL_INTERVALS = [
        self::OPEN => 3,
        self::UNDERPAID => 3,
        self::CHECKING => 3,
        self::ENDED => 5,
        self::RECORDING => 10,
        self::UNCONFIRMED => 30,
    ];

    private function __construct()
    {
    }

    /**
     * @param bool $orderPaid whether PrestaShop has the order paid (by this module or otherwise)
     * @param bool $orderClosed whether the order was canceled, refunded or ended in a payment error
     */
    public static function of(Attempt $attempt, bool $orderPaid, bool $orderClosed, int $now): string
    {
        if ($orderPaid) {
            return self::PAID;
        }
        if ($attempt->state === Attempt::PAID) {
            return $attempt->hasNote('to_check') ? self::REVIEW : self::PAID;
        }
        switch ($attempt->state) {
            case Attempt::FAILED:
                return self::FAILED;
            case Attempt::ABANDONED:
                return self::ABANDONED;
            case Attempt::EXPIRED:
                return $attempt->hasReceived() ? self::UNDERPAID_EXPIRED : self::EXPIRED;
            case Attempt::NEW:
                return self::CREATING;
        }
        // Found paid or expired, not applied to the orders yet: the next check applies it.
        $status = $attempt->statusState();
        if ($status === PaymentStatus::PAID) {
            return self::RECORDING;
        }
        if ($attempt->state === Attempt::UNCONFIRMED || $status === PaymentStatus::UNCONFIRMED) {
            return self::UNCONFIRMED;
        }
        if ($status === PaymentStatus::EXPIRED) {
            return self::ENDED;
        }
        if ($orderClosed) {
            return self::CANCELED;
        }
        if ($attempt->origin === Attempt::ORIGIN_PENDING && $status === null) {
            return self::CHECKING;
        }
        if ($attempt->expiresAt === null || $now >= $attempt->expiresAt) {
            return self::ENDED;
        }
        if ($attempt->hasReceived()) {
            return self::UNDERPAID;
        }

        return self::OPEN;
    }

    /** How often a page in this phase asks for news, in seconds; null: it does not. */
    public static function pollInterval(string $phase): ?int
    {
        return self::POLL_INTERVALS[$phase] ?? null;
    }
}
