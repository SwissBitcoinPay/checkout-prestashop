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

use Sattleme\PrestaShop\Database;
use Sattleme\PrestaShop\Payment\Attempt;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Payment\Lock;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PrestaShop's GDPR module (psgdpr) asks each module for a customer's personal data
 * (actionExportGDPRData) and has each erase it (actionDeleteGDPRCustomer), given the customer's
 * fields, or only an email address or a phone number. The module's: the bitcoin payments of the
 * customer's carts.
 */
final class Gdpr
{
    /** @var AttemptRepository */
    private $repository;

    public function __construct(AttemptRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * The customer's payments, as psgdpr exports them.
     *
     * @param array<string, mixed> $customer
     *
     * @return list<array<string, mixed>>
     */
    public function export(array $customer): array
    {
        $rows = [];
        foreach ($this->repository->forCarts($this->carts($customer)) as $attempt) {
            if ($attempt->invoice === null) {
                continue;
            }
            $rows[] = [
                'reference' => $attempt->ref,
                'order_reference' => $attempt->orderReference,
                'date' => date('Y-m-d H:i:s', $attempt->createdAt),
                'amount' => $attempt->amount . ' ' . $attempt->currency,
                'sats' => $attempt->sats(),
                'state' => $attempt->state,
                'bitcoin_address' => $attempt->onchainAddress(),
                'transactions' => implode(' ', $attempt->txids()),
                'received_sats' => $attempt->received(),
            ];
        }

        return $rows;
    }

    /**
     * Erases what leads to the customer's coins (Shredder): the number of payments marked.
     *
     * @param array<string, mixed> $customer
     */
    public function erase(array $customer): int
    {
        $shredder = new Shredder($this->repository);
        $count = 0;
        foreach ($this->repository->forCarts($this->carts($customer)) as $attempt) {
            Lock::with('cart', $attempt->idCart, 30, function () use ($shredder, $attempt): void {
                $current = $this->repository->find($attempt->id);
                if ($current instanceof Attempt) {
                    $shredder->shred($current);
                }
            });
            ++$count;
        }

        return $count;
    }

    /**
     * The carts of the customer: by its id, its email address or its phone number (psgdpr gives
     * one of them), through its customer accounts (a guest's included) and their addresses.
     *
     * @param array<string, mixed> $customer
     *
     * @return list<int>
     */
    private function carts(array $customer): array
    {
        $ids = [];
        $id = $customer['id'] ?? null;
        if ((is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id) === 1) {
            $ids[] = (int) $id;
        }
        $email = $customer['email'] ?? null;
        if (is_string($email) && \Validate::isEmail($email)) {
            foreach (Database::rows('SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer` WHERE `email` = ' . Database::quote($email)) as $row) {
                $ids[] = Database::int($row['id_customer'] ?? null);
            }
        }
        $phone = $customer['phone'] ?? null;
        if (is_string($phone) && trim($phone) !== '' && \Validate::isPhoneNumber($phone)) {
            foreach (Database::rows('SELECT DISTINCT `id_customer` FROM `' . _DB_PREFIX_ . 'address` WHERE `id_customer` > 0 AND (`phone` = '
                . Database::quote(trim($phone)) . ' OR `phone_mobile` = ' . Database::quote(trim($phone)) . ')') as $row) {
                $ids[] = Database::int($row['id_customer'] ?? null);
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(', ', $ids);
        $rows = Database::rows('SELECT `id_cart` FROM `' . _DB_PREFIX_ . 'cart` WHERE `id_customer` IN (' . $in . ')
            UNION SELECT `id_cart` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_customer` IN (' . $in . ')');

        return array_map(static fn (array $row): int => Database::int($row['id_cart'] ?? null), $rows);
    }
}
