<?php

/**
 * sattle.me for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License that is bundled with this module in the file
 * LICENSE, also available at https://opensource.org/licenses/MIT
 *
 * @author    sattle.me
 * @copyright 2026 sattle.me
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace Sattleme\PrestaShop;

use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's wordings in a given locale, whatever the request's language: the order states' names
 * and the method's title and description stored at installation in each of the shop's languages,
 * the notes written for the merchant in the shop's language. PrestaShop's translator holds the
 * catalogues of its request's language only, and the module's ones only once it found them in the
 * module's directory (a module uploaded and installed in one request is not there yet). So a
 * wording the translator leaves untranslated comes from the module's own translation files
 * (translations/<locale>/ModulesSattleme<Part>.<locale>.xlf).
 */
final class Wordings
{
    /** @var array<string, array<string, string>> the module's catalogues read, by domain and locale */
    private static $catalogues = [];

    private function __construct()
    {
    }

    /**
     * $text in $locale, its parameters replaced: the translator's (its wordings, the merchant's),
     * else the module's file's.
     *
     * @param array<string, string> $parameters
     */
    public static function trans(TranslatorInterface $translator, string $text, string $domain, string $locale, array $parameters = []): string
    {
        $translated = $translator->trans($text, $parameters, $domain, $locale);
        if ($translated !== strtr($text, $parameters)) {
            return $translated;
        }
        $own = self::catalogue($domain, $locale)[$text] ?? null;

        return $own === null ? $translated : strtr($own, $parameters);
    }

    /** @return array<string, string> source => translation, of the module's file of $domain in $locale */
    private static function catalogue(string $domain, string $locale): array
    {
        $key = $domain . '|' . $locale;
        if (isset(self::$catalogues[$key])) {
            return self::$catalogues[$key];
        }
        self::$catalogues[$key] = [];
        if (preg_match('/^Modules\.Sattleme\.(Admin|Shop)$/D', $domain, $part) !== 1 || preg_match('/^[a-z]{2,3}-[A-Z]{2}$/D', $locale) !== 1) {
            return [];
        }
        $file = self::directory() . '/' . $locale . '/ModulesSattleme' . $part[1] . '.' . $locale . '.xlf';
        $document = new \DOMDocument();
        if (!is_file($file) || !$document->load($file, LIBXML_NONET)) {
            return [];
        }
        foreach ($document->getElementsByTagName('trans-unit') as $unit) {
            $source = $unit->getElementsByTagName('source')->item(0);
            $target = $unit->getElementsByTagName('target')->item(0);
            if ($source !== null && $target !== null && $target->textContent !== '') {
                self::$catalogues[$key][$source->textContent] = $target->textContent;
            }
        }

        return self::$catalogues[$key];
    }

    /** The module's translations/ directory (this file is the module's src/Wordings.php). */
    public static function directory(): string
    {
        return dirname(__DIR__) . '/translations';
    }
}
