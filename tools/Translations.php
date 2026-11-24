<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tools;

/**
 * The module's wordings and their translations (PrestaShop's translation system: XLIFF files in
 * sattleme/translations/<locale>/ModulesSattleme<Part>.<locale>.xlf, domains Modules.Sattleme.Admin
 * and Modules.Sattleme.Shop).
 *
 * The wordings are read from the code as PrestaShop's own extractor reads them: the trans() calls
 * whose wording and domain are literal strings, and the {l s='…' d='…'} tags of the templates. Each
 * locale's files must hold exactly those wordings, each translated, with the same placeholders.
 *
 * A development tool: never shipped in the module's ZIP.
 */
final class Translations
{
    public const LOCALES = ['fr-FR', 'de-DE', 'it-IT'];

    public const DOMAINS = ['Modules.Sattleme.Admin', 'Modules.Sattleme.Shop'];

    private const DOMAIN = '/^Modules\.Sattleme\.(Admin|Shop)$/D';

    private function __construct()
    {
    }

    /**
     * The wordings of the module's code, by domain, sorted.
     *
     * @return array<string, list<string>>
     */
    public static function extract(string $moduleDir): array
    {
        $wordings = array_fill_keys(self::DOMAINS, []);
        foreach (self::files($moduleDir) as $file) {
            $code = (string) file_get_contents($file);
            $found = str_ends_with($file, '.tpl') ? self::fromTemplate($code) : self::fromPhp($code, $file);
            foreach ($found as [$text, $domain]) {
                $wordings[$domain][$text] = true;
            }
        }
        $sorted = [];
        foreach ($wordings as $domain => $texts) {
            $texts = array_map('strval', array_keys($texts));
            sort($texts, SORT_STRING);
            $sorted[$domain] = $texts;
        }

        return $sorted;
    }

    /**
     * The translations of a locale's file of a domain: source => target.
     *
     * @return array<string, string>
     */
    public static function read(string $file): array
    {
        $document = new \DOMDocument();
        if (!is_file($file) || !@$document->load($file, \LIBXML_NONET)) {
            throw new \RuntimeException("{$file}: missing, or not XML");
        }
        if ($document->documentElement?->localName !== 'xliff') {
            throw new \RuntimeException("{$file}: not XLIFF");
        }
        $units = [];
        foreach ($document->getElementsByTagName('trans-unit') as $unit) {
            $source = $unit->getElementsByTagName('source')->item(0);
            $target = $unit->getElementsByTagName('target')->item(0);
            if ($source === null) {
                throw new \RuntimeException("{$file}: a unit without source");
            }
            if (isset($units[$source->textContent])) {
                throw new \RuntimeException("{$file}: \"{$source->textContent}\" twice");
            }
            $units[$source->textContent] = $target === null ? '' : $target->textContent;
        }

        return $units;
    }

    /** The file of a domain's translations in a locale. */
    public static function file(string $moduleDir, string $domain, string $locale): string
    {
        $part = substr($domain, strlen('Modules.Sattleme.'));

        return "{$moduleDir}/translations/{$locale}/ModulesSattleme{$part}.{$locale}.xlf";
    }

    /**
     * What is wrong with the module's translations: wordings missing or left over, untranslated,
     * or with other placeholders.
     *
     * @return list<string>
     */
    public static function problems(string $moduleDir): array
    {
        $problems = [];
        $wordings = self::extract($moduleDir);
        foreach (self::LOCALES as $locale) {
            foreach (self::DOMAINS as $domain) {
                $file = self::file($moduleDir, $domain, $locale);
                try {
                    $units = self::read($file);
                } catch (\RuntimeException $e) {
                    $problems[] = $e->getMessage();
                    continue;
                }
                foreach (array_diff($wordings[$domain], array_keys($units)) as $missing) {
                    $problems[] = "{$file}: \"{$missing}\" is missing";
                }
                foreach (array_diff(array_keys($units), $wordings[$domain]) as $extra) {
                    $problems[] = "{$file}: \"{$extra}\" is used nowhere";
                }
                foreach ($units as $source => $target) {
                    if (trim($target) === '') {
                        $problems[] = "{$file}: \"{$source}\" is not translated";
                    } elseif (self::placeholders((string) $source) !== self::placeholders($target)) {
                        $problems[] = "{$file}: \"{$source}\" and its translation have other placeholders";
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Writes a locale's file of a domain: the wordings given, sorted, with their translations.
     *
     * @param array<string, string> $translations source => target
     */
    public static function write(string $file, string $locale, array $translations): void
    {
        ksort($translations, SORT_STRING);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $xliff = $document->createElementNS('urn:oasis:names:tc:xliff:document:1.2', 'xliff');
        $xliff->setAttribute('version', '1.2');
        $document->appendChild($xliff);
        $fileElement = $document->createElement('file');
        $fileElement->setAttribute('original', 'sattleme');
        $fileElement->setAttribute('source-language', 'en-US');
        $fileElement->setAttribute('target-language', $locale);
        $fileElement->setAttribute('datatype', 'plaintext');
        $xliff->appendChild($fileElement);
        $body = $document->createElement('body');
        $fileElement->appendChild($body);
        foreach ($translations as $source => $target) {
            $unit = $document->createElement('trans-unit');
            $unit->setAttribute('id', md5((string) $source));
            $sourceElement = $document->createElement('source');
            $sourceElement->appendChild($document->createTextNode((string) $source));
            $targetElement = $document->createElement('target');
            $targetElement->appendChild($document->createTextNode($target));
            $unit->appendChild($sourceElement);
            $unit->appendChild($targetElement);
            $body->appendChild($unit);
        }
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, $document->saveXML());
    }

    /** @return list<string> the placeholders of a wording, sorted: %name% */
    public static function placeholders(string $text): array
    {
        preg_match_all('/%[a-z_]+%/', $text, $matches);
        $placeholders = $matches[0];
        sort($placeholders);

        return $placeholders;
    }

    /** @return list<string> the module's PHP files and templates, its libraries left out */
    private static function files(string $moduleDir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($moduleDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $relative = substr($file->getPathname(), strlen($moduleDir) + 1);
            if (str_starts_with($relative, 'vendor/') || !in_array($file->getExtension(), ['php', 'tpl'], true)) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    /** @return list<array{0: string, 1: string}> the {l s='…' d='Modules.Sattleme.…'} of a template */
    private static function fromTemplate(string $code): array
    {
        $found = [];
        preg_match_all("/\\{l\\s+s='((?:[^'\\\\]|\\\\.)*)'([^}]*)\\}/", $code, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (preg_match("/\\bd='([^']*)'/", $match[2], $domain) === 1 && preg_match(self::DOMAIN, $domain[1]) === 1) {
                $found[] = [stripcslashes(str_replace("\\'", "'", $match[1])), $domain[1]];
            }
        }

        return $found;
    }

    /**
     * The trans() calls of a PHP file whose wording and domain (Modules.Sattleme.…) are literal
     * strings: ->trans('wording', [...], 'domain'), Wordings::trans($translator, 'wording', 'domain').
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function fromPhp(string $code, string $file): array
    {
        $tokens = array_values(array_filter(token_get_all($code), static fn ($token): bool => !is_array($token) || !in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)));
        $found = [];
        $count = count($tokens);
        for ($i = 1; $i < $count - 1; ++$i) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== \T_STRING || $tokens[$i][1] !== 'trans' || $tokens[$i + 1] !== '('
                || !is_array($tokens[$i - 1]) || !in_array($tokens[$i - 1][0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true)) {
                continue;
            }
            // The call's arguments: their literal strings, at the call's own depth.
            $depth = 0;
            $arguments = [[]];
            for ($j = $i + 1; $j < $count; ++$j) {
                $token = $tokens[$j];
                if (in_array($token, ['(', '[', '{'], true)) {
                    ++$depth;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($token, [')', ']', '}'], true)) {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($token === ',' && $depth === 1) {
                    $arguments[] = [];
                    continue;
                }
                $arguments[count($arguments) - 1][] = $token;
            }
            $literals = array_map(static fn (array $argument): ?string => count($argument) === 1 && is_array($argument[0]) && $argument[0][0] === \T_CONSTANT_ENCAPSED_STRING
                ? self::unquote($argument[0][1]) : null, $arguments);
            // The domain is the third argument; the wording the first (->trans('wording', [], 'domain'))
            // or the second (Wordings::trans($translator, 'wording', 'domain', $locale)).
            $domain = $literals[2] ?? null;
            if ($domain === null || preg_match(self::DOMAIN, $domain) !== 1) {
                continue;
            }
            $wording = $literals[0] ?? $literals[1] ?? null;
            if ($wording === null) {
                throw new \RuntimeException("{$file}: a trans() call of {$domain} whose wording is not a literal string");
            }
            $found[] = [$wording, $domain];
        }

        return $found;
    }

    /** A PHP string literal's value: single-quoted strings only (wordings are written so). */
    private static function unquote(string $literal): ?string
    {
        if (!str_starts_with($literal, "'")) {
            return null;
        }

        return str_replace(['\\\\', "\\'"], ['\\', "'"], substr($literal, 1, -1));
    }
}
