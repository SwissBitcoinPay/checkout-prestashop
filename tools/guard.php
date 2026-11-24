<?php

declare(strict_types=1);

/*
 * Gives PHP files the _PS_VERSION_ guard (tools/Guard.php), in place: Composer's autoloader files of
 * the built module, which Composer writes after PHP-Scoper gave the embedded libraries theirs
 * (scoper.inc.php). tools/check-zip.php checks every PHP file of the ZIP for it.
 *
 * Usage: php tools/guard.php <file.php>...
 */

use Sattleme\PrestaShop\Tools\Guard;

require __DIR__ . '/Guard.php';

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "Usage: php tools/guard.php <file.php>...\n");
    exit(2);
}
foreach ($files as $file) {
    $code = file_get_contents($file);
    if (!is_string($code)) {
        fwrite(STDERR, "{$file}: not readable\n");
        exit(1);
    }
    try {
        $guarded = Guard::add($code);
    } catch (UnexpectedValueException $e) {
        fwrite(STDERR, "{$file}: {$e->getMessage()}\n");
        exit(1);
    }
    if ($guarded !== $code && file_put_contents($file, $guarded) === false) {
        fwrite(STDERR, "{$file}: not writable\n");
        exit(1);
    }
}
