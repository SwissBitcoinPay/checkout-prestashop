<?php

declare(strict_types=1);

/*
 * The merchant installs the module from its ZIP, as PrestaShop's back office does it: Modules >
 * Module Manager > Upload a module, which installs the module it uploaded (tests/e2e/run.sh).
 *
 * Usage (HttpShop's environment set): php tests/e2e/install.php <the module's ZIP>
 */

use Sattleme\PrestaShop\Tests\Support\HttpShop;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$zip = $argv[1] ?? '';
if (!is_file($zip)) {
    fwrite(STDERR, "Usage: php tests/e2e/install.php <the module's ZIP>\n");
    exit(2);
}
$merchant = HttpShop::merchant();
// The page of the Module Manager first, as the merchant opens it.
$manager = $merchant->browser->get($merchant->url('improve/modules/manage'));
if ($manager->status !== 200) {
    fwrite(STDERR, "The Module Manager answered {$manager->status}\n");
    exit(1);
}
$answer = $merchant->browser->post(
    $merchant->url('improve/modules/import'),
    [],
    ['file_uploaded' => new CURLFile($zip, 'application/zip', basename($zip))],
);
$result = json_decode($answer->body, true);
if ($answer->status !== 200 || !is_array($result) || ($result['status'] ?? null) !== true || ($result['module_name'] ?? null) !== 'sattleme') {
    fwrite(STDERR, "The upload of the module failed ({$answer->status}): " . substr($answer->body, 0, 2000) . "\n");
    exit(1);
}
echo 'Module Manager: ' . (is_string($result['msg'] ?? null) ? strip_tags($result['msg']) : 'installed') . "\n";
