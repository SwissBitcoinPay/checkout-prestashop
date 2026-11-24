<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tools;

/**
 * The licence header every file of the module carries, as PrestaShop's validator and its
 * header-stamp tool write them (a "NOTICE OF LICENSE" comment with @author, @copyright and @license),
 * and the index.php that keeps a web server from listing a directory (PrestaShop's autoindex).
 *
 * A development tool: never shipped in the module's ZIP.
 */
final class Headers
{
    /** The header's lines, without their comment markers. */
    public const LINES = [
        'sattle.me for PrestaShop',
        '',
        'NOTICE OF LICENSE',
        '',
        'This source file is subject to the MIT License that is bundled with this module in the file',
        'LICENSE, also available at https://opensource.org/licenses/MIT',
        '',
        '@author    sattle.me',
        '@copyright 2026 sattle.me',
        '@license   https://opensource.org/licenses/MIT MIT License',
    ];

    private function __construct()
    {
    }

    /** The header as a comment of a PHP, JavaScript or CSS file. */
    public static function comment(): string
    {
        return "/**\n" . implode("\n", array_map(static fn (string $line): string => rtrim(' * ' . $line), self::LINES)) . "\n */";
    }

    /** The header as a comment of a Smarty template. */
    public static function smarty(): string
    {
        return "{**\n" . implode("\n", array_map(static fn (string $line): string => rtrim(' * ' . $line), self::LINES)) . "\n *}";
    }

    /** The index.php of a directory: it sends its visitor to the parent directory. */
    public static function indexFile(): string
    {
        return "<?php\n\n" . self::comment() . "\n"
            . "header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');\n"
            . "header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');\n"
            . "\n"
            . "header('Cache-Control: no-store, no-cache, must-revalidate');\n"
            . "header('Cache-Control: post-check=0, pre-check=0', false);\n"
            . "header('Pragma: no-cache');\n"
            . "\n"
            . "header('Location: ../');\n"
            . "exit;\n";
    }

    /**
     * Whether $contents (a file named $name) starts with the header, as its type writes it.
     */
    public static function hasHeader(string $name, string $contents): bool
    {
        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));

        return match ($extension) {
            'php' => str_starts_with($contents, "<?php\n\n" . self::comment() . "\n"),
            'tpl' => str_starts_with($contents, self::smarty() . "\n"),
            'js', 'css' => str_starts_with($contents, self::comment() . "\n"),
            default => true,
        };
    }
}
