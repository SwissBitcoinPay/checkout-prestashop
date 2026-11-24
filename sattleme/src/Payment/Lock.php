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
 * A named lock of the database server (MySQL's and MariaDB's GET_LOCK): one holder at a time,
 * across PHP workers, across web servers sharing the database, released when its holder ends,
 * whatever way it ends (a PHP process stopped mid-call loses its connection, and the lock with it).
 *
 * The payment of a cart (its attempts, its invoice's making, the orders made from it and their
 * completion) happens under the lock of the cart: a double click, two workers, the payment page
 * and the background checks never make two invoices nor complete an order twice.
 */
final class Lock
{
    private function __construct()
    {
    }

    /**
     * Runs $work holding the lock of $what $id, waiting $seconds at most for it.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws LockTimeoutException when another holder kept it longer
     */
    public static function with(string $what, int $id, int $seconds, callable $work): mixed
    {
        $name = self::name($what, $id);
        if (!self::acquire($name, $seconds)) {
            throw new LockTimeoutException("The {$what} {$id} is being processed by another request");
        }
        try {
            return $work();
        } finally {
            Database::value('SELECT RELEASE_LOCK(' . Database::quote($name) . ')');
        }
    }

    /**
     * Runs $work only if the lock of $what $id is free right now: null when it is not.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T|null
     */
    public static function ifFree(string $what, int $id, callable $work): mixed
    {
        try {
            return self::with($what, $id, 0, $work);
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /**
     * The lock's name: the database server's locks are of the whole server, so it names this
     * shop's database and tables too; 64 characters at most.
     */
    public static function name(string $what, int $id): string
    {
        $shop = substr(hash('sha256', _DB_SERVER_ . '|' . _DB_NAME_ . '|' . _DB_PREFIX_), 0, 16);

        return 'sattleme:' . $shop . ':' . $what . ':' . $id;
    }

    private static function acquire(string $name, int $seconds): bool
    {
        return Database::value('SELECT GET_LOCK(' . Database::quote($name) . ', ' . max(0, $seconds) . ')') === '1';
    }
}
