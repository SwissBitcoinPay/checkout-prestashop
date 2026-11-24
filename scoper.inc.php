<?php

declare(strict_types=1);

/*
 * PHP-Scoper's configuration (tools/build.sh): the module's runtime libraries, installed from
 * composer.lock without the development ones in build/composer/vendor, are prefixed with
 * Sattleme\PrestaShop\Vendor, so that another module embedding another version of one of them never
 * collides with this one (PORTING.md, "Packages that depend on an SDK"; checkout-php's
 * tools/prefix/build.sh tests the SDK prefixed so). Each PHP file gets the _PS_VERSION_ guard
 * PrestaShop's technical standards ask of every PHP file of a module (tools/Guard.php).
 */

use Isolated\Symfony\Component\Finder\Finder;
use Sattleme\PrestaShop\Tools\Guard;

require_once __DIR__ . '/tools/Guard.php';

$vendor = __DIR__ . '/build/composer/vendor';

return [
    'prefix' => 'Sattleme\PrestaShop\Vendor',
    'output-dir' => __DIR__ . '/build/scoped',
    'finders' => [
        Finder::create()
            ->files()
            ->in([
                $vendor . '/sattleme/checkout',
                $vendor . '/bacon/bacon-qr-code',
            ])
            ->path(['#^src/#', '#^LICENSE#'])
            ->name(['*.php', 'LICENSE']),
        // dasprid/enum but its EnumMap, the one class of it that calls serialize() and
        // unserialize(), which PrestaShop Addons forbids: bacon/bacon-qr-code, its one user here,
        // never uses it (tests/Unit/BuildTest.php checks both).
        Finder::create()
            ->files()
            ->in($vendor . '/dasprid/enum')
            ->path(['#^src/#', '#^LICENSE#'])
            ->name(['*.php', 'LICENSE'])
            ->notPath('#^src/EnumMap\.php$#'),
    ],
    'patchers' => [
        static fn (string $filePath, string $prefix, string $contents): string => str_ends_with($filePath, '.php') ? Guard::add($contents) : $contents,
    ],
];
