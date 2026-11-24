<?php

declare(strict_types=1);

/*
 * The pinned copy of the PHP SDK (sattleme/checkout-php) in sdk/.
 *
 *   php tools/sdk.php check
 *       Whether sdk/ is its PIN.json's, file for file (tests/Unit/BuildTest.php checks it too).
 *
 *   php tools/sdk.php import <clone of sattleme/checkout-php> <commit>
 *       Replaces sdk/ with composer.json, LICENSE and src/ of the SDK at that commit, read from the
 *       clone's git objects (whatever the clone has checked out), writes sdk/PIN.json, then has
 *       the repository's pinned Composer (tools/Composer.php: 2.11.2, its SHA-256 checked, never
 *       the Composer on PATH) take the new copy into composer.lock (`update sattleme/checkout
 *       --no-install`). Then build the module and run the checks and the tests, as the CI does
 *       (README.md, "Development").
 */

use Sattleme\PrestaShop\Tools\Composer;
use Sattleme\PrestaShop\Tools\SdkPin;

require __DIR__ . '/Composer.php';
require __DIR__ . '/SdkPin.php';

$root = dirname(__DIR__);
$copy = $root . '/sdk';
$command = $argv[1] ?? '';

if ($command === 'check' && !isset($argv[2])) {
    $problems = SdkPin::check($copy);
    foreach ($problems as $problem) {
        fwrite(STDERR, "sdk/: {$problem}\n");
    }
    if ($problems !== []) {
        exit(1);
    }
    echo "sdk/ is the pinned copy of its PIN.json\n";
    exit(0);
}

if ($command === 'import' && isset($argv[2], $argv[3]) && !isset($argv[4])) {
    try {
        // The pinned Composer first: nothing is changed when it cannot run.
        Composer::phar($root);
        $result = SdkPin::import(rtrim($argv[2], '/'), $argv[3], $copy);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    printf("sdk/: %d files of %s %s, commit %s\n", $result['files'], SdkPin::REPOSITORY, $result['version'], $result['commit']);
    $problems = SdkPin::check($copy);
    if ($problems !== []) {
        fwrite(STDERR, 'sdk/: ' . implode("\nsdk/: ", $problems) . "\n");
        exit(1);
    }

    // composer.lock: the path package of sdk/ as the pinned Composer reads it now (its version,
    // its requirements, its reference). Nothing installed here, no script nor plugin run.
    try {
        $status = Composer::run($root, ['update', 'sattleme/checkout', '--no-install', '--no-interaction', '--no-plugins', '--no-scripts', '--no-progress']);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        $status = 1;
    }
    if ($status !== 0) {
        fwrite(STDERR, 'composer.lock was not updated by Composer ' . Composer::VERSION . ": run the import again once what is said above is fixed\n");
        exit(1);
    }
    echo "composer.lock takes sdk/ as it is now: build the module, then run the checks and the tests\n";
    exit(0);
}

fwrite(STDERR, "Usage: php tools/sdk.php check | import <clone of sattleme/checkout-php> <commit>\n");
exit(2);
