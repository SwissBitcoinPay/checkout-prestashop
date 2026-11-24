<?php

declare(strict_types=1);

/*
 * One "Place order" of a cart, in a process of its own (ConcurrencyTest): it waits for the given
 * moment so that two of them start at once, then prints the order's id as JSON.
 *
 * Usage (the shop's environment set): php tests/Integration/bin/checkout.php <id_cart> <start, Unix time>
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

$idCart = $argv[1] ?? '0';
$start = $argv[2] ?? '0';
$cart = new Cart((int) $idCart);
$customer = new Customer((int) $cart->id_customer);
$context = Sattleme\PrestaShop\Platform::context();
$context->currency = new Currency((int) $cart->id_currency);
$context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
$context->cart = $cart;
$context->customer = $customer;
$module = Module::getInstanceByName('sattleme');
if (!$module instanceof Sattleme) {
    exit(1);
}
if ((float) $start > microtime(true)) {
    time_sleep_until((float) $start);
}
$started = microtime(true);
try {
    $order = $module->creator()->checkout($cart, $customer);
    echo json_encode(['order' => $order, 'started' => $started, 'ended' => microtime(true)]), "\n";
} catch (Throwable $error) {
    echo json_encode(['error' => get_class($error) . ': ' . $error->getMessage()]), "\n";
    exit(1);
}
