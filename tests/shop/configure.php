<?php

declare(strict_types=1);

/*
 * The test shop's settings (tests/shop/shop.sh): no e-mail sent (PrestaShop's "never send e-mails"),
 * the rest as PrestaShop installs it.
 *
 * Usage: php tests/shop/configure.php <shop directory>
 */

$shop = $argv[1] ?? '';
if (!is_file($shop . '/config/config.inc.php')) {
    fwrite(STDERR, "Usage: php tests/shop/configure.php <shop directory>\n");
    exit(2);
}
$_SERVER['REQUEST_METHOD'] = 'GET';
require $shop . '/config/config.inc.php';

Configuration::updateGlobalValue('PS_MAIL_METHOD', 3);
echo "Configured\n";
