<?php

declare(strict_types=1);

/*
 * The tests run the module as its ZIP holds it (build/sattleme.zip, tools/build.sh):
 *
 * - unit tests (no PrestaShop, no database): the ZIP's classes and prefixed libraries, extracted
 *   into build/test-module/;
 * - shop tests (SATTLEME_SHOP_ROOT set, tests/shop/shop.sh): PrestaShop itself, booted as its
 *   console does, with the module the shop installed from the ZIP.
 */

use Sattleme\PrestaShop\Tests\Support\BuiltModule;

require dirname(__DIR__) . '/vendor/autoload.php';

$shop = getenv('SATTLEME_SHOP_ROOT');
if (is_string($shop) && $shop !== '') {
    if (!is_file($shop . '/config/config.inc.php')) {
        fwrite(STDERR, "SATTLEME_SHOP_ROOT names no PrestaShop: run tests/shop/shop.sh setup first\n");
        exit(1);
    }
    // PrestaShop's console boots so: no request, the default shop, its default language.
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = parse_url((string) getenv('SATTLEME_SHOP_URL'), PHP_URL_HOST) . ':' . parse_url((string) getenv('SATTLEME_SHOP_URL'), PHP_URL_PORT);
    require $shop . '/config/config.inc.php';
    if (!Module::getInstanceByName('sattleme') instanceof Sattleme) {
        fwrite(STDERR, "The shop has no sattle.me module installed\n");
        exit(1);
    }
    // PrestaShop's Symfony kernel, as its console boots it: its legacy classes find their services
    // in it (Cart, Order…).
    global $kernel;
    $kernel = new AdminKernel('prod', false);
    $kernel->boot();
    Sattleme\PrestaShop\Platform::translator();
} else {
    define('_PS_VERSION_', '9.2.0');
    require BuiltModule::extract() . '/vendor/autoload.php';
}
