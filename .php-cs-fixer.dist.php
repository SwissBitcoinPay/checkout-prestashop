<?php

declare(strict_types=1);

// PrestaShop's coding standard (prestashop/php-dev-tools), on the module, its tests and its tools.
$config = new PrestaShop\CodingStandards\CsFixer\Config();

/** @var Symfony\Component\Finder\Finder $finder */
$finder = $config->setUsingCache(true)->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')->getFinder();
$finder->in([__DIR__ . '/sattleme', __DIR__ . '/tests', __DIR__ . '/tools'])
    ->exclude(['vendor'])
    ->append([__FILE__, __DIR__ . '/scoper.inc.php']);

return $config;
