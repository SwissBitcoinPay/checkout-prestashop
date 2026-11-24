<?php

declare(strict_types=1);

/*
 * Extracts a ZIP for the test shop (tests/shop/shop.sh), refusing any entry outside the target.
 *
 *   php tests/shop/extract.php <PrestaShop release ZIP> <directory>
 *       the shop's files: the release's inner prestashop.zip, or its prestashop/ directory
 *   php tests/shop/extract.php <ZIP> <directory> --as-is
 *       the ZIP's entries as they are (the module's ZIP holds sattleme/)
 */

$zipPath = $argv[1] ?? '';
$target = $argv[2] ?? '';
$asIs = ($argv[3] ?? '') === '--as-is';
if (!is_file($zipPath) || $target === '') {
    fwrite(STDERR, "Usage: php tests/shop/extract.php <zip> <directory> [--as-is]\n");
    exit(2);
}

/** Extracts the entries of $zip whose names start with $prefix into $target, without the prefix. */
function extract_entries(ZipArchive $zip, string $prefix, string $target): int
{
    if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
        throw new RuntimeException("Cannot create {$target}");
    }
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $name = (string) $zip->getNameIndex($i);
        if ($prefix !== '' && !str_starts_with($name, $prefix)) {
            continue;
        }
        $relative = substr($name, strlen($prefix));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')
            || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
            if ($relative !== '') {
                throw new RuntimeException("Unsafe entry {$name}");
            }
            continue;
        }
        $path = $target . '/' . $relative;
        if (str_ends_with($name, '/')) {
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
            continue;
        }
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new RuntimeException("Cannot read {$name}");
        }
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new RuntimeException("Cannot write {$path}");
        }
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
        ++$count;
    }

    return $count;
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
    fwrite(STDERR, "Cannot open {$zipPath}\n");
    exit(1);
}
if ($asIs) {
    $count = extract_entries($zip, '', $target);
} elseif ($zip->locateName('prestashop.zip') !== false) {
    $inner = tempnam(dirname($target), 'prestashop-');
    $in = $zip->getStream('prestashop.zip');
    $out = $inner === false ? false : fopen($inner, 'wb');
    if ($inner === false || $in === false || $out === false || stream_copy_to_stream($in, $out) === false) {
        fwrite(STDERR, "Cannot extract prestashop.zip\n");
        exit(1);
    }
    fclose($in);
    fclose($out);
    $shop = new ZipArchive();
    if ($shop->open($inner, ZipArchive::RDONLY) !== true) {
        fwrite(STDERR, "Cannot open the inner prestashop.zip\n");
        exit(1);
    }
    $count = extract_entries($shop, '', $target);
    $shop->close();
    unlink($inner);
} else {
    $count = extract_entries($zip, 'prestashop/', $target);
}
$zip->close();
echo "{$count} files extracted into {$target}\n";
