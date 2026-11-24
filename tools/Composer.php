<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tools;

/**
 * The repository's pinned Composer: the phar of its release 2.11.2, its SHA-256 checked, as the CI
 * installs it (.github/workflows/ci.yml, COMPOSER_URL and COMPOSER_SHA256). The tools that write
 * composer.lock run it, never the Composer on PATH: another version writes the lockfile otherwise
 * (its plugin-api-version, for one).
 *
 * A development tool: never shipped in the module's ZIP.
 */
final class Composer
{
    public const VERSION = '2.11.2';

    public const URL = 'https://getcomposer.org/download/2.11.2/composer.phar';

    public const SHA256 = 'dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028';

    private function __construct()
    {
    }

    /**
     * The phar, in the repository's build/tools/: downloaded once, its SHA-256 checked each time
     * it is used. A file there of other bytes is refused, never run nor replaced.
     */
    public static function phar(string $root): string
    {
        $phar = $root . '/build/tools/composer-' . self::VERSION . '.phar';
        if (!is_file($phar)) {
            self::download($phar);
        }
        if (!hash_equals(self::SHA256, (string) hash_file('sha256', $phar))) {
            throw new \RuntimeException("{$phar} is not Composer " . self::VERSION . '\'s phar (its SHA-256 differs): remove it, and run the command again');
        }

        return $phar;
    }

    /**
     * Runs the pinned Composer with this PHP, in the repository: its exit code.
     *
     * @param list<string> $arguments
     */
    public static function run(string $root, array $arguments): int
    {
        $process = proc_open(array_merge([\PHP_BINARY, self::phar($root)], $arguments, ['--working-dir=' . $root]), [1 => \STDOUT, 2 => \STDERR], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot run Composer');
        }

        return proc_close($process);
    }

    private static function download(string $phar): void
    {
        if (!is_dir(\dirname($phar)) && !mkdir(\dirname($phar), 0777, true) && !is_dir(\dirname($phar))) {
            throw new \RuntimeException('Cannot create ' . \dirname($phar));
        }
        $part = $phar . '.part';
        $process = proc_open(['curl', '-fsSL', '--retry', '3', '-o', $part, self::URL], [1 => \STDOUT, 2 => \STDERR], $pipes);
        if (!\is_resource($process) || proc_close($process) !== 0 || !is_file($part)) {
            @unlink($part);
            throw new \RuntimeException('Composer ' . self::VERSION . ' could not be downloaded from ' . self::URL);
        }
        if (!hash_equals(self::SHA256, (string) hash_file('sha256', $part))) {
            unlink($part);
            throw new \RuntimeException('What ' . self::URL . ' gave is not Composer ' . self::VERSION . '\'s phar (its SHA-256 differs)');
        }
        rename($part, $phar);
    }
}
