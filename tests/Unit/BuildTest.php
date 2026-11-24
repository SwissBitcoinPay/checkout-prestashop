<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\BuiltModule;
use Sattleme\PrestaShop\Tools\SdkPin;

/**
 * The pinned SDK and the built ZIP: the copy is its PIN.json's; the lockfile pins the runtime
 * libraries exactly; the ZIP holds the module's files only, its libraries prefixed, and passes the
 * checks PrestaShop's upload and validator make (tools/check-zip.php).
 */
final class BuildTest extends TestCase
{
    public function testThePinnedSdkIsItsPinsCopyFileByFile(): void
    {
        $copy = BuiltModule::root() . '/sdk';
        self::assertSame([], SdkPin::check($copy));
        $pin = json_decode((string) file_get_contents($copy . '/PIN.json'), true);
        self::assertIsArray($pin);
        self::assertSame('https://github.com/sattleme/checkout-php', $pin['repository']);
        self::assertSame('1.0.0', $pin['version']);
        self::assertIsString($pin['commit']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $pin['commit']);
    }

    public function testTheLockfilePinsTheRuntimeLibrariesExactly(): void
    {
        $lock = json_decode((string) file_get_contents(BuiltModule::root() . '/composer.lock'), true);
        self::assertIsArray($lock);
        self::assertIsArray($lock['packages']);
        $runtime = [];
        foreach ($lock['packages'] as $package) {
            self::assertIsArray($package);
            self::assertIsString($package['name']);
            $runtime[$package['name']] = $package['version'];
        }
        ksort($runtime);
        self::assertSame(['bacon/bacon-qr-code' => 'v3.1.1', 'dasprid/enum' => '1.0.7', 'sattleme/checkout' => '1.0.0'], $runtime);
        $composer = json_decode((string) file_get_contents(BuiltModule::root() . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);
        foreach ($composer['require'] as $name => $constraint) {
            self::assertIsString($constraint);
            if (str_contains((string) $name, '/')) {
                self::assertMatchesRegularExpression('/^[0-9]+\.[0-9]+\.[0-9]+$/', $constraint, $name . ' is pinned exactly');
            }
        }
    }

    public function testTheZipPassesTheChecksOfPrestaShopsUploadAndValidator(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BuiltModule::root() . '/tools/check-zip.php') . ' ' . escapeshellarg(BuiltModule::zip()) . ' 2>&1';
        exec($command, $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }

    public function testTheZipHoldsTheModuleAndItsPrefixedLibrariesOnly(): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(BuiltModule::zip(), \ZipArchive::RDONLY));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        self::assertContains('sattleme/sattleme.php', $names);
        self::assertContains('sattleme/config.xml', $names);
        self::assertContains('sattleme/logo.png', $names);
        self::assertContains('sattleme/LICENSE', $names);
        self::assertContains('sattleme/vendor/autoload.php', $names);
        self::assertContains('sattleme/vendor/sattleme/checkout/LICENSE', $names);
        self::assertContains('sattleme/vendor/bacon/bacon-qr-code/LICENSE', $names);
        self::assertContains('sattleme/vendor/dasprid/enum/LICENSE', $names);
        // The logo's licence, not decided yet: README.md says where it will be stated.
        $readme = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(BuiltModule::root() . '/README.md'));
        self::assertStringContainsString('Their licence is not decided yet; once it is, it will be stated here, in this section, and in `LICENSE` after the MIT terms', $readme);
        foreach ($names as $name) {
            self::assertDoesNotMatchRegularExpression('#(^|/)(tests?|\.git[^/]*|\.github|node_modules)(/|$)|composer\.(json|lock)$|phpunit|phpstan|\.md$#i', $name);
        }
        // The SDK's classes are there, prefixed: the module loads them from its own class map.
        $module = BuiltModule::extract();
        self::assertNotSame('', $module);
        self::assertTrue(class_exists('Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Client'));
        self::assertFalse(class_exists('Sattleme\Checkout\Client', false));
        self::assertStringStartsWith($module, (string) (new \ReflectionClass('Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Client'))->getFileName());
        self::assertStringStartsWith($module, (string) (new \ReflectionClass('Sattleme\PrestaShop\Payment\Creator'))->getFileName());
    }

    public function testEveryPhpFileOfTheZipDoesNothingOutsidePrestaShop(): void
    {
        // The _PS_VERSION_ guard in every PHP file, the libraries' and Composer's included
        // (tools/check-zip.php checks each), PrestaShop's index.php aside.
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(BuiltModule::zip(), \ZipArchive::RDONLY));
        $guarded = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '.php') && basename($name) !== 'index.php') {
                self::assertStringContainsString("\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n", (string) $zip->getFromIndex($i), $name);
                ++$guarded;
            }
        }
        $zip->close();
        self::assertGreaterThan(150, $guarded);
        // Composer's autoloader, reached outside PrestaShop: it registers nothing, says nothing.
        $probe = 'register_shutdown_function(static function (): void { echo count(spl_autoload_functions()), "|", implode(",", get_declared_classes()) === implode(",", $GLOBALS["before"]) ? "none" : "declared"; });'
            . '$GLOBALS["before"] = get_declared_classes(); require $argv[1];';
        exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($probe) . ' ' . escapeshellarg(BuiltModule::extract() . '/vendor/autoload.php') . ' 2>&1', $output, $status);
        self::assertSame(['0|none'], $output);
        self::assertSame(0, $status);
    }

    public function testNoLibraryOfTheZipCallsSerializeOrUnserialize(): void
    {
        // dasprid/enum's EnumMap, which calls both, is left out (scoper.inc.php): bacon/bacon-qr-code,
        // as composer.lock installs it (the repository's vendor/, of the same lockfile as the
        // build's), never uses it, nor does the SDK.
        $installed = BuiltModule::root() . '/vendor';
        self::assertFileExists($installed . '/dasprid/enum/src/EnumMap.php');
        foreach (['bacon/bacon-qr-code/src', 'sattleme/checkout/src'] as $library) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($installed . '/' . $library, \FilesystemIterator::SKIP_DOTS));
            $files = 0;
            foreach ($iterator as $file) {
                \assert($file instanceof \SplFileInfo);
                self::assertStringNotContainsString('EnumMap', (string) file_get_contents($file->getPathname()), $file->getPathname());
                ++$files;
            }
            self::assertGreaterThan(10, $files, $library);
        }
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(BuiltModule::zip(), \ZipArchive::RDONLY));
        self::assertNotFalse($zip->getFromName('sattleme/vendor/dasprid/enum/src/AbstractEnum.php'));
        self::assertFalse($zip->getFromName('sattleme/vendor/dasprid/enum/src/EnumMap.php'));
        self::assertStringNotContainsString('EnumMap', (string) $zip->getFromName('sattleme/vendor/composer/autoload_static.php'));
        $zip->close();
    }

    public function testTheZipIsNamedAsPrestaShopAddonsTakesAModule(): void
    {
        // "module_name.zip", without a version number, its directory module_name/ at its root.
        self::assertSame(BuiltModule::root() . '/build/sattleme.zip', BuiltModule::zip());
        self::assertSame([], glob(BuiltModule::root() . '/build/sattleme-*.zip'));
        $build = (string) file_get_contents(BuiltModule::root() . '/tools/build.sh');
        self::assertStringContainsString('"$BUILD/sattleme.zip"', $build);
        self::assertStringNotContainsString('sattleme-$VERSION.zip', $build);
    }

    public function testItsRootHtaccessDeniesPhpFilesAsAddonsAsks(): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(BuiltModule::zip(), \ZipArchive::RDONLY));
        $htaccess = $zip->getFromName('sattleme/.htaccess');
        $zip->close();
        // PrestaShop Addons' validation checklist's own: PHP files denied, in Apache 2.2's and 2.4's words.
        self::assertSame(CheckZipTest::HTACCESS, $htaccess);
    }

    /**
     * header_csp.txt, as PrestaShop Addons recommends it: the external domains the module reaches,
     * all from the shop's server (the customer's browser reaches the shop alone:
     * TemplatesTest). The SDK's own hosts (its rate sources, mempool.space and BlockCypher), read
     * from its source, and breez.tips, the Lightning address of a sattle.me account; the merchant's
     * relays are the integration key's, and its Bitcoin node the merchant's app's: no list can name
     * them.
     */
    public function testItsCspFileNamesTheDomainsTheModuleReaches(): void
    {
        $module = BuiltModule::extract();
        $sdk = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($module . '/vendor/sattleme/checkout/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            // Its code, its comments (the specifications they cite) aside.
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token) || !in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    $code .= is_array($token) ? $token[1] : $token;
                }
            }
            preg_match_all('#\b(?:https|wss)://([a-z0-9.-]+)#', $code, $hosts);
            foreach ($hosts[1] as $host) {
                $sdk[$host] = true;
            }
        }
        $sdk = array_keys($sdk);
        sort($sdk);
        self::assertSame(['api.blockcypher.com', 'api.coinbase.com', 'api.coingecko.com', 'api.kraken.com', 'api.opennode.com', 'mempool.space'], $sdk);
        $csp = explode(';', trim((string) file_get_contents($module . '/header_csp.txt')));
        sort($csp);
        $expected = array_merge($sdk, ['breez.tips']);
        sort($expected);
        self::assertSame($expected, $csp);
    }

    public function testOneVersionEverywhere(): void
    {
        $module = BuiltModule::extract();
        $config = simplexml_load_file($module . '/config.xml');
        self::assertNotFalse($config);
        self::assertSame(BuiltModule::version(), (string) $config->version);
        self::assertSame('sattleme', (string) $config->name);
        self::assertSame('1.0.0', BuiltModule::version());
        self::assertStringStartsWith("# Changelog\n\n## 1.0.0 - 2026-11-24\n", (string) file_get_contents(BuiltModule::root() . '/CHANGELOG.md'));
    }
}
