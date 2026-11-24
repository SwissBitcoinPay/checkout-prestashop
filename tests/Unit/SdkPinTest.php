<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\BuiltModule;
use Sattleme\PrestaShop\Tools\Composer;
use Sattleme\PrestaShop\Tools\SdkPin;

/**
 * tools/sdk.php's import of the SDK into sdk/: the files of the SDK's Composer package at a
 * commit, read from git's objects whatever the clone has checked out or changed, with PIN.json;
 * its check, which finds any file changed, missing or added; and the Composer it runs, the
 * repository's pinned one, never the one on PATH.
 */
final class SdkPinTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sattleme-sdkpin-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/sdk/src', 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    private function git(string ...$args): string
    {
        $command = array_values(array_merge(['git', '-C', $this->dir . '/sdk', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'commit.gpgsign=false'], $args));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);

        return trim($output);
    }

    /** @param array<string, string> $files */
    private function commit(array $files): string
    {
        foreach ($files as $name => $contents) {
            file_put_contents($this->dir . '/sdk/' . $name, $contents);
        }
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--message', 'A commit');

        return $this->git('rev-parse', 'HEAD');
    }

    public function testACommitIsImportedFromGitsObjectsWithItsPin(): void
    {
        $this->git('init', '--quiet');
        $first = $this->commit([
            'composer.json' => '{"name": "sattleme/checkout"}',
            'LICENSE' => "MIT\n",
            'src/Client.php' => "<?php final class Client { public const VERSION = '1.0.0'; }\n",
            'src/A.php' => "<?php // first\n",
            'README.md' => "Not of the package\n",
        ]);
        $this->commit(['src/A.php' => "<?php // second\n", 'src/B.php' => "<?php // new\n"]);
        // The clone's working tree changed too: never what is imported.
        file_put_contents($this->dir . '/sdk/src/A.php', "<?php // not committed\n");

        $copy = $this->dir . '/copy';
        $result = SdkPin::import($this->dir . '/sdk', substr($first, 0, 12), $copy);
        self::assertSame(['commit' => $first, 'version' => '1.0.0', 'files' => 4], $result);
        self::assertSame(['LICENSE', 'PIN.json', 'composer.json', 'src/A.php', 'src/Client.php'], SdkPin::filesUnder($copy));
        self::assertSame("<?php // first\n", file_get_contents($copy . '/src/A.php'));
        $pin = json_decode((string) file_get_contents($copy . '/PIN.json'), true);
        self::assertIsArray($pin);
        self::assertSame(['repository', 'commit', 'version', 'files'], array_keys($pin));
        self::assertSame(SdkPin::REPOSITORY, $pin['repository']);
        self::assertIsArray($pin['files']);
        self::assertSame(hash('sha256', "<?php // first\n"), $pin['files']['src/A.php'] ?? null);
        self::assertSame([], SdkPin::check($copy));

        // Changed, added or removed by hand: the check says so.
        file_put_contents($copy . '/src/A.php', "<?php // edited\n");
        file_put_contents($copy . '/src/C.php', "<?php // added\n");
        unlink($copy . '/LICENSE');
        self::assertSame(['LICENSE is missing', 'src/A.php differs from PIN.json', 'src/C.php is not in PIN.json'], SdkPin::check($copy));
    }

    public function testWhatIsNotACommitOfTheSdkIsRefused(): void
    {
        $this->git('init', '--quiet');
        $other = $this->commit(['composer.json' => '{"name": "acme/other"}', 'LICENSE' => "MIT\n", 'src/Client.php' => "<?php\n"]);
        foreach (['0000000000000000000000000000000000000000', $other, '--upload-pack=x'] as $commit) {
            try {
                SdkPin::import($this->dir . '/sdk', $commit, $this->dir . '/copy');
                self::fail("{$commit} was imported");
            } catch (\RuntimeException $e) {
                self::assertMatchesRegularExpression('/is not a commit/', $e->getMessage());
            }
        }
        self::assertDirectoryDoesNotExist($this->dir . '/copy');
    }

    public function testTheImportRunsThePinnedComposerNeverTheOneOnPath(): void
    {
        $this->git('init', '--quiet');
        $commit = $this->commit([
            'composer.json' => '{"name": "sattleme/checkout"}',
            'LICENSE' => "MIT\n",
            'src/Client.php' => "<?php final class Client { public const VERSION = '1.0.0'; }\n",
        ]);
        // A copy of the repository's tools, manifests and pinned SDK.
        $repository = $this->dir . '/repository';
        mkdir($repository . '/tools', 0777, true);
        foreach (['tools/sdk.php', 'tools/SdkPin.php', 'tools/Composer.php', 'composer.json', 'composer.lock'] as $file) {
            copy(BuiltModule::root() . '/' . $file, $repository . '/' . $file);
        }
        mkdir($repository . '/sdk');
        foreach (SdkPin::filesUnder(BuiltModule::root() . '/sdk') as $file) {
            if (!is_dir(\dirname($repository . '/sdk/' . $file))) {
                mkdir(\dirname($repository . '/sdk/' . $file), 0777, true);
            }
            copy(BuiltModule::root() . '/sdk/' . $file, $repository . '/sdk/' . $file);
        }
        $pin = (string) file_get_contents($repository . '/sdk/PIN.json');
        $lock = (string) file_get_contents($repository . '/composer.lock');
        // A Composer on PATH (and named by COMPOSER_BIN, as the import read it once), which says
        // when it runs; at the pinned Composer's place, a file of other bytes.
        mkdir($this->dir . '/bin');
        file_put_contents($this->dir . '/bin/composer', "#!/bin/sh\necho \"\$@\" > " . escapeshellarg($this->dir . '/ran') . "\n");
        chmod($this->dir . '/bin/composer', 0755);
        mkdir($repository . '/build/tools', 0777, true);
        file_put_contents($repository . '/build/tools/composer-' . Composer::VERSION . '.phar', "<?php echo 'not Composer';\n");

        $environment = getenv();
        $path = getenv('PATH');
        $environment['PATH'] = $this->dir . '/bin:' . (is_string($path) && $path !== '' ? $path : '/usr/bin:/bin');
        $environment['COMPOSER_BIN'] = $this->dir . '/bin/composer';
        $process = proc_open([\PHP_BINARY, $repository . '/tools/sdk.php', 'import', $this->dir . '/sdk', $commit], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repository, $environment);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process), $output . $error);
        self::assertFileDoesNotExist($this->dir . '/ran', 'the Composer on PATH ran');
        self::assertStringContainsString('is not Composer ' . Composer::VERSION . '\'s phar', $error);
        // Nothing changed: the pinned SDK and the lockfile as they were.
        self::assertSame($pin, file_get_contents($repository . '/sdk/PIN.json'));
        self::assertSame($lock, file_get_contents($repository . '/composer.lock'));
    }

    public function testThePinnedComposerIsTheOneTheCiInstalls(): void
    {
        foreach (['ci.yml', 'release.yml'] as $workflow) {
            $text = (string) file_get_contents(BuiltModule::root() . '/.github/workflows/' . $workflow);
            self::assertStringContainsString('COMPOSER_URL: ' . Composer::URL . "\n", $text, $workflow);
            self::assertStringContainsString('COMPOSER_SHA256: ' . Composer::SHA256 . "\n", $text, $workflow);
        }
        $lock = json_decode((string) file_get_contents(BuiltModule::root() . '/composer.lock'), true);
        self::assertIsArray($lock);
        // Written by a Composer 2 (the pinned one, or Dependabot's, whose version may differ: its
        // plugin API version is not asserted, so that its pull requests pass the same checks).
        self::assertIsString($lock['plugin-api-version'] ?? null);
        self::assertStringStartsWith('2.', $lock['plugin-api-version']);
    }
}
