<?php

declare(strict_types=1);

/*
 * Cancels an order as PrestaShop's back office does (OrderHistory: its state "Canceled"), in a
 * process of its own (ConcurrencyTest): it prints the order's state after as JSON, or the refusal.
 *
 * Usage (the shop's environment set): php tests/Integration/bin/cancel.php <id_order>
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

$idOrder = (int) ($argv[1] ?? '0');
$history = new OrderHistory();
$history->id_order = $idOrder;
try {
    $history->changeIdOrderState((int) Configuration::get('PS_OS_CANCELED'), $idOrder);
    $history->add();
} catch (Throwable $error) {
    echo json_encode(['refused' => get_class($error) . ': ' . $error->getMessage()]), "\n";
    exit(0);
}
// Read from the database: PrestaShop's object cache keeps the order as it was loaded.
echo json_encode(['state' => (int) Db::getInstance()->getValue('SELECT `current_state` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . $idOrder, false)]), "\n";
