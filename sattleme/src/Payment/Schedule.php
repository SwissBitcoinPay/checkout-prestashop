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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * When an invoice is due for a check again in the background (the cron URL, every minute; a check
 * due runs at the cron's next call, within a minute of it; counted from the start of the run that
 * checked it, less SLACK, so that the next run finds it due):
 *
 * - every minute while it can be paid;
 * - once just before the end of its grace period (an on-chain payment a check lists by then came
 *   in time, without asking BlockCypher; with a once-a-minute cron, this check runs 1 to 60 s
 *   before the end), once just after (its final status);
 * - then every five minutes until its final status is known (an on-chain payment's confirmations);
 * - after failed checks past its end, less and less often, up to every 30 minutes;
 * - given up 90 days after it was made, with a note on the order.
 */
final class Schedule
{
    /** The SDK reports a payment of an invoice's last seconds up to this long after its end (s). */
    public const GRACE_SECONDS = 120;

    public const OPEN_INTERVAL = 60;
    public const SETTLING_INTERVAL = 300;
    public const END_MARGIN = 10;
    public const MAX_BACKOFF = 1800;
    public const GIVE_UP_AFTER = 90 * 86400;

    /** A customer's page, or the merchant's view of the order, has an invoice checked at most this often. */
    public const WEB_INTERVAL = 6;

    private function __construct()
    {
    }

    /**
     * What a background check's due time is set before its mark (seconds): it is counted from the
     * start of the cron's run that checked the invoice, and the next run, a minute later, starts a
     * second or two earlier or later (the cron, PHP's start); it then finds the invoice due. Below
     * END_MARGIN: the checks around the end of the grace period stay on their side of it.
     */
    public const SLACK = 5;

    /**
     * When an invoice ending at $expiresAt is due for a background check again, for a check that
     * belongs to a run started at $from (the cron's run; a page's or the merchant's check: its own
     * start). Counted from the run's start, never from the check's end: a check that took 20 s of a
     * run is due at the next run all the same, a minute after this one (not two).
     */
    public static function nextCheckAt(int $expiresAt, int $errors, int $from): int
    {
        return $from + self::nextDelay($expiresAt, $errors, $from) - self::SLACK;
    }

    /** Seconds from $now until the next background check of an invoice ending at $expiresAt. */
    public static function nextDelay(int $expiresAt, int $errors, int $now): int
    {
        $end = $expiresAt + self::GRACE_SECONDS;
        if ($now < $end + self::END_MARGIN) {
            // While it can be paid, whatever failed: every minute, and once just before and once
            // just after the end of its grace period. Never sooner than a page's checks.
            $mark = $now < $end - self::END_MARGIN ? $end - self::END_MARGIN : $end + self::END_MARGIN;

            return max(self::WEB_INTERVAL, min(self::OPEN_INTERVAL, $mark - $now));
        }
        if ($errors > 0) {
            return min(self::MAX_BACKOFF, self::OPEN_INTERVAL * 2 ** min($errors - 1, 10));
        }

        return self::SETTLING_INTERVAL;
    }

    /** Whether an invoice made at $createdAt, with no final status yet, is given up on. */
    public static function isGivenUp(int $createdAt, int $now): bool
    {
        return $now - $createdAt >= self::GIVE_UP_AFTER;
    }
}
