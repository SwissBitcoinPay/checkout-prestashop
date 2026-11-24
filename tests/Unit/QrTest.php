<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\Attempts;
use Sattleme\PrestaShop\Tests\Support\QrReader;
use Sattleme\PrestaShop\View\Qr;

/**
 * The QR code: an SVG made on the server, whose dark modules are exactly the code's, shown as an
 * <img>'s base64 data URI; read back as a scanner reads it (Support\QrReader, written from the
 * standard), it is its text.
 */
final class QrTest extends TestCase
{
    public function testItsImageReadsAsTheInvoicesPaymentUri(): void
    {
        $uri = Qr::dataUri(Attempts::URI, 'QR code of the bitcoin invoice');
        self::assertIsString($uri);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        // Base64's characters only: escaping it for HTML changes nothing.
        self::assertSame($uri, htmlentities($uri, ENT_QUOTES, 'UTF-8'));
        self::assertSame(Qr::svg(Attempts::URI, 'QR code of the bitcoin invoice'), base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true));
        self::assertSame(Attempts::URI, QrReader::image($uri));
        self::assertSame('lightning:' . Attempts::BOLT11, QrReader::image((string) Qr::dataUri('lightning:' . Attempts::BOLT11, 'QR')));
    }

    public function testEveryVersionOfTheCodeReadsAsItsText(): void
    {
        // The longest text each version of a level M code takes in bytes (the standard's table):
        // the encoder takes it in that version, and it reads back as itself, versions 1 to 40.
        $longest = [14, 26, 42, 62, 84, 106, 122, 152, 180, 213, 251, 287, 331, 362, 412, 450, 504, 560, 624, 666,
            711, 779, 857, 911, 997, 1059, 1125, 1190, 1264, 1370, 1452, 1538, 1628, 1722, 1809, 1911, 1989, 2099, 2213, 2331];
        foreach ($longest as $index => $length) {
            $text = substr(str_repeat(Attempts::URI, 8), 0, $length);
            $svg = Qr::svg($text, 'QR');
            self::assertNotNull($svg, (string) $length);
            self::assertStringContainsString(sprintf('viewBox="0 0 %1$d %1$d"', 17 + 4 * ($index + 1) + 2 * Qr::QUIET_ZONE), $svg, 'version ' . ($index + 1));
            self::assertSame($text, QrReader::svg($svg), 'version ' . ($index + 1));
        }
        self::assertNull(Qr::svg(str_repeat('a', 2332), 'QR'), 'longer than a level M code takes');
        // A module changed (its last, a data module): the reader finds the error, as a scanner.
        $damaged = (array) Qr::modules(Attempts::URI);
        $last = count($damaged) - 1;
        $damaged[$last][$last] = 1 - $damaged[$last][$last];
        try {
            QrReader::decode($damaged);
            self::fail('a damaged code read');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('its error correction finds errors', $e->getMessage());
        }
        // As the encoder chooses them: digits only, the alphanumeric set, bytes.
        foreach (['0', '12345678901234567', 'BITCOIN:' . strtoupper(Attempts::ADDRESS), 'LIGHTNING:' . strtoupper(Attempts::BOLT11), Attempts::URI] as $text) {
            self::assertSame($text, QrReader::decode((array) Qr::modules($text)), $text);
        }
    }

    public function testTheSvgDrawsExactlyTheCodesModules(): void
    {
        $modules = Qr::modules(Attempts::URI);
        self::assertNotNull($modules);
        $size = count($modules);
        self::assertGreaterThanOrEqual(21, $size);
        self::assertSame(0, ($size - 17) % 4, 'a QR code is 17 + 4 × version modules wide');

        $svg = Qr::svg(Attempts::URI, 'QR code of the bitcoin invoice');
        self::assertNotNull($svg);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($svg));
        $root = $document->documentElement;
        self::assertNotNull($root);
        $side = $size + 2 * Qr::QUIET_ZONE;
        self::assertSame("0 0 {$side} {$side}", $root->getAttribute('viewBox'));
        self::assertSame('img', $root->getAttribute('role'));

        // Draws the path's runs back into a grid: it must be the code, quiet zone around it.
        $path = $document->getElementsByTagName('path')->item(0);
        self::assertNotNull($path);
        preg_match_all('/M(\d+) (\d+)h(\d+)v1h-(\d+)z/', $path->getAttribute('d'), $runs, PREG_SET_ORDER);
        $drawn = array_fill(0, $size, array_fill(0, $size, 0));
        foreach ($runs as [, $x, $y, $width, $back]) {
            self::assertSame($width, $back);
            for ($i = 0; $i < (int) $width; ++$i) {
                $drawn[(int) $y - Qr::QUIET_ZONE][(int) $x - Qr::QUIET_ZONE + $i] = 1;
            }
        }
        self::assertSame($modules, $drawn);
    }

    public function testItsLabelIsEscaped(): void
    {
        $svg = Qr::svg('lightning:lnbc1', '"><script>alert(1)</script>');
        self::assertNotNull($svg);
        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('aria-label="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $svg);
    }

    public function testATextTooLongForAQrCodeHasNone(): void
    {
        self::assertNull(Qr::svg(str_repeat('a', 8000), 'QR'));
        self::assertNull(Qr::modules(str_repeat('a', 8000)));
    }
}
