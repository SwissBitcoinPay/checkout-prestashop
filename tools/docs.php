<?php

declare(strict_types=1);

/*
 * The module's user guides as PrestaShop Addons asks for them: docs/user-guide/readme_<language>.md
 * written as sattleme/docs/readme_<language>.pdf (tools/UserGuide.php: the same source gives the
 * same bytes), or checked against them (the CI: the PDFs committed are the ones their sources give).
 *
 * Usage: php tools/docs.php build|check
 */

use Sattleme\PrestaShop\Tools\UserGuide;

require __DIR__ . '/UserGuide.php';

$command = $argv[1] ?? '';
if (!in_array($command, ['build', 'check'], true)) {
    fwrite(STDERR, "Usage: php tools/docs.php build|check\n");
    exit(2);
}
$root = dirname(__DIR__);
$problems = 0;
foreach (UserGuide::LANGUAGES as $language) {
    $source = UserGuide::source($root, $language);
    $pdf = UserGuide::pdf($root, $language);
    $markdown = file_get_contents($source);
    if (!is_string($markdown)) {
        fwrite(STDERR, "{$source}: missing\n");
        exit(1);
    }
    try {
        $rendered = UserGuide::render($markdown, $language);
    } catch (UnexpectedValueException $e) {
        fwrite(STDERR, "{$source}: {$e->getMessage()}\n");
        exit(1);
    }
    if ($command === 'build') {
        file_put_contents($pdf, $rendered);
        echo basename($pdf), ': ', strlen($rendered), " bytes\n";
    } elseif (@file_get_contents($pdf) !== $rendered) {
        fwrite(STDERR, "{$pdf}: not what {$source} gives: php tools/docs.php build\n");
        ++$problems;
    }
}
if ($command === 'check') {
    if ($problems > 0) {
        exit(1);
    }
    echo count(UserGuide::LANGUAGES), " user guides, as their sources give them\n";
}
