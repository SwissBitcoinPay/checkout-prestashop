<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tools;

/**
 * The pinned copy of the SDK in sdk/: the smallest set of the SDK's own files its Composer package
 * builds from (composer.json, LICENSE, src/), unchanged, with sdk/PIN.json naming the repository,
 * the commit, the version and each file's SHA-256 (tools/sdk.php).
 *
 * A development tool: never shipped in the module's ZIP.
 */
final class SdkPin
{
    public const REPOSITORY = 'https://github.com/sattleme/checkout-php';

    public const PIN = 'PIN.json';

    private function __construct()
    {
    }

    /**
     * What differs between $copy and its PIN.json: empty when the copy is the pinned one.
     *
     * @return list<string>
     */
    public static function check(string $copy): array
    {
        $pinPath = $copy . '/' . self::PIN;
        if (!is_file($pinPath)) {
            return [self::PIN . ' is missing'];
        }
        $pin = json_decode((string) file_get_contents($pinPath), true);
        if (!\is_array($pin)) {
            return [self::PIN . ' is not a JSON object'];
        }
        $problems = [];
        if (array_keys($pin) !== ['repository', 'commit', 'version', 'files']) {
            $problems[] = self::PIN . ': its fields are repository, commit, version and files, in this order';
        }
        if (($pin['repository'] ?? null) !== self::REPOSITORY) {
            $problems[] = self::PIN . ': repository is not ' . self::REPOSITORY;
        }
        if (!\is_string($pin['commit'] ?? null) || preg_match('/^[0-9a-f]{40}$/D', $pin['commit']) !== 1) {
            $problems[] = self::PIN . ': commit is not a commit';
        }
        if (!\is_string($pin['version'] ?? null) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $pin['version']) !== 1) {
            $problems[] = self::PIN . ': version is not a version';
        }
        $files = $pin['files'] ?? null;
        if (!\is_array($files) || $files === []) {
            return array_merge($problems, [self::PIN . ': files is empty']);
        }
        foreach ($files as $file => $hash) {
            $path = $copy . '/' . $file;
            if (!\is_string($hash) || !is_file($path)) {
                $problems[] = $file . ' is missing';
            } elseif (!hash_equals($hash, (string) hash_file('sha256', $path))) {
                $problems[] = $file . ' differs from ' . self::PIN;
            }
        }
        foreach (self::filesUnder($copy) as $file) {
            if ($file !== self::PIN && !\array_key_exists($file, $files)) {
                $problems[] = $file . ' is not in ' . self::PIN;
            }
        }
        if (\is_string($pin['version'] ?? null) && is_file($copy . '/src/Client.php')) {
            $client = (string) file_get_contents($copy . '/src/Client.php');
            if (preg_match("/public const VERSION = '([^']+)';/", $client, $match) !== 1 || $match[1] !== $pin['version']) {
                $problems[] = self::PIN . ': version is not the one of src/Client.php';
            }
        }

        return $problems;
    }

    /**
     * Replaces $copy with the files the SDK's Composer package builds from (composer.json, LICENSE,
     * src/) at $commit of a clone of the SDK, and writes PIN.json. The files are read from git's
     * objects, never from the clone's working tree: whatever the clone has checked out, or
     * changed, the copy is the commit's. The commit must be in the clone (fetch it first).
     *
     * @return array{commit: string, version: string, files: int}
     */
    public static function import(string $clone, string $commit, string $copy): array
    {
        if (!is_dir($clone)) {
            throw new \RuntimeException("{$clone} is not a directory");
        }
        if (preg_match('/^[0-9A-Za-z._\/-]{1,255}$/D', $commit) !== 1 || str_starts_with($commit, '-')) {
            throw new \RuntimeException("{$commit} is not a commit");
        }
        try {
            $resolved = trim(self::git($clone, 'rev-parse', '--verify', '--quiet', $commit . '^{commit}'));
        } catch (\RuntimeException) {
            throw new \RuntimeException("{$commit} is not a commit of {$clone}: fetch it first (git -C {$clone} fetch origin {$commit})");
        }
        $files = [];
        foreach (explode("\0", self::git($clone, 'ls-tree', '-r', '-z', $resolved, '--', 'composer.json', 'LICENSE', 'src')) as $entry) {
            if ($entry === '') {
                continue;
            }
            [$meta, $file] = explode("\t", $entry, 2);
            [$mode, $type] = explode(' ', $meta);
            // Regular files only: a symbolic link or a submodule has no place in the package.
            if ($type !== 'blob' || !\in_array($mode, ['100644', '100755'], true)) {
                throw new \RuntimeException("{$file} is not a regular file at {$resolved}");
            }
            $files[$file] = self::git($clone, 'cat-file', 'blob', $resolved . ':' . $file);
        }
        ksort($files, \SORT_STRING);
        $composer = json_decode($files['composer.json'] ?? '', true);
        if (!\is_array($composer) || ($composer['name'] ?? null) !== 'sattleme/checkout' || !isset($files['LICENSE'], $files['src/Client.php'])) {
            throw new \RuntimeException("{$resolved} is not a commit of the PHP SDK (sattleme/checkout)");
        }
        if (preg_match("/public const VERSION = '([0-9]+\\.[0-9]+\\.[0-9]+)';/", $files['src/Client.php'], $match) !== 1) {
            throw new \RuntimeException('src/Client.php names no version');
        }
        $version = $match[1];

        self::remove($copy);
        $hashes = [];
        foreach ($files as $file => $contents) {
            $target = $copy . '/' . $file;
            if (!is_dir(\dirname($target)) && !mkdir(\dirname($target), 0777, true)) {
                throw new \RuntimeException("Cannot create the directory of {$target}");
            }
            if (file_put_contents($target, $contents) !== \strlen($contents)) {
                throw new \RuntimeException("Cannot write {$target}");
            }
            $hashes[$file] = hash('sha256', $contents);
        }
        $pin = ['repository' => self::REPOSITORY, 'commit' => $resolved, 'version' => $version, 'files' => $hashes];
        file_put_contents(
            $copy . '/' . self::PIN,
            json_encode($pin, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
        );

        return ['commit' => $resolved, 'version' => $version, 'files' => \count($hashes)];
    }

    /**
     * Every file under $dir, as paths relative to it with "/", sorted.
     *
     * @return list<string>
     */
    public static function filesUnder(string $dir): array
    {
        $found = [];
        if (!is_dir($dir)) {
            return $found;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile()) {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), \strlen($dir) + 1));
            }
        }
        sort($found);

        return $found;
    }

    /** What git prints, byte for byte; read-only commands only (the clone is never written). */
    private static function git(string $clone, string ...$args): string
    {
        $command = array_values(array_merge(['git', '-C', $clone], $args));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot run git');
        }
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' failed: ' . trim($error));
        }

        return $output;
    }

    private static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
