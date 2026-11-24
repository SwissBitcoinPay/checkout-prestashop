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

use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Sdk\Errors;
use Sattleme\PrestaShop\Sdk\GatewayFactory;
use Sattleme\PrestaShop\Sdk\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Checks the payment of an attempt's invoice with the SDK (getStatus: the server's own check) and
 * applies what it found (Settler). Asked by the customer's page every few seconds, by the merchant's
 * view of the order, and by the background checks (the cron URL).
 *
 * Two checks of one attempt never run at once: a check holds the attempt (a lease in the database,
 * which only the holder writes through) for its call's time and a margin; a check that outlived its
 * lease writes nothing. Checks in a row are harmless: the SDK's checks are made to be repeated, and
 * applying a status is idempotent.
 */
final class Checker
{
    /** Of the SDK's call (seconds): for the customer's page, the merchant's view, the cron. */
    public const WEB_TIMEOUT = 15;
    public const ADMIN_TIMEOUT = 10;
    public const CRON_TIMEOUT = 20;

    /** A check holds its attempt this much longer than its call may take (seconds). */
    public const LEASE_MARGIN = 30;

    /** How long a check waits for the cart's lock (another check, the checkout) to apply its status. */
    public const LOCK_WAIT = 30;

    /** @var AttemptRepository */
    private $repository;

    /** @var Settler */
    private $settler;

    public function __construct(AttemptRepository $repository, Settler $settler)
    {
        $this->repository = $repository;
        $this->settler = $settler;
    }

    /**
     * Checks the attempt now: false when it was skipped (final, being checked, or checked less than
     * $minInterval seconds ago). $runStartedAt: the start of the cron's run it belongs to, from which
     * its next background check is counted (Schedule::nextCheckAt); null: this check's start.
     *
     * @phpstan-impure
     */
    public function check(int $id, int $minInterval, int $timeout, ?int $runStartedAt = null): bool
    {
        $now = Clock::now();
        $from = min($runStartedAt ?? $now, $now);
        $lease = $now + $timeout + self::LEASE_MARGIN;
        if (!$this->repository->claim($id, $now, $lease, $minInterval)) {
            return false;
        }
        $attempt = $this->repository->find($id);
        if ($attempt === null || $attempt->invoice === null) {
            $this->repository->release($id, $lease);

            return false;
        }
        try {
            $gateway = GatewayFactory::forShop($attempt->idShop);
            if ($gateway === null) {
                $this->failed($attempt, $lease, Errors::NO_KEY, 'no integration key is set, or it cannot be read', $from);

                return true;
            }
            $status = $gateway->getStatus($attempt->invoice, (float) $timeout);
        } catch (\Throwable $error) {
            $this->failed($attempt, $lease, Errors::code($error), Errors::describe($error), $from);

            return true;
        }
        try {
            Lock::with('cart', $attempt->idCart, self::LOCK_WAIT, function () use ($id, $lease, $status, $from): void {
                $current = $this->repository->find($id);
                // Final meanwhile, or this check outlived its lease: another one decides.
                if ($current !== null && !$current->final && $this->repository->holdsLease($id, $lease)) {
                    $this->settler->apply($current, $status, Clock::now(), $from);
                }
            });
        } catch (\Throwable $error) {
            // PrestaShop could not take the update (the database, a module reacting to the payment):
            // the attempt is not final, a later check applies its status again.
            $now = Clock::now();
            $this->repository->applyFailed($id, $now, Schedule::nextCheckAt($attempt->expiresAt ?? $now, $attempt->errors + 1, $from));
            if ($attempt->error !== Errors::APPLY) {
                Logger::error('Applying the status of payment ' . $attempt->ref . ' failed: ' . Errors::describe($error), 'Cart', $attempt->idCart);
            }
        } finally {
            $this->repository->release($id, $lease);
        }

        return true;
    }

    /**
     * Has the attempt checked for a page or the merchant's view: at most every few seconds per
     * attempt, never while another check of it runs. Nothing when it is final.
     *
     * @phpstan-impure
     */
    public function requestCheck(Attempt $attempt, int $timeout): bool
    {
        if ($attempt->final || !in_array($attempt->state, [Attempt::OPEN, Attempt::UNCONFIRMED], true)) {
            return false;
        }

        return $this->check($attempt->id, Schedule::WEB_INTERVAL, $timeout);
    }

    private function failed(Attempt $attempt, int $lease, string $code, string $description, int $from): void
    {
        $now = Clock::now();
        $expiresAt = $attempt->expiresAt ?? $now;
        $this->repository->checkFailed($attempt->id, $lease, $code, $now, Schedule::nextCheckAt($expiresAt, $attempt->errors + 1, $from));
        // Logged when it starts failing, or fails otherwise: not at each of its retries.
        if ($code !== $attempt->error) {
            $message = 'The check of payment ' . $attempt->ref . ' failed: ' . $description;
            if (in_array($code, ['network', 'rate', Errors::TIMEOUT], true)) {
                Logger::warning($message, 'Cart', $attempt->idCart);
            } else {
                Logger::error($message, 'Cart', $attempt->idCart);
            }
        }
    }
}
