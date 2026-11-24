<?php

declare(strict_types=1);

/*
 * Checks an attempt's payment as the background checks do (Checker::check), in a process of its
 * own (ConcurrencyTest), in the context of a request to the shop (its default currency, country,
 * language: PrestaShop records a payment in it): it prints whether it was checked, and the
 * attempt's state and error after, as JSON.
 *
 * Usage (the shop's environment set): php tests/Integration/bin/check.php <id_sattleme_payment>
 */

use Sattleme\PrestaShop\Payment\AttemptRepository;

require dirname(__DIR__, 2) . '/bootstrap.php';

$id = (int) ($argv[1] ?? '0');
$context = Sattleme\PrestaShop\Platform::context();
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
$context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$module = Module::getInstanceByName('sattleme');
if (!$module instanceof Sattleme) {
    exit(1);
}
$checked = $module->checker()->check($id, 0, 5);
$attempt = (new AttemptRepository())->find($id);
echo json_encode(['checked' => $checked, 'state' => $attempt?->state, 'error' => $attempt?->error]), "\n";
