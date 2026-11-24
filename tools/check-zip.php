<?php

declare(strict_types=1);

/*
 * Checks the module's ZIP as PrestaShop's module upload and its validator would, offline:
 *
 * - one directory at its root, sattleme/, with sattleme/sattleme.php (the upload's own test);
 * - only the module's files: no development file (tests, CI, Composer's manifests, tool configs),
 *   besides what PrestaShop Addons asks of a module at its root: .htaccess (denying PHP files,
 *   both in Apache 2.2's and 2.4's words), docs/ with its user guides (readme_<language>.pdf, PDFs)
 *   and header_csp.txt (the external domains the module reaches, separated by semicolons);
 * - an index.php in every directory, PrestaShop's (autoindex);
 * - the licence header on every file of the module, and the _PS_VERSION_ guard on every PHP file,
 *   its libraries' included (direct access to a file does nothing), PrestaShop's index.php aside;
 * - no forbidden function in the module's code (eval, exec, serialize…, debug output);
 * - every PHP file parses (php -l), and the embedded libraries are all prefixed;
 * - config.xml, the class and the CHANGELOG name one version; the class's module_key, PrestaShop
 *   Addons' key of the product, is either absent (before the product exists: README.md,
 *   "Releases") or one quoted key (Addons' example: 32 hexadecimal characters); the logo is a PNG; the translations
 *   of French, German and Italian are there and are XLIFF.
 *
 * Usage: php tools/check-zip.php <zip>
 */

use Sattleme\PrestaShop\Tools\Headers;
use Sattleme\PrestaShop\Tools\ModuleKey;

require __DIR__ . '/Headers.php';
require __DIR__ . '/ModuleKey.php';

$zipPath = $argv[1] ?? '';
$zip = new ZipArchive();
if ($zipPath === '' || $zip->open($zipPath, ZipArchive::RDONLY) !== true) {
    fwrite(STDERR, "Usage: php tools/check-zip.php <zip>\n");
    exit(2);
}

$problems = [];
$problem = static function (string $message) use (&$problems): void {
    $problems[] = $message;
};

$module = 'sattleme';
$files = [];
$directories = [];
for ($i = 0; $i < $zip->numFiles; ++$i) {
    $name = (string) $zip->getNameIndex($i);
    if (!str_starts_with($name, $module . '/')) {
        $problem("{$name}: outside {$module}/");
        continue;
    }
    if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/')) {
        $problem("{$name}: not a plain relative path");
    }
    if (str_ends_with($name, '/')) {
        $directories[rtrim($name, '/')] = true;
    } else {
        $files[$name] = $i;
        for ($parent = dirname($name); $parent !== '.'; $parent = dirname($parent)) {
            $directories[$parent] = true;
        }
    }
}

// The upload: module_name/module_name.php.
if (!isset($files["{$module}/{$module}.php"])) {
    $problem("{$module}/{$module}.php is missing");
}

// Only the module's files.
$topLevel = ['LICENSE', 'config.xml', 'controllers', 'index.php', 'logo.png', "{$module}.php", 'src', 'translations', 'vendor', 'views'];
$devName = '#(^|/)(\.[^/]+|tests?|docs?|examples?|bin|node_modules|composer\.(json|lock)|phpunit[^/]*|phpstan[^/]*|psalm[^/]*|'
    . 'Makefile|[^/]*\.(sh|neon|dist|md|yml|yaml|phar|log|lock|zip|bak|orig|swp))($|/)#i';
// What PrestaShop Addons asks of a module, at its root only: exactly these names.
$addons = '#^(\.htaccess|header_csp\.txt|docs|docs/index\.php|docs/readme_[a-z]{2}\.pdf)$#D';
foreach (array_merge(array_keys($files), array_keys($directories)) as $name) {
    if ($name === $module) {
        continue;
    }
    $relative = substr($name, strlen($module) + 1);
    if (preg_match($addons, $relative) === 1) {
        continue;
    }
    $first = explode('/', $relative)[0];
    if (!in_array($first, $topLevel, true) || str_starts_with($relative, 'docs/')) {
        $problem("{$name}: not a file of the module");
    }
    if (preg_match($devName, $relative) === 1) {
        $problem("{$name}: a development file");
    }
}
// Addons' files, as Addons asks for them.
$htaccess = $zip->getFromName("{$module}/.htaccess");
if (is_string($htaccess)) {
    // PHP files denied to the web, by Apache 2.4 (mod_authz_core) and by Apache 2.2 (mod_access).
    foreach (['/<Files\s+[^>]*\.php[^>]*>/i', '/Require all denied/', '/Order (deny,allow|allow,deny)/i', '/Deny from all/i'] as $rule) {
        if (preg_match($rule, $htaccess) !== 1) {
            $problem("{$module}/.htaccess: does not deny PHP files in Apache 2.2's and 2.4's words");
            break;
        }
    }
}
foreach ($files as $name => $index) {
    if (preg_match('#^' . $module . '/docs/readme_[a-z]{2}\.pdf$#D', $name) === 1 && !str_starts_with((string) $zip->getFromIndex($index), '%PDF-')) {
        $problem("{$name}: not a PDF");
    }
}
// Addons' format: the domains, separated by semicolons ("domaine-1.com;www.domaine-2.fr").
$csp = $zip->getFromName("{$module}/header_csp.txt");
if (is_string($csp)) {
    foreach (explode(';', trim($csp)) as $domain) {
        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $domain) !== 1) {
            $problem("{$module}/header_csp.txt: \"{$domain}\" is not a domain");
        }
    }
}

// An index.php in every directory.
foreach (array_keys($directories) as $directory) {
    $index = $directory . '/index.php';
    if (!isset($files[$index])) {
        $problem("{$directory}/: no index.php");
    } elseif ($zip->getFromIndex($files[$index]) !== Headers::indexFile()) {
        $problem("{$index}: not PrestaShop's index.php");
    }
}

// Every PHP file, the libraries' included: the guard, and no call of PHP's serialize(),
// unserialize() or eval() (PrestaShop Addons forbids the first two in a module's code: methods of
// those names, such as __serialize() refusing it, are not PHP's functions). The module's own
// files: the licence header, no forbidden function.
$forbidden = '/\b(eval|exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec|assert_options|create_function|serialize|unserialize|var_dump|var_export|print_r|debug_zval_dump|phpinfo|dump|dd|error_log)\s*\(/i';
foreach ($files as $name => $index) {
    $relative = substr($name, strlen($module) + 1);
    $contents = (string) $zip->getFromIndex($index);
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($extension === 'php' && basename($name) !== 'index.php' && preg_match("/\\nif \\(!defined\\('_PS_VERSION_'\\)\\) \\{\\n    exit;\\n\\}\\n/", $contents) !== 1) {
        $problem("{$name}: no _PS_VERSION_ guard");
    }
    if ($extension === 'php') {
        foreach (phpCalls($contents, ['serialize', 'unserialize', 'eval']) as $call) {
            $problem("{$name}: calls {$call}()");
        }
    }
    if (str_starts_with($relative, 'vendor/')) {
        continue;
    }
    if (in_array($extension, ['php', 'tpl', 'js', 'css'], true) && !Headers::hasHeader($name, $contents)) {
        $problem("{$name}: no licence header");
    }
    if ($extension === 'php' && basename($name) !== 'index.php') {
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? '';
        if (preg_match($forbidden, $code, $match) === 1) {
            $problem("{$name}: calls {$match[1]}()");
        }
    }
    if ($extension === 'js' && preg_match('/\b(eval\s*\(|new Function\b|document\.write\b|console\.)/', $contents, $match) === 1) {
        $problem("{$name}: uses {$match[1]}");
    }
}

/**
 * The calls of PHP's functions $names in $code: by name, unqualified or from the root namespace,
 * neither a method (->, ::), nor a declaration (function), as PHP's tokenizer reads the code.
 *
 * @param list<string> $names
 *
 * @return list<string>
 */
function phpCalls(string $code, array $names): array
{
    $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn (PhpToken $token): bool => !$token->isIgnorable()));
    $calls = [];
    foreach ($tokens as $i => $token) {
        $name = strtolower(ltrim($token->text, '\\'));
        if (!in_array($name, $names, true) || !$token->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_EVAL])) {
            continue;
        }
        $before = $tokens[$i - 1] ?? null;
        if ($before !== null && $before->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST])) {
            continue;
        }
        if (($tokens[$i + 1] ?? null)?->text === '(') {
            $calls[] = $name;
        }
    }

    return $calls;
}

// Every PHP file parses (php -l, a few at once); the embedded libraries are prefixed.
$scratch = sys_get_temp_dir() . '/sattleme-check-' . bin2hex(random_bytes(6));
mkdir($scratch);
$lint = [];
try {
    foreach ($files as $name => $index) {
        if (!str_ends_with($name, '.php')) {
            continue;
        }
        $contents = (string) $zip->getFromIndex($index);
        if (str_contains($name, '/vendor/') && preg_match_all('/^namespace ([A-Za-z0-9_\\\\]+)/m', $contents, $matches) > 0) {
            foreach ($matches[1] as $namespace) {
                if (!str_starts_with($namespace, 'Sattleme\\PrestaShop\\Vendor\\') && $namespace !== 'Composer\\Autoload') {
                    $problem("{$name}: namespace {$namespace} is not prefixed");
                }
            }
        }
        $path = $scratch . '/' . sprintf('%05d', count($lint)) . '.php';
        file_put_contents($path, $contents);
        $lint[$name] = $path;
    }
    // PHP 8.3 and later lint several files per run, earlier versions one: a few runs at once.
    $queue = array_chunk($lint, PHP_VERSION_ID >= 80300 ? 40 : 1, true);
    $running = [];
    while ($queue !== [] || $running !== []) {
        while ($queue !== [] && count($running) < 4) {
            $batch = (array) array_shift($queue);
            $process = proc_open(array_merge([PHP_BINARY, '-n', '-l'], array_values($batch)), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                $problem(implode(', ', array_keys($batch)) . ': php -l could not run');
                continue;
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $running[] = [$process, [$pipes[1], $pipes[2]], $batch, ''];
        }
        foreach ($running as $key => [$process, $outputs, $batch, $text]) {
            foreach ($outputs as $output) {
                $text .= (string) stream_get_contents($output);
            }
            $running[$key][3] = $text;
            if (proc_get_status($process)['running']) {
                continue;
            }
            foreach ($outputs as $output) {
                $text .= (string) stream_get_contents($output);
                fclose($output);
            }
            proc_close($process);
            // Each file of the run is checked by its own line: "No syntax errors detected in <file>".
            foreach ($batch as $name => $path) {
                if (!str_contains($text, 'No syntax errors detected in ' . $path . "\n")) {
                    $lines = array_filter(explode("\n", $text), static fn (string $line): bool => str_contains($line, $path));
                    $problem("{$name}: " . trim(str_replace($path, $name, implode(' ', $lines !== [] ? $lines : [$text]))));
                }
            }
            unset($running[$key]);
        }
        usleep(10000);
    }
} finally {
    foreach ($lint as $path) {
        @unlink($path);
    }
    @rmdir($scratch);
}

// One version; the logo; the translations.
$class = (string) $zip->getFromName("{$module}/{$module}.php");
preg_match("/public const VERSION = '([0-9.]+)';/", $class, $classVersion);
$config = (string) $zip->getFromName("{$module}/config.xml");
preg_match('#<version><!\[CDATA\[([0-9.]+)\]\]></version>#', $config, $configVersion);
$changelog = (string) @file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
preg_match('/^## \[?([0-9.]+)\]?/m', $changelog, $changelogVersion);
$versions = array_unique([$classVersion[1] ?? '?', $configVersion[1] ?? '?', $changelogVersion[1] ?? '?']);
if (count($versions) !== 1 || in_array('?', $versions, true)) {
    $problem('sattleme.php, config.xml and CHANGELOG.md name versions ' . implode(', ', $versions));
}
if (!str_contains($class, "'max' => '9.99.99'") || !str_contains($class, "'min' => '9.0.0'")) {
    $problem("{$module}.php: ps_versions_compliancy is not 9.0.0 to 9.99.99");
}
// Addons' module key: with it or without it, but as Addons gives it, once.
$keyProblem = ModuleKey::problem($class);
if ($keyProblem !== null) {
    $problem("{$module}.php: {$keyProblem}");
}
if (!str_starts_with((string) $zip->getFromName("{$module}/logo.png"), "\x89PNG\r\n\x1a\n")) {
    $problem("{$module}/logo.png: not a PNG");
}
foreach (['fr-FR', 'de-DE', 'it-IT'] as $locale) {
    foreach (['Admin', 'Shop'] as $part) {
        $name = "{$module}/translations/{$locale}/ModulesSattleme{$part}.{$locale}.xlf";
        $xml = $zip->getFromName($name);
        $document = new DOMDocument();
        if (!is_string($xml) || !@$document->loadXML($xml) || $document->documentElement?->localName !== 'xliff') {
            $problem("{$name}: missing, or not XLIFF");
        }
    }
}

$zip->close();
foreach ($problems as $message) {
    fwrite(STDERR, "{$message}\n");
}
if ($problems !== []) {
    fwrite(STDERR, count($problems) . " problems in {$zipPath}\n");
    exit(1);
}
printf("%s: %d files in %d directories, checked\n", $zipPath, count($files), count($directories));
