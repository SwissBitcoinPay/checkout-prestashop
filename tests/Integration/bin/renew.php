<?php

declare(strict_types=1);

/*
 * A new invoice for a cart's orders (Creator::renew: the payment page's "Get a new invoice"), in a
 * process of its own (ConcurrencyTest): it prints "started", waits for the given moment if any,
 * then prints the attempt's state as JSON.
 *
 * Usage (the shop's environment set): php tests/Integration/bin/renew.php <id_cart> [<start, Unix time>]
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

$idCart = $argv[1] ?? '0';
$start = $argv[2] ?? '0';
$module = Module::getInstanceByName('sattleme');
if (!$module instanceof Sattleme) {
    exit(1);
}
echo "started\n";
if ((float) $start > microtime(true)) {
    time_sleep_until((float) $start);
}
try {
    $attempt = $module->creator()->renew((int) $idCart);
    echo json_encode(['state' => $attempt?->state]), "\n";
} catch (Throwable $error) {
    echo json_encode(['error' => get_class($error) . ': ' . $error->getMessage()]), "\n";
    exit(1);
}
