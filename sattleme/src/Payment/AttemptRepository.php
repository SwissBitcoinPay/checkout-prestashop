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
 * The payment attempts, in the table sattleme_payment (one row per attempt, its reference unique).
 * Every value written is escaped here (PrestaShop's Db::insert and Db::update escape nothing);
 * reads skip PrestaShop's query cache.
 */
final class AttemptRepository
{
    public const TABLE = 'sattleme_payment';

    /** How the JSON of an invoice and of a status is stored. */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function table(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    /** The table, created at installation (and kept at uninstallation: see Installer). */
    public static function createTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
            `id_sattleme_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_cart` INT UNSIGNED NOT NULL,
            `id_order` INT UNSIGNED NULL,
            `order_reference` VARCHAR(255) NOT NULL,
            `ref` VARCHAR(64) NOT NULL,
            `amount` VARCHAR(32) NOT NULL,
            `currency` CHAR(3) NOT NULL,
            `state` VARCHAR(16) NOT NULL,
            `final` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `origin` VARCHAR(8) NOT NULL DEFAULT \'created\',
            `invoice` MEDIUMTEXT NULL,
            `status` MEDIUMTEXT NULL,
            `expires_at` INT UNSIGNED NULL,
            `created_at` INT UNSIGNED NOT NULL,
            `checked_at` INT UNSIGNED NULL,
            `next_check_at` INT UNSIGNED NOT NULL DEFAULT 0,
            `lease_until` INT UNSIGNED NULL,
            `error` VARCHAR(64) NOT NULL DEFAULT \'\',
            `errors` INT UNSIGNED NOT NULL DEFAULT 0,
            `completed_at` INT UNSIGNED NULL,
            `notes` VARCHAR(255) NOT NULL DEFAULT \'\',
            `shredded` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `canceling_until` INT UNSIGNED NULL,
            PRIMARY KEY (`id_sattleme_payment`),
            UNIQUE KEY `sattleme_ref` (`ref`),
            KEY `sattleme_cart` (`id_cart`),
            KEY `sattleme_order` (`id_order`),
            KEY `sattleme_due` (`id_shop`, `final`, `next_check_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /** A new attempt, stored before its invoice is asked for. */
    public function create(int $idShop, int $idCart, ?int $idOrder, string $orderReference, string $ref, string $amount, string $currency, int $now): Attempt
    {
        if (preg_match(Reference::PATTERN, $ref) !== 1 || preg_match('/^[^\x00-\x1F\x7F]{1,255}$/Du', $orderReference) !== 1
            || !Amount::isDecimal($amount) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \InvalidArgumentException('Invalid attempt');
        }
        $ok = Database::execute('INSERT INTO `' . self::table() . '`
            (`id_shop`, `id_cart`, `id_order`, `order_reference`, `ref`, `amount`, `currency`, `state`, `created_at`, `next_check_at`)
            VALUES (' . $idShop . ', ' . $idCart . ', ' . ($idOrder === null ? 'NULL' : (string) $idOrder) . ', '
            . self::quote($orderReference) . ', ' . self::quote($ref) . ', ' . self::quote($amount) . ', ' . self::quote($currency) . ', '
            . self::quote(Attempt::NEW) . ', ' . $now . ', 0)');
        $id = Database::lastInsertId();
        $attempt = $ok ? $this->find($id) : null;
        if ($attempt === null) {
            throw new \RuntimeException('The payment attempt could not be stored');
        }

        return $attempt;
    }

    public function find(int $id): ?Attempt
    {
        $row = Database::row('SELECT * FROM `' . self::table() . '` WHERE `id_sattleme_payment` = ' . $id);

        return $row === null ? null : Attempt::fromRow($row);
    }

    /** The latest attempt of a cart, or null. */
    public function latestForCart(int $idCart): ?Attempt
    {
        $row = Database::row('SELECT * FROM `' . self::table() . '` WHERE `id_cart` = ' . $idCart
            . ' ORDER BY `id_sattleme_payment` DESC');

        return $row === null ? null : Attempt::fromRow($row);
    }

    /** @return list<Attempt> the attempts of a cart, the latest first */
    public function allForCart(int $idCart): array
    {
        return $this->select('`id_cart` = ' . $idCart . ' ORDER BY `id_sattleme_payment` DESC');
    }

    /**
     * The attempts whose background check is due, the longest due first.
     *
     * @return list<Attempt>
     */
    public function due(int $idShop, int $now, int $limit): array
    {
        return $this->select('`id_shop` = ' . $idShop . ' AND `final` = 0 AND `state` IN (' . self::quote(Attempt::OPEN) . ', '
            . self::quote(Attempt::UNCONFIRMED) . ') AND `next_check_at` <= ' . $now
            . ' ORDER BY `next_check_at` ASC, `id_sattleme_payment` ASC LIMIT ' . max(1, $limit));
    }

    /**
     * Attempts with an invoice, not final, made before $before: the ones given up on.
     *
     * @return list<Attempt>
     */
    public function stale(int $idShop, int $before, int $limit): array
    {
        return $this->select('`id_shop` = ' . $idShop . ' AND `final` = 0 AND `state` IN (' . self::quote(Attempt::OPEN) . ', '
            . self::quote(Attempt::UNCONFIRMED) . ') AND `created_at` < ' . $before . ' ORDER BY `id_sattleme_payment` ASC LIMIT ' . max(1, $limit));
    }

    /**
     * The attempts of the carts of these orders' customer: privacy (export, erasure).
     *
     * @param list<int> $idCarts
     *
     * @return list<Attempt>
     */
    public function forCarts(array $idCarts): array
    {
        $ids = array_values(array_unique(array_map('intval', $idCarts)));
        if ($ids === []) {
            return [];
        }

        return $this->select('`id_cart` IN (' . implode(', ', $ids) . ') ORDER BY `id_sattleme_payment` ASC');
    }

    /** @return array{pending: int, final: int} counts of a shop's attempts with an invoice */
    public function counts(int $idShop): array
    {
        $rows = Database::rows('SELECT `final`, COUNT(*) AS `n` FROM `' . self::table() . '` WHERE `id_shop` = ' . $idShop
            . ' AND `state` <> ' . self::quote(Attempt::NEW) . ' GROUP BY `final`');
        $counts = ['pending' => 0, 'final' => 0];
        foreach ($rows as $row) {
            $counts[Database::int($row['final'] ?? null) === 1 ? 'final' : 'pending'] = Database::int($row['n'] ?? null);
        }

        return $counts;
    }

    /**
     * The invoice the SDK gave: the attempt is open, its first check due at once.
     *
     * @param array<string, mixed> $invoice
     */
    public function storeInvoice(int $id, array $invoice, string $origin, int $now): void
    {
        $expiresAt = $invoice['expiresAt'] ?? null;
        if (!is_int($expiresAt)) {
            throw new \InvalidArgumentException('An invoice without expiresAt');
        }
        $this->update($id, [
            'state' => self::quote(Attempt::OPEN),
            'origin' => self::quote($origin),
            'invoice' => self::quote(json_encode($invoice, self::JSON_FLAGS)),
            'expires_at' => (string) $expiresAt,
            'next_check_at' => (string) $now,
            'error' => "''",
            'errors' => '0',
        ]);
    }

    /** A call that made no invoice and may succeed later: the attempt stays new. */
    public function creationFailed(int $id, string $code): void
    {
        $this->update($id, ['error' => self::quote(self::code($code)), 'errors' => '`errors` + 1']);
    }

    /** Final, never paid: failed (the SDK refused the order) or abandoned. */
    public function close(int $id, string $state, string $code): void
    {
        if (!in_array($state, [Attempt::FAILED, Attempt::ABANDONED, Attempt::EXPIRED], true)) {
            throw new \InvalidArgumentException('Not a closing state');
        }
        $this->update($id, ['state' => self::quote($state), 'final' => '1', 'error' => self::quote(self::code($code)), 'lease_until' => 'NULL']);
    }

    /** The orders made from the attempt's cart: the first one names them. */
    public function linkOrder(int $id, int $idOrder): void
    {
        $this->update($id, ['id_order' => (string) $idOrder]);
    }

    /**
     * Claims an attempt for a check: true when this check holds it until $leaseUntil. Never one
     * that is final, held by another check, or checked less than $minInterval seconds ago.
     */
    public function claim(int $id, int $now, int $leaseUntil, int $minInterval): bool
    {
        $sql = 'UPDATE `' . self::table() . '` SET `lease_until` = ' . $leaseUntil
            . ' WHERE `id_sattleme_payment` = ' . $id . ' AND `final` = 0 AND `state` IN (' . self::quote(Attempt::OPEN) . ', '
            . self::quote(Attempt::UNCONFIRMED) . ') AND (`lease_until` IS NULL OR `lease_until` < ' . $now . ')';
        if ($minInterval > 0) {
            $sql .= ' AND (`checked_at` IS NULL OR `checked_at` <= ' . ($now - $minInterval) . ')';
        }

        return Database::execute($sql) && Database::affectedRows() === 1;
    }

    /** Whether $leaseUntil is still the attempt's lease (a check that outlived it writes nothing). */
    public function holdsLease(int $id, int $leaseUntil): bool
    {
        $value = Database::value('SELECT `lease_until` FROM `' . self::table() . '` WHERE `id_sattleme_payment` = ' . $id);

        return $value !== null && Database::intOrNull($value) === $leaseUntil;
    }

    /** A check that failed: counted, the lease released, the next check scheduled. */
    public function checkFailed(int $id, int $leaseUntil, string $code, int $now, int $nextCheckAt): void
    {
        Database::execute('UPDATE `' . self::table() . '` SET `error` = ' . self::quote(self::code($code))
            . ', `errors` = `errors` + 1, `checked_at` = ' . $now . ', `next_check_at` = ' . $nextCheckAt . ', `lease_until` = NULL'
            . ' WHERE `id_sattleme_payment` = ' . $id . ' AND `lease_until` = ' . $leaseUntil);
    }

    /**
     * What a check found: the status, the attempt's state from it, and when it is checked next.
     *
     * @param array<string, mixed> $status
     */
    public function storeStatus(int $id, array $status, string $state, int $now, int $nextCheckAt): void
    {
        $this->update($id, [
            'status' => self::quote(json_encode($status, self::JSON_FLAGS)),
            'state' => self::quote($state),
            'checked_at' => (string) $now,
            'next_check_at' => (string) $nextCheckAt,
            'error' => "''",
            'errors' => '0',
        ]);
    }

    /**
     * Final (paid or expired), once its orders are updated to match: until then the attempt keeps
     * its state, and the next check applies its status again.
     */
    public function finish(int $id, string $state, bool $completed, int $now): void
    {
        if (!in_array($state, [Attempt::PAID, Attempt::EXPIRED], true)) {
            throw new \InvalidArgumentException('Not a final state of a check');
        }
        $fields = ['state' => self::quote($state), 'final' => '1', 'lease_until' => 'NULL'];
        if ($completed) {
            $fields['completed_at'] = (string) $now;
        }
        $this->update($id, $fields);
    }

    /** Releases a check's lease, if it is still the attempt's. */
    public function release(int $id, int $leaseUntil): void
    {
        Database::execute('UPDATE `' . self::table() . '` SET `lease_until` = NULL WHERE `id_sattleme_payment` = ' . $id
            . ' AND `lease_until` = ' . $leaseUntil);
    }

    /** Applying a status failed (PrestaShop could not take it): counted, tried again at the next check. */
    public function applyFailed(int $id, int $now, int $nextCheckAt): void
    {
        $this->update($id, ['error' => self::quote('apply'), 'errors' => '`errors` + 1', 'checked_at' => (string) $now, 'next_check_at' => (string) $nextCheckAt]);
    }

    /**
     * Marks the cart as being canceled for $seconds (a cancellation of its order let through, its
     * state not written yet), by the database server's clock: one clock for every web server.
     */
    public function markCanceling(int $idCart, int $seconds): void
    {
        Database::execute('UPDATE `' . self::table() . '` SET `canceling_until` = UNIX_TIMESTAMP() + ' . max(0, $seconds)
            . ' WHERE `id_cart` = ' . $idCart);
    }

    /** Whether the cart is marked as being canceled (markCanceling), now. */
    public function isCanceling(int $idCart): bool
    {
        return Database::int(Database::value('SELECT COUNT(*) FROM `' . self::table() . '` WHERE `id_cart` = ' . $idCart
            . ' AND `canceling_until` > UNIX_TIMESTAMP()')) > 0;
    }

    public function addNote(int $id, string $note): void
    {
        if (preg_match('/^[a-z_]{1,32}$/D', $note) !== 1) {
            throw new \InvalidArgumentException('Invalid note');
        }
        Database::execute('UPDATE `' . self::table() . '` SET `notes` = IF(`notes` = \'\', ' . self::quote($note)
            . ', CONCAT(`notes`, \',\', ' . self::quote($note) . ')) WHERE `id_sattleme_payment` = ' . $id
            . ' AND FIND_IN_SET(' . self::quote($note) . ', `notes`) = 0');
    }

    /**
     * What leads to the customer's coins removed (privacy): the address and the transactions.
     *
     * @param array<string, mixed>|null $invoice
     * @param array<string, mixed>|null $status
     */
    public function shred(int $id, ?array $invoice, ?array $status, bool $done): void
    {
        $fields = ['shredded' => '1'];
        if ($done) {
            $fields['invoice'] = $invoice === null ? 'NULL' : self::quote(json_encode($invoice, self::JSON_FLAGS));
            $fields['status'] = $status === null ? 'NULL' : self::quote(json_encode($status, self::JSON_FLAGS));
        }
        $this->update($id, $fields);
    }

    /** @param array<string, string> $fields column => SQL value, already escaped */
    private function update(int $id, array $fields): void
    {
        $set = [];
        foreach ($fields as $column => $value) {
            $set[] = '`' . $column . '` = ' . $value;
        }
        if (!Database::execute('UPDATE `' . self::table() . '` SET ' . implode(', ', $set) . ' WHERE `id_sattleme_payment` = ' . $id)) {
            throw new \RuntimeException('The payment attempt could not be updated');
        }
    }

    /** @return list<Attempt> */
    private function select(string $where): array
    {
        return array_map(static fn (array $row): Attempt => Attempt::fromRow($row), Database::rows('SELECT * FROM `' . self::table() . '` WHERE ' . $where));
    }

    private static function quote(string $value): string
    {
        return Database::quote($value);
    }

    private static function code(string $code): string
    {
        return substr(preg_replace('/[^a-z_]/', '', $code) ?? '', 0, 64);
    }
}
