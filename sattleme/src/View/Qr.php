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

namespace Sattleme\PrestaShop\View;

use Sattleme\PrestaShop\Vendor\BaconQrCode\Common\ErrorCorrectionLevel;
use Sattleme\PrestaShop\Vendor\BaconQrCode\Encoder\Encoder;
use Sattleme\PrestaShop\Vendor\BaconQrCode\Exception\ExceptionInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A text as a QR code: an SVG image made on the server (bacon/bacon-qr-code's encoder, embedded
 * and prefixed), with no script and no request to anyone, shown as an <img> of a base64 data URI
 * (the template escapes it as any value: no markup of the module's written unescaped). The dark
 * modules are one path of horizontal runs, on a white background of its own with the standard's
 * quiet zone, so that it scans whatever the page's colours.
 */
final class Qr
{
    /** Modules of white around the code, as the QR code standard asks for. */
    public const QUIET_ZONE = 4;

    private function __construct()
    {
    }

    /** The SVG of $text, labelled $label; null when the text is too long for a QR code. */
    public static function svg(string $text, string $label): ?string
    {
        try {
            $matrix = Encoder::encode($text, ErrorCorrectionLevel::M())->getMatrix();
        } catch (ExceptionInterface) {
            return null;
        }
        $width = $matrix->getWidth();
        $size = $width + 2 * self::QUIET_ZONE;
        $runs = '';
        for ($y = 0; $y < $matrix->getHeight(); ++$y) {
            $x = 0;
            while ($x < $width) {
                if ($matrix->get($x, $y) !== 1) {
                    ++$x;
                    continue;
                }
                $start = $x;
                while ($x < $width && $matrix->get($x, $y) === 1) {
                    ++$x;
                }
                $runs .= 'M' . ($start + self::QUIET_ZONE) . ' ' . ($y + self::QUIET_ZONE) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" shape-rendering="crispEdges">'
            . '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/><path fill="#000" d="' . $runs . '"/></svg>';
    }

    /** The SVG of $text as a base64 data URI, for an <img>'s src; null as svg(). */
    public static function dataUri(string $text, string $label): ?string
    {
        $svg = self::svg($text, $label);

        return $svg === null ? null : 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * The modules of $text's code, dark as 1, quiet zone excluded: what a scanner reads (tests).
     *
     * @return list<list<int>>|null
     */
    public static function modules(string $text): ?array
    {
        try {
            $matrix = Encoder::encode($text, ErrorCorrectionLevel::M())->getMatrix();
        } catch (ExceptionInterface) {
            return null;
        }
        $rows = [];
        for ($y = 0; $y < $matrix->getHeight(); ++$y) {
            $row = [];
            for ($x = 0; $x < $matrix->getWidth(); ++$x) {
                $row[] = $matrix->get($x, $y) === 1 ? 1 : 0;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
