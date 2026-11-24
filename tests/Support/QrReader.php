<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Support;

/**
 * Reads a QR code back into its text, as a wallet's scanner reads it: written from the standard
 * (ISO/IEC 18004), apart from the module's encoder. It checks the code's finder, timing and
 * alignment patterns, reads its version and format information, unmasks it, reads its codewords
 * between the function patterns, checks each block's error correction (its syndromes are zero:
 * nothing to correct), then decodes its segments (numeric, alphanumeric, byte). Level M only, the
 * module's level (View\Qr).
 */
final class QrReader
{
    /**
     * Level M's blocks, by version: error correction codewords per block, the blocks of the first
     * group and their data codewords, and the blocks of the second group (one data codeword more).
     */
    private const BLOCKS_M = [
        1 => [10, 1, 16, 0], 2 => [16, 1, 28, 0], 3 => [26, 1, 44, 0], 4 => [18, 2, 32, 0],
        5 => [24, 2, 43, 0], 6 => [16, 4, 27, 0], 7 => [18, 4, 31, 0], 8 => [22, 2, 38, 2],
        9 => [22, 3, 36, 2], 10 => [26, 4, 43, 1], 11 => [30, 1, 50, 4], 12 => [22, 6, 36, 2],
        13 => [22, 8, 37, 1], 14 => [24, 4, 40, 5], 15 => [24, 5, 41, 5], 16 => [28, 7, 45, 3],
        17 => [28, 10, 46, 1], 18 => [26, 9, 43, 4], 19 => [26, 3, 44, 11], 20 => [26, 3, 41, 13],
        21 => [26, 17, 42, 0], 22 => [28, 17, 46, 0], 23 => [28, 4, 47, 14], 24 => [28, 6, 45, 14],
        25 => [28, 8, 47, 13], 26 => [28, 19, 46, 4], 27 => [28, 22, 45, 3], 28 => [28, 3, 45, 23],
        29 => [28, 21, 45, 7], 30 => [28, 19, 47, 10], 31 => [28, 2, 46, 29], 32 => [28, 10, 46, 23],
        33 => [28, 14, 46, 21], 34 => [28, 14, 46, 23], 35 => [28, 12, 47, 26], 36 => [28, 6, 47, 34],
        37 => [28, 29, 46, 14], 38 => [28, 13, 46, 32], 39 => [28, 40, 47, 7], 40 => [28, 18, 47, 31],
    ];

    private const ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    private function __construct()
    {
    }

    /** The text of the QR code an <img>'s src shows: a base64 data URI of the module's SVG. */
    public static function image(string $src): string
    {
        $prefix = 'data:image/svg+xml;base64,';
        if (!str_starts_with($src, $prefix)) {
            throw new \UnexpectedValueException('not a base64 SVG data URI: ' . substr($src, 0, 40));
        }
        $svg = base64_decode(substr($src, strlen($prefix)), true);
        if ($svg === false) {
            throw new \UnexpectedValueException('not base64');
        }

        return self::svg($svg);
    }

    /**
     * The text of the QR code an SVG of the module's draws (View\Qr: a white square, then the dark
     * modules as one path of horizontal runs, M<x> <y>h<width>v1h-<width>z, a quiet zone around).
     */
    public static function svg(string $svg, int $quietZone = 4): string
    {
        $document = new \DOMDocument();
        if (!$document->loadXML($svg, \LIBXML_NONET)) {
            throw new \UnexpectedValueException('not XML');
        }
        $root = $document->documentElement;
        $path = $document->getElementsByTagName('path')->item(0);
        if ($root === null || $root->localName !== 'svg' || $path === null || preg_match('/^0 0 (\d+) \1$/D', $root->getAttribute('viewBox'), $box) !== 1) {
            throw new \UnexpectedValueException('not a square SVG with a path');
        }
        $size = (int) $box[1] - 2 * $quietZone;
        if ($size < 21) {
            throw new \UnexpectedValueException('too small for a QR code');
        }
        $d = $path->getAttribute('d');
        if (preg_match('/^(?:M\d+ \d+h\d+v1h-\d+z)+$/D', $d) !== 1) {
            throw new \UnexpectedValueException('a path of other shapes than runs');
        }
        preg_match_all('/M(\d+) (\d+)h(\d+)v1h-(\d+)z/', $d, $runs, \PREG_SET_ORDER);
        $modules = array_fill(0, $size, array_fill(0, $size, 0));
        foreach ($runs as [, $x, $y, $width, $back]) {
            $row = (int) $y - $quietZone;
            $start = (int) $x - $quietZone;
            if ($width !== $back || $row < 0 || $row >= $size || $start < 0 || $start + (int) $width > $size) {
                throw new \UnexpectedValueException('a run in the quiet zone, or not closed');
            }
            for ($i = 0; $i < (int) $width; ++$i) {
                $modules[$row][$start + $i] = 1;
            }
        }

        return self::decode($modules);
    }

    /**
     * The text of a QR code given as its modules, by row, dark as 1 (View\Qr::modules' form).
     *
     * @param array<int, array<int, int>> $modules
     */
    public static function decode(array $modules): string
    {
        $size = count($modules);
        $version = intdiv($size - 17, 4);
        if ($size < 21 || ($size - 17) % 4 !== 0 || $version > 40) {
            throw new \UnexpectedValueException('not the size of a QR code: ' . $size);
        }
        foreach ($modules as $row) {
            if (!array_is_list($modules) || !array_is_list($row) || count($row) !== $size) {
                throw new \UnexpectedValueException('not square');
            }
        }
        $dark = static fn (int $x, int $y): bool => $modules[$y][$x] === 1;
        $function = self::functionPatterns($dark, $version, $size);
        if ($version >= 7 && self::versionInformation($dark, $size) !== $version) {
            throw new \UnexpectedValueException('its version information is not its size\'s version');
        }
        [$level, $mask] = self::format($dark, $size);
        if ($level !== 0) {
            throw new \UnexpectedValueException('level ' . $level . ', not M');
        }

        // Its codewords: two columns at a time from the right, up then down, the timing column
        // skipped, each module not of a function pattern unmasked.
        $codewords = [];
        $byte = 0;
        $bits = 0;
        $up = true;
        for ($x = $size - 1; $x > 0; $x -= 2) {
            if ($x === 6) {
                --$x;
            }
            for ($count = 0; $count < $size; ++$count) {
                $y = $up ? $size - 1 - $count : $count;
                for ($column = $x; $column > $x - 2; --$column) {
                    if (isset($function[$y][$column])) {
                        continue;
                    }
                    $byte = ($byte << 1) | ($dark($column, $y) !== self::masked($mask, $y, $column) ? 1 : 0);
                    if (++$bits === 8) {
                        $codewords[] = $byte;
                        $byte = 0;
                        $bits = 0;
                    }
                }
            }
            $up = !$up;
        }

        // Its blocks, interleaved: data codewords one block after the other, the longer blocks'
        // last one, then error correction codewords the same way.
        [$ec, $first, $data, $second] = self::BLOCKS_M[$version];
        $blocks = $first + $second;
        if (count($codewords) !== $first * ($data + $ec) + $second * ($data + 1 + $ec)) {
            throw new \UnexpectedValueException('not as many codewords as version ' . $version . ' holds');
        }
        $read = array_fill(0, $blocks, []);
        $offset = 0;
        for ($i = 0; $i < $data; ++$i) {
            for ($block = 0; $block < $blocks; ++$block) {
                $read[$block][] = $codewords[$offset++];
            }
        }
        for ($block = $first; $block < $blocks; ++$block) {
            $read[$block][] = $codewords[$offset++];
        }
        $stream = [];
        foreach ($read as $words) {
            $stream = array_merge($stream, $words);
        }
        for ($i = 0; $i < $ec; ++$i) {
            for ($block = 0; $block < $blocks; ++$block) {
                $read[$block][] = $codewords[$offset++];
            }
        }
        foreach ($read as $block => $words) {
            if (!self::correct($words, $ec)) {
                throw new \UnexpectedValueException('block ' . $block . ': its error correction finds errors');
            }
        }

        return self::segments($stream, $version);
    }

    /**
     * Where the function patterns are, each checked: finders, timing, alignment, format and
     * version information, the dark module.
     *
     * @param \Closure(int, int): bool $dark
     *
     * @return array<int, array<int, true>> by row, then column
     */
    private static function functionPatterns(\Closure $dark, int $version, int $size): array
    {
        $function = [];
        $region = static function (int $left, int $top, int $width, int $height) use (&$function): void {
            for ($y = $top; $y < $top + $height; ++$y) {
                for ($x = $left; $x < $left + $width; ++$x) {
                    $function[$y][$x] = true;
                }
            }
        };
        // The finders, their separators and the format information.
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$left, $top]) {
            for ($dy = 0; $dy < 7; ++$dy) {
                for ($dx = 0; $dx < 7; ++$dx) {
                    $ring = max(abs($dx - 3), abs($dy - 3));
                    if ($dark($left + $dx, $top + $dy) !== ($ring !== 2)) {
                        throw new \UnexpectedValueException('no finder at ' . $left . ', ' . $top);
                    }
                }
            }
        }
        $region(0, 0, 9, 9);
        $region($size - 8, 0, 8, 9);
        $region(0, $size - 8, 9, 8);
        if (!$dark(8, $size - 8)) {
            throw new \UnexpectedValueException('no dark module');
        }
        // The timing patterns.
        for ($i = 8; $i < $size - 8; ++$i) {
            if ($dark($i, 6) !== ($i % 2 === 0) || $dark(6, $i) !== ($i % 2 === 0)) {
                throw new \UnexpectedValueException('no timing pattern');
            }
        }
        $region(6, 9, 1, $size - 17);
        $region(9, 6, $size - 17, 1);
        // The alignment patterns, but where the finders are.
        $centers = self::alignment($version, $size);
        $last = count($centers) - 1;
        foreach ($centers as $i => $cy) {
            foreach ($centers as $j => $cx) {
                if (($i === 0 && ($j === 0 || $j === $last)) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; ++$dy) {
                    for ($dx = -2; $dx <= 2; ++$dx) {
                        if ($dark($cx + $dx, $cy + $dy) !== (max(abs($dx), abs($dy)) !== 1)) {
                            throw new \UnexpectedValueException('no alignment pattern at ' . $cx . ', ' . $cy);
                        }
                    }
                }
                $region($cx - 2, $cy - 2, 5, 5);
            }
        }
        // The version information.
        if ($version >= 7) {
            $region($size - 11, 0, 3, 6);
            $region(0, $size - 11, 6, 3);
        }

        return $function;
    }

    /**
     * The centers of the alignment patterns, on each axis.
     *
     * @return list<int>
     */
    private static function alignment(int $version, int $size): array
    {
        if ($version === 1) {
            return [];
        }
        $count = intdiv($version, 7) + 2;
        $step = $version === 32 ? 26 : intdiv($version * 4 + $count * 2 + 1, $count * 2 - 2) * 2;
        $centers = [6];
        for ($i = 1, $position = $size - 7 - ($count - 2) * $step; $i < $count; ++$i, $position += $step) {
            $centers[] = $position;
        }

        return $centers;
    }

    /**
     * The level (M: 0) and mask of the format information, both of its copies read and equal.
     *
     * @param \Closure(int, int): bool $dark
     *
     * @return array{0: int, 1: int}
     */
    private static function format(\Closure $dark, int $size): array
    {
        $positions = [[0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8], [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0]];
        $copy = [];
        for ($y = $size - 1; $y >= $size - 7; --$y) {
            $copy[] = [8, $y];
        }
        for ($x = $size - 8; $x < $size; ++$x) {
            $copy[] = [$x, 8];
        }
        $bits = self::bits($dark, $positions);
        if (self::bits($dark, $copy) !== $bits) {
            throw new \UnexpectedValueException('its two copies of the format information differ');
        }
        for ($information = 0; $information < 32; ++$information) {
            if ((self::bch($information, 0x537, 10) ^ 0x5412) === $bits) {
                return [$information >> 3, $information & 7];
            }
        }
        throw new \UnexpectedValueException('no format information');
    }

    /**
     * The modules at $positions (x, y), dark as 1, the first as the most significant bit.
     *
     * @param \Closure(int, int): bool $dark
     * @param list<array{0: int, 1: int}> $positions
     */
    private static function bits(\Closure $dark, array $positions): int
    {
        $bits = 0;
        foreach ($positions as [$x, $y]) {
            $bits = ($bits << 1) | ($dark($x, $y) ? 1 : 0);
        }

        return $bits;
    }

    /**
     * The version its version information gives, both of its copies read and equal.
     *
     * @param \Closure(int, int): bool $dark
     */
    private static function versionInformation(\Closure $dark, int $size): int
    {
        $topRight = 0;
        $bottomLeft = 0;
        for ($y = 5; $y >= 0; --$y) {
            for ($x = $size - 9; $x >= $size - 11; --$x) {
                $topRight = ($topRight << 1) | ($dark($x, $y) ? 1 : 0);
                $bottomLeft = ($bottomLeft << 1) | ($dark($y, $x) ? 1 : 0);
            }
        }
        if ($topRight !== $bottomLeft || self::bch($topRight >> 12, 0x1F25, 12) !== $topRight) {
            throw new \UnexpectedValueException('no version information');
        }

        return $topRight >> 12;
    }

    /** $data followed by its BCH code's remainder of $generator, of $degree bits. */
    private static function bch(int $data, int $generator, int $degree): int
    {
        $remainder = $data << $degree;
        for ($bit = 31; $bit >= $degree; --$bit) {
            if (($remainder >> $bit & 1) === 1) {
                $remainder ^= $generator << ($bit - $degree);
            }
        }

        return ($data << $degree) | $remainder;
    }

    /** Whether the mask darkens the module at $row, $column. */
    private static function masked(int $mask, int $row, int $column): bool
    {
        return match ($mask) {
            0 => ($row + $column) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $column % 3 === 0,
            3 => ($row + $column) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($column, 3)) % 2 === 0,
            5 => ($row * $column) % 2 + ($row * $column) % 3 === 0,
            6 => (($row * $column) % 2 + ($row * $column) % 3) % 2 === 0,
            default => (($row + $column) % 2 + ($row * $column) % 3) % 2 === 0,
        };
    }

    /**
     * Whether a block's codewords (data, then $ec error correction codewords) are a Reed-Solomon
     * codeword of GF(256) (x^8 + x^4 + x^3 + x^2 + 1): its syndromes at α^0 to α^($ec - 1) are zero.
     *
     * @param list<int> $words
     */
    private static function correct(array $words, int $ec): bool
    {
        $exp = [];
        $log = [];
        for ($i = 0, $value = 1; $i < 255; ++$i) {
            $exp[$i] = $value;
            $log[$value] = $i;
            $value <<= 1;
            if ($value > 255) {
                $value ^= 0x11D;
            }
        }
        for ($i = 0; $i < $ec; ++$i) {
            $syndrome = 0;
            foreach ($words as $word) {
                $syndrome = ($syndrome === 0 ? 0 : $exp[($log[$syndrome] + $i) % 255]) ^ $word;
            }
            if ($syndrome !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The text of its data codewords' segments, until the terminator or their end.
     *
     * @param list<int> $stream
     */
    private static function segments(array $stream, int $version): string
    {
        $position = 0;
        $available = count($stream) * 8;
        $read = static function (int $count) use ($stream, &$position, $available): int {
            if ($position + $count > $available) {
                throw new \UnexpectedValueException('a segment longer than the data');
            }
            $value = 0;
            for ($i = 0; $i < $count; ++$i, ++$position) {
                $value = ($value << 1) | ($stream[intdiv($position, 8)] >> (7 - $position % 8) & 1);
            }

            return $value;
        };
        $class = $version <= 9 ? 0 : ($version <= 26 ? 1 : 2);
        $text = '';
        while ($available - $position >= 4) {
            $mode = $read(4);
            if ($mode === 0) {
                break;
            }
            switch ($mode) {
                case 1:
                    $length = $read([10, 12, 14][$class]);
                    for (; $length >= 3; $length -= 3) {
                        $text .= str_pad((string) $read(10), 3, '0', \STR_PAD_LEFT);
                    }
                    if ($length > 0) {
                        $text .= str_pad((string) $read($length === 2 ? 7 : 4), $length, '0', \STR_PAD_LEFT);
                    }
                    break;
                case 2:
                    $length = $read([9, 11, 13][$class]);
                    for (; $length >= 2; $length -= 2) {
                        $pair = $read(11);
                        $text .= self::ALPHANUMERIC[intdiv($pair, 45)] . self::ALPHANUMERIC[$pair % 45];
                    }
                    if ($length === 1) {
                        $text .= self::ALPHANUMERIC[$read(6)];
                    }
                    break;
                case 4:
                    $length = $read([8, 16, 16][$class]);
                    for ($i = 0; $i < $length; ++$i) {
                        $text .= chr($read(8));
                    }
                    break;
                default:
                    throw new \UnexpectedValueException('a segment of mode ' . $mode . ', not numeric, alphanumeric or byte');
            }
        }

        return $text;
    }
}
