<?php

declare(strict_types=1);

/*
 * Holds a lock (of a cart, of a shop's background checks) for some seconds, in a process of its own
 * (ConcurrencyTest, CronTest), then exits: its database connection, and the lock, end with it.
 *
 * Usage (the shop's environment set): php tests/Integration/bin/hold-lock.php <cart|cron> <id> <seconds>
 */

use Sattleme\PrestaShop\Payment\Lock;

require dirname(__DIR__, 2) . '/bootstrap.php';

$what = $argv[1] ?? 'cart';
$id = $argv[2] ?? '0';
$seconds = $argv[3] ?? '0';
Lock::with($what, (int) $id, 5, static function () use ($seconds): void {
    echo "held\n";
    sleep((int) $seconds);
});
