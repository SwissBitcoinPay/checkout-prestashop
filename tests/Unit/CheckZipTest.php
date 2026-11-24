<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\BuiltModule;
use Sattleme\PrestaShop\Tools\Headers;
use Sattleme\PrestaShop\Tools\ModuleKey;

/**
 * tools/check-zip.php on variants of the built ZIP: it takes what PrestaShop Addons asks of a
 * module (a root .htaccess denying PHP files, docs/ with its PDF user guides, header_csp.txt), as
 * Addons asks for them, and refuses anything else it refused before.
 */
final class CheckZipTest extends TestCase
{
    /** The .htaccess of PrestaShop Addons' validation checklist: PHP files denied, Apache 2.2 and 2.4. */
    public const HTACCESS = "# Apache 2.2\n<IfModule !mod_authz_core.c>\n   <Files *.php>\n   order allow,deny\n   deny from all\n   </Files>\n</IfModule>\n\n"
        . "# Apache 2.4\n<IfModule mod_authz_core.c>\n   <Files *.php>\n   Require all denied\n   </Files>\n</IfModule>\n";

    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * The built ZIP with files added (name => contents) or removed (name => null), each set of
     * changes after the one before.
     *
     * @param array<string, string|null> ...$sets
     */
    private function variant(array ...$sets): string
    {
        $copy = sys_get_temp_dir() . '/sattleme-checkzip-' . bin2hex(random_bytes(6)) . '.zip';
        $this->made[] = $copy;
        self::assertTrue(copy(BuiltModule::zip(), $copy));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($copy));
        foreach ($sets as $changes) {
            foreach ($changes as $name => $contents) {
                self::assertTrue($contents === null ? $zip->deleteName($name) : $zip->addFromString($name, $contents), $name);
            }
        }
        self::assertTrue($zip->close());

        return $copy;
    }

    /** @return array{0: int, 1: string} the check's exit code and what it printed */
    private static function check(string $zip): array
    {
        exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(BuiltModule::root() . '/tools/check-zip.php') . ' ' . escapeshellarg($zip) . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    /** @return array<string, string> what PrestaShop Addons asks of a module, as it asks for it */
    private static function addons(): array
    {
        return [
            'sattleme/.htaccess' => self::HTACCESS,
            'sattleme/docs/index.php' => Headers::indexFile(),
            'sattleme/docs/readme_en.pdf' => "%PDF-1.4\n%%EOF\n",
            'sattleme/docs/readme_fr.pdf' => "%PDF-1.4\n%%EOF\n",
            'sattleme/header_csp.txt' => "relay.example.com;breez.tips;api.coingecko.com\n",
        ];
    }

    public function testWhatAddonsAsksOfAModuleIsTaken(): void
    {
        // Its module key too, as README.md's "Releases" says to add it (Addons' own example of a
        // key); the built ZIP, without it, passes BuildTest's check.
        [$status, $output] = self::check($this->variant(self::addons(), ['sattleme/sattleme.php' => self::keyed("\$this->module_key = 'c1614c239af92968e5fae97f366e9961';")]));
        self::assertSame(0, $status, $output);
    }

    public function testTheModuleKeyIsNoneUntilAddonsGivesItThenOneQuotedKey(): void
    {
        $class = self::moduleClass();
        self::assertStringNotContainsString('module_key =', $class);
        self::assertNull(ModuleKey::problem($class));
        self::assertNull(ModuleKey::problem(self::keyed("\$this->module_key = 'c1614c239af92968e5fae97f366e9961';")));
        // Not a quoted key, or twice: refused (tools/check-zip.php, testAnythingElseIsRefusedAsBefore).
        foreach (["\$this->module_key = getenv('KEY');", "\$this->module_key = '';", "\$this->module_key = 'c1614c239af92968e5fae97f366e9961';\n        \$this->module_key = 'c1614c239af92968e5fae97f366e9961';"] as $wrong) {
            self::assertSame('module_key is not one key of PrestaShop Addons, quoted', ModuleKey::problem(self::keyed($wrong)), $wrong);
        }
        // Where it goes: README.md's "Releases", and a comment in the constructor.
        $readme = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(BuiltModule::root() . '/README.md'));
        self::assertStringContainsString("**Before the first upload**, the module's key", $readme);
        self::assertStringContainsString("as `\$this->module_key = '<the key>';` (one line, after `\$this->name`)", $readme);
        self::assertStringContainsString("        \$this->name = self::NAME;\n        // PrestaShop Addons' module key goes here once the product exists: README.md, \"Releases\".\n", $class);
    }

    /** The module's class with $line after its name, where README.md says the key goes. */
    private static function keyed(string $line): string
    {
        $class = self::moduleClass();
        $keyed = (string) preg_replace('/^        \$this->name = self::NAME;\n/m', '$0        ' . $line . "\n", $class, 1);
        self::assertNotSame($class, $keyed);

        return $keyed;
    }

    /** The module's class, as the built ZIP holds it. */
    private static function moduleClass(): string
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(BuiltModule::zip(), \ZipArchive::RDONLY));
        $class = $zip->getFromName('sattleme/sattleme.php');
        $zip->close();
        self::assertIsString($class);

        return $class;
    }

    public function testAnythingElseIsRefusedAsBefore(): void
    {
        $refused = [
            // Another file in docs/, a user guide that is no PDF, or named otherwise; docs/ without its index.php.
            'sattleme/docs/notes.md' => ["Notes\n", 'sattleme/docs/notes.md: not a file of the module'],
            'sattleme/docs/readme_de.pdf' => ["Not a PDF\n", 'sattleme/docs/readme_de.pdf: not a PDF'],
            'sattleme/docs/guide.pdf' => ["%PDF-1.4\n", 'sattleme/docs/guide.pdf: not a file of the module'],
            'sattleme/docs/index.php' => [null, 'sattleme/docs/: no index.php'],
            // An .htaccess elsewhere, or of Apache 2.4 only; header_csp.txt with a URL.
            'sattleme/src/.htaccess' => [self::HTACCESS, 'sattleme/src/.htaccess: a development file'],
            'sattleme/.htaccess' => ["<Files *.php>\nRequire all denied\n</Files>\n", 'sattleme/.htaccess: does not deny PHP files in Apache 2.2\'s and 2.4\'s words'],
            'sattleme/header_csp.txt' => ['https://relay.example.com;breez.tips', 'sattleme/header_csp.txt: "https://relay.example.com" is not a domain'],
            // A PHP file without the _PS_VERSION_ guard, a library's too.
            'sattleme/vendor/dasprid/enum/src/NullValue.php' => ["<?php\n\nnamespace Sattleme\\PrestaShop\\Vendor\\DASPRiD\\Enum;\n\nfinal class NullValue\n{\n}\n", 'sattleme/vendor/dasprid/enum/src/NullValue.php: no _PS_VERSION_ guard'],
            'sattleme/vendor/composer/autoload_psr4.php' => ["<?php\n\nreturn [];\n", 'sattleme/vendor/composer/autoload_psr4.php: no _PS_VERSION_ guard'],
            // PHP's serialize(), unserialize() or eval() called, in a library too (dasprid/enum's
            // EnumMap, left out of the ZIP, calls the first two).
            'sattleme/vendor/dasprid/enum/src/EnumMap.php' => ["<?php\n\nnamespace Sattleme\\PrestaShop\\Vendor\\DASPRiD\\Enum;\n\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\nfinal class EnumMap\n{\n    public function serialize(): string\n    {\n        return \\serialize([]);\n    }\n\n    public function unserialize(string \$data): void\n    {\n        unserialize(\$data);\n    }\n}\n", 'sattleme/vendor/dasprid/enum/src/EnumMap.php: calls serialize()'],
            'sattleme/vendor/bacon/bacon-qr-code/src/Run.php' => ["<?php\n\nnamespace Sattleme\\PrestaShop\\Vendor\\BaconQrCode;\n\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\neval('return 1;');\n", 'sattleme/vendor/bacon/bacon-qr-code/src/Run.php: calls eval()'],
            // A module key that is not one quoted key.
            'sattleme/sattleme.php' => [self::keyed("\$this->module_key = getenv('KEY');"), 'sattleme.php: module_key is not one key of PrestaShop Addons, quoted'],
            // What was refused before.
            'sattleme/tests/index.php' => [Headers::indexFile(), 'sattleme/tests/index.php: not a file of the module'],
            'sattleme/.git/config' => ["[core]\n", 'sattleme/.git/config: a development file'],
            'sattleme/composer.json' => ["{}\n", 'sattleme/composer.json: not a file of the module'],
            'sattleme/notes.txt' => ["Notes\n", 'sattleme/notes.txt: not a file of the module'],
        ];
        [$status, $output] = self::check($this->variant(self::addons(), array_map(static fn (array $case): ?string => $case[0], $refused)));
        self::assertSame(1, $status, $output);
        foreach ($refused as [, $problem]) {
            self::assertStringContainsString($problem, $output);
        }
        self::assertStringContainsString('sattleme/vendor/dasprid/enum/src/EnumMap.php: calls unserialize()', $output);
        // Methods of those names, refusing serialization or of the SDK's own (the built ZIP has
        // both), are not PHP's functions.
        self::assertStringNotContainsString('AbstractEnum.php', $output);
        self::assertStringNotContainsString('Event.php', $output);
    }
}
