<?php

declare(strict_types=1);

/*
 * Gives every directory of the built module (its prefixed vendor/ included) the index.php that
 * keeps a web server from listing it, as PrestaShop's validator wants and its autoindex tool
 * writes it. Directories that have one keep theirs: tools/check-zip.php checks them all.
 *
 * Usage: php tools/index-files.php <directory>
 */

use Sattleme\PrestaShop\Tools\Headers;

require __DIR__ . '/Headers.php';

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "Usage: php tools/index-files.php <directory>\n");
    exit(2);
}

$directories = [$root];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);
foreach ($iterator as $file) {
    assert($file instanceof SplFileInfo);
    if ($file->isDir()) {
        $directories[] = $file->getPathname();
    }
}
$written = 0;
foreach ($directories as $directory) {
    $index = $directory . '/index.php';
    if (!is_file($index)) {
        file_put_contents($index, Headers::indexFile());
        ++$written;
    }
}
echo "index.php written in {$written} directories\n";
