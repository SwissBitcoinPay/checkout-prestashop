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

namespace Sattleme\PrestaShop;

use Db;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PrestaShop's database (Db), as the module reads it: never through PrestaShop's query cache (a
 * payment's record changes within a request, and between the processes that check it), its values
 * typed where they are read (PDO gives integer columns as integers, others as strings). Every value
 * written is escaped by its caller with quote(), or is an integer.
 */
final class Database
{
    private function __construct()
    {
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $sql): array
    {
        $result = \Db::getInstance()->executeS($sql, true, false);
        $rows = [];
        foreach (is_array($result) ? $result : [] as $row) {
            if (is_array($row)) {
                $rows[] = self::stringKeys($row);
            }
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public static function row(string $sql): ?array
    {
        $row = \Db::getInstance()->getRow($sql, false);

        return is_array($row) ? self::stringKeys($row) : null;
    }

    /**
     * The first column of the first row, as a string, or null when there is none (or it is NULL).
     * PHP's PDO gives integer columns as integers: they are read as strings here.
     */
    public static function value(string $sql): ?string
    {
        $row = self::row($sql);
        $value = $row === null ? null : reset($row);

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    public static function execute(string $sql): bool
    {
        return \Db::getInstance()->execute($sql, false);
    }

    /** The number of rows the last statement changed. */
    public static function affectedRows(): int
    {
        return \Db::getInstance()->Affected_Rows();
    }

    public static function lastInsertId(): int
    {
        return (int) \Db::getInstance()->Insert_ID();
    }

    /** A string as an SQL literal: quoted, escaped (HTML kept as it is). */
    public static function quote(string $value): string
    {
        return "'" . pSQL($value, true) . "'";
    }

    /** A column's value as an integer: 0 when it is none. */
    public static function int(mixed $value): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : 0;
    }

    /** A column's value as an integer, or null when it is NULL or empty. */
    public static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }

    /** A column's value as a string: '' when it is NULL. */
    public static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * @param array<mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $row): array
    {
        $typed = [];
        foreach ($row as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }
}
