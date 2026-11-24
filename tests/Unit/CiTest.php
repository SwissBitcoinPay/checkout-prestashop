<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\BuiltModule;

/**
 * What the workflows run: the release keeps the ZIP PrestaShop Addons takes as the run's artifact,
 * never a GitHub release; and the PHP version a workflow installs is never in PHP, the binary the
 * test shop's and the end-to-end test's scripts run (PHP="${PHP:-php}").
 */
final class CiTest extends TestCase
{
    public function testTheReleaseIsKeptAsTheRunsArtifactNeverAGitHubRelease(): void
    {
        $release = self::lines('.github/workflows/release.yml');
        $text = implode("\n", $release);

        // The module is distributed only through PrestaShop Addons: no release, no write, no secret.
        foreach ($release as $where => $line) {
            self::assertDoesNotMatchRegularExpression('#/releases\b(?!/download/)|\bgh release\b|contents: write|environment:|secrets\.#', $line, $where);
        }
        self::assertMatchesRegularExpression('/^on:\n  push:\n    tags: \["v\*\.\*\.\*"\]$/m', $text);
        self::assertMatchesRegularExpression('/^permissions:\n  contents: read$/m', $text);
        self::assertMatchesRegularExpression('/^  ci:\n    name: CI\n    uses: \.\/\.github\/workflows\/ci\.yml$/m', $text);
        self::assertMatchesRegularExpression('/^  build:\n(?:    .*\n)*?    needs: ci$/m', $text, 'kept once the CI passed');
        // The tag: vX.Y.Z, the module's version in its three places, a commit of main.
        self::assertStringContainsString('sattleme/sattleme.php', $text);
        self::assertStringContainsString('sattleme/config.xml', $text);
        self::assertStringContainsString('CHANGELOG.md', $text);
        self::assertStringContainsString('git merge-base --is-ancestor "$GITHUB_SHA" refs/remotes/origin/main', $text);
        // Built and checked by tools/build.sh, as PrestaShop Addons takes it: sattleme.zip.
        self::assertStringContainsString('run: sh tools/build.sh build/php-scoper.phar', $text);
        self::assertStringContainsString('sha256sum sattleme.zip | tee SHA256SUMS', $text);
        self::assertMatchesRegularExpression('#- uses: actions/upload-artifact@[0-9a-f]{40} \# v[\d.]+\n\s+with:\n\s+name: sattleme-\$\{\{ github\.ref_name \}\}\n\s+path: \|\n\s+build/sattleme\.zip\n\s+build/SHA256SUMS\n\s+if-no-files-found: error\n\s+retention-days: 90$#m', $text);
        self::assertStringNotContainsString('download-artifact', $text);
        // README says how the module is installed, and how the team submits the artifact.
        $readme = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(BuiltModule::root() . '/README.md'));
        self::assertStringNotContainsString('/releases', $readme);
        self::assertStringContainsString('The module is distributed through **PrestaShop Addons** only', $readme);
        self::assertStringContainsString('checks `sattleme.zip` against `SHA256SUMS`', $readme);
    }

    public function testNoWorkflowSetsPhpWhichTheScriptsRunAsPhp(): void
    {
        $versions = 0;
        foreach (['.github/workflows/ci.yml', '.github/workflows/release.yml'] as $workflow) {
            foreach (self::lines($workflow) as $where => $line) {
                // tests/shop/shop.sh and tests/e2e/run.sh run "$PHP": a version there is no PHP.
                self::assertDoesNotMatchRegularExpression('/^\s*PHP:|\$\{\{ env\.PHP \}\}|\$PHP\b(?!_)/', $line, $where);
                $versions += substr_count($line, 'PHP_VERSION');
            }
        }
        self::assertGreaterThan(5, $versions);
    }

    /**
     * The lines of a workflow, but its comments.
     *
     * @return array<string, string> each line, by its file and number
     */
    private static function lines(string $file): array
    {
        $content = file(BuiltModule::root() . '/' . $file, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($content, $file);
        $lines = [];
        foreach ($content as $number => $line) {
            if (!str_starts_with(ltrim($line), '#')) {
                $lines[$file . ':' . ($number + 1)] = $line;
            }
        }
        self::assertGreaterThan(20, count($lines));

        return $lines;
    }
}
