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

namespace Sattleme\PrestaShop\Cron;

use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\Checker;
use Sattleme\PrestaShop\Payment\Lock;
use Sattleme\PrestaShop\Payment\Orders;
use Sattleme\PrestaShop\Payment\Schedule;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Privacy\Shredder;
use Sattleme\PrestaShop\Sdk\Logger;
use Sattleme\PrestaShop\Wordings;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * One run of the background checks of a shop (the cron URL, every minute): the invoices whose
 * check is due, the longest due first, as many as fit in the run's time (each check bounded), at
 * most MAX_CHECKS; and the invoices still without final status 90 days after they were made, given
 * up on with a note on their order. One run at a time per shop: a run that finds another one going
 * returns at once.
 */
final class Runner
{
    /** A run starts no check that could end later than this after it started (seconds). */
    public const BUDGET = 45;

    /** Checks per run, at most. */
    public const MAX_CHECKS = 20;

    /** Attempts given up on per run, at most. */
    public const MAX_GIVE_UPS = 50;

    /** @var AttemptRepository */
    private $repository;

    /** @var Checker */
    private $checker;

    /** @var TranslatorInterface */
    private $translator;

    public function __construct(AttemptRepository $repository, Checker $checker, TranslatorInterface $translator)
    {
        $this->repository = $repository;
        $this->checker = $checker;
        $this->translator = $translator;
    }

    /**
     * @param callable(): float $monotonic seconds of a monotonic clock (tests)
     *
     * @phpstan-impure
     *
     * @return array{busy: bool, checked: int, skipped: int, due: int, abandoned: int}
     */
    public function run(int $idShop, ?callable $monotonic = null, int $budget = self::BUDGET): array
    {
        $monotonic = $monotonic ?? static fn (): float => hrtime(true) / 1e9;
        $result = Lock::ifFree('cron', $idShop, function () use ($idShop, $monotonic, $budget): array {
            $now = Clock::now();
            $abandoned = 0;
            foreach ($this->repository->stale($idShop, $now - Schedule::GIVE_UP_AFTER, self::MAX_GIVE_UPS) as $attempt) {
                $abandoned += $this->giveUp($attempt) ? 1 : 0;
            }
            $due = $this->repository->due($idShop, $now, self::MAX_CHECKS);
            $started = $monotonic();
            $checked = 0;
            $skipped = 0;
            foreach ($due as $attempt) {
                if ($monotonic() - $started + Checker::CRON_TIMEOUT > $budget) {
                    break; // The next run checks the others first: they are due the longest.
                }
                // Its next check counts from this run's start ($now): due at the next run.
                if ($this->checker->check($attempt->id, 0, Checker::CRON_TIMEOUT, $now)) {
                    ++$checked;
                } else {
                    ++$skipped;
                }
            }
            Config::setLastCronRun(Clock::now(), $idShop);

            return ['busy' => false, 'checked' => $checked, 'skipped' => $skipped, 'due' => count($due), 'abandoned' => $abandoned];
        });

        return $result ?? ['busy' => true, 'checked' => 0, 'skipped' => 0, 'due' => 0, 'abandoned' => 0];
    }

    /** Stops checking an invoice with no final status after 90 days: noted on its order. */
    private function giveUp(Attempt $attempt): bool
    {
        return (bool) Lock::with('cart', $attempt->idCart, Checker::LOCK_WAIT, function () use ($attempt): bool {
            $current = $this->repository->find($attempt->id);
            if ($current === null || $current->final) {
                return false;
            }
            $this->repository->close($current->id, Attempt::ABANDONED, 'abandoned');
            $orders = Orders::forCart($current->idCart);
            if ($orders !== [] && !$current->hasNote('abandoned')) {
                Orders::note($orders, Wordings::trans(
                    $this->translator,
                    'The bitcoin invoice of this order had no final status after %days% days (an on-chain payment still being confirmed?): its checks stopped. Check this payment in the sattle.me app, and settle the order by hand. Reference: %ref%.',
                    'Modules.Sattleme.Admin',
                    Platform::defaultLocale(),
                    ['%days%' => (string) intdiv(Schedule::GIVE_UP_AFTER, 86400), '%ref%' => $current->ref],
                ));
                $this->repository->addNote($current->id, 'abandoned');
            }
            Logger::warning('Payment ' . $current->ref . ': no final status after 90 days, checks stopped', 'Cart', $current->idCart);
            $closed = $this->repository->find($current->id);
            if ($closed !== null) {
                (new Shredder($this->repository))->finished($closed);
            }

            return true;
        });
    }
}
