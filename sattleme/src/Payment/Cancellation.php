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
 * Whether an order may be canceled (PrestaShop's "Canceled", or "Payment error", which cancels it
 * too: both give its products back to the stock), from its cart's attempts, as the server's checks
 * found them. Refused while a payment may still come (an invoice that can still be paid, a payment
 * being confirmed, an invoice whose final status is not known yet: a Lightning invoice cannot be
 * revoked), and once bitcoin came (paid; found paid, not recorded yet; an underpayment, whatever
 * its end: the sats are in the merchant's wallet, which the merchant settles with the customer).
 */
final class Cancellation
{
    /** A payment of the order may still come. */
    public const MAY_COME = 'may_come';

    /** Bitcoin came for the order. */
    public const CAME = 'came';

    /** An invoice of the order is being made (another request holds its cart's lock too long). */
    public const BEING_MADE = 'being_made';

    /**
     * How long a cancellation let through marks its cart as being canceled (seconds): the time
     * PrestaShop takes to write the order's state once this module let it through (the other
     * modules' reactions to the change, the stock given back), a new invoice of the cart refused
     * meanwhile (Creator::renew). A change another module aborts leaves the mark this long at most.
     */
    public const WRITE_SECONDS = 60;

    private function __construct()
    {
    }

    /**
     * Why the order of these attempts may not be canceled; null: it may.
     *
     * @param list<Attempt> $attempts its cart's
     */
    public static function refusal(array $attempts): ?string
    {
        $mayCome = false;
        foreach ($attempts as $attempt) {
            // Being confirmed: what came may still fail (a transaction replaced or dropped).
            $confirming = !$attempt->final && $attempt->state === Attempt::UNCONFIRMED;
            if ($attempt->state === Attempt::PAID || $attempt->statusState() === PaymentStatus::PAID || ($attempt->hasReceived() && !$confirming)) {
                return self::CAME;
            }
            if (!$attempt->final && $attempt->invoice !== null && in_array($attempt->state, [Attempt::OPEN, Attempt::UNCONFIRMED], true)) {
                $mayCome = true;
            }
        }

        return $mayCome ? self::MAY_COME : null;
    }
}
