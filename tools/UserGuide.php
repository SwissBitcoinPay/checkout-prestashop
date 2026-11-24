<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tools;

/**
 * The module's user guides, docs/user-guide/readme_<language>.md, as the PDFs PrestaShop Addons
 * asks for in the module's docs/ (readme_<language>.pdf). Written here, without a tool from
 * elsewhere, so that the same source gives the same bytes on any machine and PHP version: PDF 1.4,
 * A4, PDF's standard fonts (Helvetica, Helvetica-Bold, Courier: nothing embedded) in their
 * WinAnsi encoding, no date, no compression, no random identifier.
 *
 * The Markdown is a subset, and anything else is refused: a title (#), sections (##), paragraphs,
 * lists (- and 1.), **bold** and `code`. No link, no URL, no email address: Addons allows none in a
 * module's documentation.
 *
 * A development tool: never shipped in the module's ZIP (its PDFs are).
 */
final class UserGuide
{
    public const LANGUAGES = ['en', 'fr', 'de', 'it'];

    private const PAGE_WIDTH = 595;
    private const PAGE_HEIGHT = 842;
    private const MARGIN = 62;
    private const TOP = 70;
    private const BOTTOM = 72;

    /** Font resources: regular, bold, code. */
    private const FONTS = ['R' => 'Helvetica', 'B' => 'Helvetica-Bold', 'C' => 'Courier'];

    /** Widths of Helvetica's and Helvetica-Bold's ASCII glyphs (Adobe's metrics), from 32 to 126. */
    private const WIDTHS = [
        'R' => [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584],
        'B' => [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584],
    ];

    /**
     * The characters of the guides beyond ASCII: their WinAnsi byte, and the ASCII character whose
     * width they take (a letter's accented forms) or their own widths (regular, bold).
     */
    private const CHARACTERS = [
        'à' => [0xE0, 'a'], 'á' => [0xE1, 'a'], 'â' => [0xE2, 'a'], 'ä' => [0xE4, 'a'],
        'ç' => [0xE7, 'c'], 'è' => [0xE8, 'e'], 'é' => [0xE9, 'e'], 'ê' => [0xEA, 'e'], 'ë' => [0xEB, 'e'],
        'ì' => [0xEC, 't'], 'í' => [0xED, 't'], 'î' => [0xEE, 't'], 'ï' => [0xEF, 't'],
        'ò' => [0xF2, 'o'], 'ó' => [0xF3, 'o'], 'ô' => [0xF4, 'o'], 'ö' => [0xF6, 'o'],
        'ù' => [0xF9, 'u'], 'ú' => [0xFA, 'u'], 'û' => [0xFB, 'u'], 'ü' => [0xFC, 'u'],
        'À' => [0xC0, 'A'], 'Â' => [0xC2, 'A'], 'Ä' => [0xC4, 'A'], 'Ç' => [0xC7, 'C'],
        'È' => [0xC8, 'E'], 'É' => [0xC9, 'E'], 'Ê' => [0xCA, 'E'], 'Î' => [0xCE, 'I'],
        'Ô' => [0xD4, 'O'], 'Ö' => [0xD6, 'O'], 'Ù' => [0xD9, 'U'], 'Ü' => [0xDC, 'U'],
        '«' => [0xAB, [556, 556]], '»' => [0xBB, [556, 556]], '’' => [0x92, [222, 278]],
        '–' => [0x96, [556, 556]], '—' => [0x97, [1000, 1000]], '•' => [0x95, [350, 350]],
        "\u{00A0}" => [0xA0, [278, 278]], '°' => [0xB0, [400, 400]], '€' => [0x80, [556, 556]],
    ];

    private function __construct()
    {
    }

    /** The guide of $language: its source and its PDF in the repository. */
    public static function source(string $root, string $language): string
    {
        return $root . '/docs/user-guide/readme_' . $language . '.md';
    }

    public static function pdf(string $root, string $language): string
    {
        return $root . '/sattleme/docs/readme_' . $language . '.pdf';
    }

    /**
     * The PDF of a guide's Markdown.
     *
     * @throws \UnexpectedValueException Markdown beyond the subset, a link, or a character the
     *                                   standard fonts do not have
     */
    public static function render(string $markdown, string $language): string
    {
        if (preg_match('#://|www\.|@|\]\(|<|>#', $markdown, $match) === 1) {
            throw new \UnexpectedValueException('a link, an address or markup: "' . $match[0] . '"');
        }
        $blocks = self::blocks($markdown);
        $title = $blocks[0]['kind'] === 'title' ? $blocks[0]['text'] : throw new \UnexpectedValueException('no title (#) first');

        return self::document(self::layout($blocks), $title, $language);
    }

    /**
     * The guide's blocks: its title, sections, paragraphs and list items.
     *
     * @return non-empty-list<array{kind: 'title'|'section'|'paragraph'|'item', text: string, marker: string}>
     */
    private static function blocks(string $markdown): array
    {
        $blocks = [];
        $current = null;
        foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $number => $line) {
            $where = 'line ' . ($number + 1);
            if (trim($line) === '') {
                $current = null;
                continue;
            }
            if (preg_match('/^(#{1,2}) (\S.*)$/D', $line, $heading) === 1) {
                $blocks[] = ['kind' => $heading[1] === '#' ? 'title' : 'section', 'text' => $heading[2], 'marker' => ''];
                $current = null;
            } elseif (preg_match('/^(-|[1-9][0-9]?\.) (\S.*)$/D', $line, $item) === 1) {
                $blocks[] = ['kind' => 'item', 'text' => $item[2], 'marker' => $item[1] === '-' ? '•' : $item[1]];
                $current = count($blocks) - 1;
            } elseif ($current !== null && preg_match('/^ {2,3}(\S.*)$/D', $line, $more) === 1) {
                $blocks[$current] = ['kind' => $blocks[$current]['kind'], 'text' => $blocks[$current]['text'] . ' ' . $more[1], 'marker' => $blocks[$current]['marker']];
            } elseif (preg_match('/^(\*\*|[^\s#>|*+`-])/', $line) === 1) {
                if ($current !== null && $blocks[$current]['kind'] === 'paragraph') {
                    $blocks[$current] = ['kind' => 'paragraph', 'text' => $blocks[$current]['text'] . ' ' . $line, 'marker' => ''];
                } else {
                    $blocks[] = ['kind' => 'paragraph', 'text' => $line, 'marker' => ''];
                    $current = count($blocks) - 1;
                }
            } else {
                throw new \UnexpectedValueException($where . ': not of the guides\' Markdown');
            }
        }
        if ($blocks === []) {
            throw new \UnexpectedValueException('empty');
        }

        return $blocks;
    }

    /**
     * The blocks laid out on pages: each page a list of text lines, each a list of runs (font,
     * size, x, text) at a baseline.
     *
     * @param non-empty-list<array{kind: 'title'|'section'|'paragraph'|'item', text: string, marker: string}> $blocks
     *
     * @return list<list<array{y: float, runs: list<array{font: string, size: float, x: float, text: string}>}>>
     */
    private static function layout(array $blocks): array
    {
        $pages = [[]];
        $y = self::PAGE_HEIGHT - self::TOP;
        $width = self::PAGE_WIDTH - 2 * self::MARGIN;
        foreach ($blocks as $index => $block) {
            [$size, $leading, $before, $after, $font] = match ($block['kind']) {
                'title' => [20.0, 26.0, 0.0, 10.0, 'B'],
                'section' => [13.0, 17.0, 12.0, 3.0, 'B'],
                default => [10.5, 14.5, 0.0, 6.0, 'R'],
            };
            $indent = $block['kind'] === 'item' ? 16.0 : 0.0;
            // French punctuation stays with its word: no line starts with ":" or "»".
            $text = (string) preg_replace(['/ (?=[:;!?»])/u', '/(?<=«) /u'], "\u{00A0}", $block['text']);
            $lines = self::wrap(self::runs($text, $font), $size, $width - $indent);
            // A section keeps its heading with the first lines of what follows.
            $needed = $before + count($lines) * $leading + ($block['kind'] === 'section' ? 3 * 14.5 : 0.0);
            if ($index > 0 && $y - $needed < self::BOTTOM) {
                $pages[] = [];
                $y = self::PAGE_HEIGHT - self::TOP;
            } elseif ($pages[count($pages) - 1] !== []) {
                $y -= $before;
            }
            foreach ($lines as $number => $line) {
                if ($y - $leading < self::BOTTOM) {
                    $pages[] = [];
                    $y = self::PAGE_HEIGHT - self::TOP;
                }
                $y -= $leading;
                $runs = [];
                if ($number === 0 && $block['marker'] !== '') {
                    $runs[] = ['font' => 'R', 'size' => $size, 'x' => (float) self::MARGIN + ($block['marker'] === '•' ? 4.0 : 0.0), 'text' => $block['marker']];
                }
                $x = self::MARGIN + $indent;
                foreach ($line as [$runFont, $text]) {
                    $runs[] = ['font' => $runFont, 'size' => $size, 'x' => $x, 'text' => $text];
                    $x += self::width($runFont, $text, $size);
                }
                $pages[count($pages) - 1][] = ['y' => $y, 'runs' => $runs];
            }
            $y -= $after;
        }

        return $pages;
    }

    /**
     * A block's text as runs of one font: **bold**, `code`, the rest in $font.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function runs(string $text, string $font): array
    {
        $runs = [];
        $parts = preg_split('/(\*\*[^*]+\*\*|`[^`]+`)/', $text, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
        foreach (is_array($parts) ? $parts : [] as $part) {
            if (str_starts_with($part, '**')) {
                $runs[] = ['B', substr($part, 2, -2)];
            } elseif (str_starts_with($part, '`')) {
                $runs[] = ['C', substr($part, 1, -1)];
            } elseif (str_contains($part, '*') || str_contains($part, '`') || str_contains($part, '_')) {
                throw new \UnexpectedValueException('emphasis other than **bold** and `code`: ' . $part);
            } else {
                $runs[] = [$font, $part];
            }
        }

        return $runs;
    }

    /**
     * Runs broken into lines no wider than $width, at spaces.
     *
     * @param list<array{0: string, 1: string}> $runs
     *
     * @return list<list<array{0: string, 1: string}>>
     */
    private static function wrap(array $runs, float $size, float $width): array
    {
        // Words, each of one font or more ("**Configure**." is one word), and whether a space
        // precedes them.
        $words = [];
        $spaced = [];
        $word = [];
        $wordSpaced = false;
        $space = false;
        foreach ($runs as [$font, $text]) {
            foreach (explode(' ', $text) as $index => $part) {
                $space = $space || $index > 0;
                if ($part === '') {
                    continue;
                }
                if ($word !== [] && $space) {
                    $words[] = $word;
                    $spaced[] = $wordSpaced;
                    $word = [];
                }
                if ($word === []) {
                    $wordSpaced = $space;
                }
                $word[] = [$font, $part];
                $space = false;
            }
        }
        if ($word !== []) {
            $words[] = $word;
            $spaced[] = $wordSpaced;
        }
        $lines = [];
        $line = [];
        $used = 0.0;
        foreach ($words as $index => $pieces) {
            $wordWidth = 0.0;
            foreach ($pieces as [$font, $piece]) {
                $wordWidth += self::width($font, $piece, $size);
            }
            $gap = $line !== [] && $spaced[$index] ? self::width('R', ' ', $size) : 0.0;
            if ($line !== [] && $used + $gap + $wordWidth > $width) {
                $lines[] = $line;
                $line = [];
                $used = 0.0;
                $gap = 0.0;
            }
            $before = $line === [] ? 'R' : $line[array_key_last($line)][0];
            if ($gap > 0) {
                // A space in the font before it (a code's space is a regular one).
                $line[] = [$before === 'C' ? 'R' : $before, ' '];
            }
            foreach ($pieces as $piece) {
                $line[] = $piece;
            }
            $used += $gap + $wordWidth;
        }
        if ($line !== []) {
            $lines[] = $line;
        }

        // Runs of one font joined.
        $joined = [];
        foreach ($lines as $line) {
            $runsOfLine = [];
            [$runFont, $runText] = $line[0];
            foreach (array_slice($line, 1) as [$font, $text]) {
                if ($font === $runFont) {
                    $runText .= $text;
                    continue;
                }
                $runsOfLine[] = [$runFont, $runText];
                [$runFont, $runText] = [$font, $text];
            }
            $runsOfLine[] = [$runFont, $runText];
            $joined[] = $runsOfLine;
        }

        return $joined;
    }

    /** The width of $text in $font at $size, in points. */
    private static function width(string $font, string $text, float $size): float
    {
        $units = 0;
        foreach (mb_str_split($text) as $character) {
            if ($font === 'C') {
                $units += 600;
                continue;
            }
            $known = self::CHARACTERS[$character] ?? null;
            if ($known !== null) {
                $character = $known[1];
                if (is_array($character)) {
                    $units += $character[$font === 'B' ? 1 : 0];
                    continue;
                }
            }
            $code = ord($character);
            if (strlen($character) !== 1 || $code < 32 || $code > 126) {
                throw new \UnexpectedValueException('a character the standard fonts do not have: ' . $character);
            }
            $units += self::WIDTHS[$font === 'B' ? 'B' : 'R'][$code - 32];
        }

        return $units * $size / 1000;
    }

    /** $text as a PDF string in WinAnsi, its bytes beyond ASCII and its delimiters escaped. */
    private static function string(string $text): string
    {
        $bytes = '';
        foreach (mb_str_split($text) as $character) {
            $known = self::CHARACTERS[$character] ?? null;
            $code = $known !== null ? $known[0] : ord($character);
            if ($known === null && (strlen($character) !== 1 || $code < 32 || $code > 126)) {
                throw new \UnexpectedValueException('a character the standard fonts do not have: ' . $character);
            }
            $bytes .= match (true) {
                $code > 126 => sprintf('\\%03o', $code),
                in_array(chr($code), ['(', ')', '\\'], true) => '\\' . chr($code),
                default => chr($code),
            };
        }

        return '(' . $bytes . ')';
    }

    /** $text as a PDF text string: UTF-16BE, its byte order mark first, in hexadecimal. */
    private static function textString(string $text): string
    {
        return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    /** A number as PDF writes it, the same on any machine. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
    }

    /**
     * The PDF file: its catalog, pages, fonts and information, each page numbered at its foot.
     *
     * @param list<list<array{y: float, runs: list<array{font: string, size: float, x: float, text: string}>}>> $pages
     */
    private static function document(array $pages, string $title, string $language): string
    {
        $objects = [];
        $fonts = [];
        foreach (self::FONTS as $name => $base) {
            $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $base . ' /Encoding /WinAnsiEncoding >>';
            $fonts[] = '/' . $name . ' ' . count($objects) . ' 0 R';
        }
        $pagesId = count($objects) + 1;
        $objects[] = '';
        $kids = [];
        $total = count($pages);
        foreach ($pages as $number => $lines) {
            $content = '';
            foreach ($lines as $line) {
                foreach ($line['runs'] as $run) {
                    $content .= 'BT /' . $run['font'] . ' ' . self::number($run['size']) . ' Tf ' . self::number($run['x']) . ' ' . self::number($line['y']) . ' Td ' . self::string($run['text']) . " Tj ET\n";
                }
            }
            $foot = ($number + 1) . ' / ' . $total;
            $content .= 'BT /R 9 Tf ' . self::number(self::PAGE_WIDTH / 2 - self::width('R', $foot, 9) / 2) . ' 40 Td ' . self::string($foot) . " Tj ET\n";
            $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
            $objects[] = '<< /Type /Page /Parent ' . $pagesId . ' 0 R /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] /Resources << /Font << ' . implode(' ', $fonts) . ' >> >> /Contents ' . count($objects) . ' 0 R >>';
            $kids[] = count($objects) . ' 0 R';
        }
        $objects[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>';
        $objects[] = '<< /Type /Catalog /Pages ' . $pagesId . ' 0 R /Lang (' . $language . ') >>';
        $catalog = count($objects);
        $objects[] = '<< /Title ' . self::textString($title) . ' /Producer (sattle.me tools/UserGuide.php) >>';
        $info = count($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . 'trailer' . "\n<< /Size " . (count($objects) + 1) . ' /Root ' . $catalog . ' 0 R /Info ' . $info . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
