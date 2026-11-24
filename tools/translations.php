<?php

declare(strict_types=1);

/*
 * The module's translations (tools/Translations.php).
 *
 *   php tools/translations.php check    every wording of the code translated in French, German and
 *                                       Italian, no translation of a wording the code no longer has
 *   php tools/translations.php list     the wordings of the code, by domain
 *   php tools/translations.php update   rewrites each locale's files: the code's wordings, sorted,
 *                                       their translations kept, new ones left empty to translate
 */

use Sattleme\PrestaShop\Tools\Translations;

require __DIR__ . '/Translations.php';

$module = dirname(__DIR__) . '/sattleme';
$command = $argv[1] ?? '';

switch ($command) {
    case 'check':
        $problems = Translations::problems($module);
        foreach ($problems as $problem) {
            fwrite(STDERR, $problem . "\n");
        }
        if ($problems !== []) {
            fwrite(STDERR, count($problems) . " problems\n");
            exit(1);
        }
        $wordings = Translations::extract($module);
        printf("%d wordings, translated in %s\n", array_sum(array_map('count', $wordings)), implode(', ', Translations::LOCALES));
        break;
    case 'list':
        foreach (Translations::extract($module) as $domain => $texts) {
            echo "{$domain}\n";
            foreach ($texts as $text) {
                echo "  {$text}\n";
            }
        }
        break;
    case 'update':
        $wordings = Translations::extract($module);
        foreach (Translations::LOCALES as $locale) {
            foreach (Translations::DOMAINS as $domain) {
                $file = Translations::file($module, $domain, $locale);
                $existing = is_file($file) ? Translations::read($file) : [];
                $translations = [];
                foreach ($wordings[$domain] as $text) {
                    $translations[$text] = $existing[$text] ?? '';
                }
                Translations::write($file, $locale, $translations);
            }
        }
        echo "Updated; run check\n";
        break;
    default:
        fwrite(STDERR, "Usage: php tools/translations.php check|list|update\n");
        exit(2);
}
