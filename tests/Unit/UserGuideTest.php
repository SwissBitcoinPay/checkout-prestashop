<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\BuiltModule;
use Sattleme\PrestaShop\Tools\UserGuide;

/**
 * The user guides PrestaShop Addons asks for (docs/readme_<language>.pdf): the PDFs committed are
 * the ones their Markdown sources give (tools/docs.php check, as the CI runs it), the same bytes
 * each time; the ZIP holds them; they cover the same sections in the four languages, with no link,
 * URL or email address.
 */
final class UserGuideTest extends TestCase
{
    /** The sections of each guide, in English: install, configure, payments, cron, refunds, privacy. */
    private const SECTIONS = ['Before you start', 'Installation', 'Configuration', 'How payments and orders go', 'Background checks', 'Refunds', 'Privacy', 'If something goes wrong'];

    public function testTheCommittedPdfsAreTheOnesTheirSourcesGiveEachTime(): void
    {
        $root = BuiltModule::root();
        foreach (UserGuide::LANGUAGES as $language) {
            $markdown = (string) file_get_contents(UserGuide::source($root, $language));
            $pdf = UserGuide::render($markdown, $language);
            self::assertSame($pdf, UserGuide::render($markdown, $language), $language);
            self::assertSame($pdf, file_get_contents(UserGuide::pdf($root, $language)), $language . ': php tools/docs.php build');
            self::assertStringStartsWith("%PDF-1.4\n", $pdf);
            self::assertStringEndsWith("%%EOF\n", $pdf);
            self::assertStringContainsString('/Lang (' . $language . ')', $pdf);
            // Its cross-reference table points at its objects.
            self::assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $start));
            self::assertSame(1, preg_match('/^xref\n0 (\d+)\n0000000000 65535 f \n((?:\d{10} 00000 n \n)+)/', substr($pdf, (int) $start[1]), $xref));
            foreach (explode("\n", trim($xref[2])) as $index => $entry) {
                self::assertStringStartsWith(($index + 1) . " 0 obj\n", substr($pdf, (int) substr($entry, 0, 10)), $language);
            }
            // Its sections, as many in every language, and no link, URL or email address.
            self::assertSame(count(self::SECTIONS), preg_match_all('/^## /m', $markdown), $language);
            self::assertDoesNotMatchRegularExpression('#https?:|://|www\.|@|\]\(|\.(com|ch|org|net|io|tips|space|info)\b#i', $markdown, $language);
        }
        $english = (string) file_get_contents(UserGuide::pdf($root, 'en'));
        foreach (self::SECTIONS as $section) {
            self::assertStringContainsString('(' . $section . ') Tj', $english);
        }
        foreach (['sattleme_ck_', 'Accept bitcoin payments', 'Bitcoin payment to check', 'crontab', 'credit slip', 'GDPR'] as $subject) {
            self::assertStringContainsString($subject, $english);
        }
        self::assertStringContainsString('(Mit Bitcoin bezahlen) Tj', (string) file_get_contents(UserGuide::pdf($root, 'de')));
    }

    public function testTheZipHoldsTheFourGuides(): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(BuiltModule::zip(), \ZipArchive::RDONLY));
        foreach (UserGuide::LANGUAGES as $language) {
            self::assertSame(file_get_contents(UserGuide::pdf(BuiltModule::root(), $language)), $zip->getFromName('sattleme/docs/readme_' . $language . '.pdf'), $language);
        }
        self::assertIsString($zip->getFromName('sattleme/docs/index.php'));
        // Their sources are the repository's, not the module's.
        self::assertFalse($zip->getFromName('sattleme/docs/readme_en.md'));
        $zip->close();
    }

    public function testALinkOrWhatTheGuidesDoNotUseIsRefused(): void
    {
        foreach ([
            "# Guide\n\nSee [the site](https://example.org).\n" => 'a link, an address or markup',
            "# Guide\n\nWrite to help@example.org.\n" => 'a link, an address or markup',
            "# Guide\n\n<b>Bold</b>\n" => 'a link, an address or markup',
            "# Guide\n\n> A quote\n" => 'a link, an address or markup',
            "# Guide\n\n| A | table |\n" => 'line 3: not of the guides\' Markdown',
            "# Guide\n\nAn *emphasis*.\n" => 'emphasis other than **bold** and `code`',
            "# Guide\n\nA sign: ✓\n" => 'a character the standard fonts do not have',
            "Text first\n" => 'no title (#) first',
        ] as $markdown => $message) {
            try {
                UserGuide::render($markdown, 'en');
                self::fail($markdown);
            } catch (\UnexpectedValueException $e) {
                self::assertStringStartsWith($message, $e->getMessage(), $markdown);
            }
        }
    }
}
