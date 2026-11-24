<?php

declare(strict_types=1);

/*
 * Zips the built module as PrestaShop's module upload wants it: its directory (sattleme/) at the
 * root of the archive. The entries are sorted and dated alike (the last commit's date), with
 * regular permissions, so that one commit always gives the same bytes.
 *
 * Usage: php tools/zip.php <module directory> <zip> <Unix time of the entries>
 */

$directory = $argv[1] ?? '';
$zipPath = $argv[2] ?? '';
$time = $argv[3] ?? '';
if (!is_dir($directory) || $zipPath === '' || preg_match('/^[0-9]+$/D', $time) !== 1) {
    fwrite(STDERR, "Usage: php tools/zip.php <module directory> <zip> <Unix time>\n");
    exit(2);
}
$directory = rtrim($directory, '/');
$name = basename($directory);

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    assert($file instanceof SplFileInfo);
    if ($file->isLink()) {
        fwrite(STDERR, "A symbolic link in the module: {$file->getPathname()}\n");
        exit(1);
    }
    if ($file->isFile()) {
        $files[] = substr($file->getPathname(), strlen($directory) + 1);
    }
}
sort($files, SORT_STRING);

$directories = [];
foreach ($files as $file) {
    for ($parent = dirname($file); $parent !== '.'; $parent = dirname($parent)) {
        $directories[$parent] = true;
    }
}
$directories = array_keys($directories);
sort($directories, SORT_STRING);

if (is_file($zipPath)) {
    unlink($zipPath);
}
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fwrite(STDERR, "Cannot create {$zipPath}\n");
    exit(1);
}
$entries = array_merge(
    [['dir', $name . '/']],
    array_map(static fn (string $dir): array => ['dir', $name . '/' . $dir . '/'], $directories),
    array_map(static fn (string $file): array => ['file', $name . '/' . $file], $files),
);
usort($entries, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));
foreach ($entries as [$type, $entry]) {
    if ($type === 'dir') {
        $zip->addEmptyDir(rtrim($entry, '/'));
        $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, (040755 & 0xFFFF) << 16);
    } else {
        $zip->addFile($directory . '/' . substr($entry, strlen($name) + 1), $entry);
        $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, (0100644 & 0xFFFF) << 16);
        $zip->setCompressionName($entry, ZipArchive::CM_DEFLATE);
    }
    $zip->setMtimeName($entry, (int) $time);
}
if (!$zip->close()) {
    fwrite(STDERR, "Cannot write {$zipPath}\n");
    exit(1);
}
printf("%s: %d files, %d bytes\n", $zipPath, count($files), filesize($zipPath));
