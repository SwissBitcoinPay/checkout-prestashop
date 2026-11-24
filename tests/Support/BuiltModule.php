<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Support;

/**
 * The module as its built ZIP holds it (tools/build.sh): the tests run that code, never the
 * repository's sattleme/ directory (which lacks the prefixed libraries anyway).
 */
final class BuiltModule
{
    private function __construct()
    {
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function version(): string
    {
        $class = (string) file_get_contents(self::root() . '/sattleme/sattleme.php');
        if (preg_match("/public const VERSION = '([0-9.]+)';/", $class, $match) !== 1) {
            throw new \RuntimeException('sattleme.php names no VERSION');
        }

        return $match[1];
    }

    public static function zip(): string
    {
        $zip = self::root() . '/build/sattleme.zip';
        if (!is_file($zip)) {
            throw new \RuntimeException("{$zip} is missing: run tools/build.sh first");
        }

        return $zip;
    }

    /** The ZIP's module directory, extracted again whenever the ZIP changed. */
    public static function extract(): string
    {
        $zip = self::zip();
        $target = self::root() . '/build/test-module';
        $stamp = $target . '/.zip-sha256';
        $digest = hash_file('sha256', $zip);
        if (is_file($stamp) && file_get_contents($stamp) === $digest && is_dir($target . '/sattleme')) {
            return $target . '/sattleme';
        }
        self::remove($target);
        mkdir($target, 0755, true);
        $archive = new \ZipArchive();
        if ($archive->open($zip, \ZipArchive::RDONLY) !== true || !$archive->extractTo($target)) {
            throw new \RuntimeException("Cannot extract {$zip}");
        }
        $archive->close();
        file_put_contents($stamp, (string) $digest);

        return $target . '/sattleme';
    }

    private static function remove(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
