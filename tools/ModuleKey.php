<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tools;

/**
 * PrestaShop Addons' module key in the module's class: Addons gives it once the product exists in
 * the seller account, and it goes in the constructor, `$this->module_key = '…';` (README.md,
 * "Releases"). Until then the class has none. tools/check-zip.php takes the module either way,
 * but refuses anything else assigned to it.
 *
 * A development tool: never shipped in the module's ZIP.
 */
final class ModuleKey
{
    private function __construct()
    {
    }

    /** What is wrong with the class's module key, or null: none, or one quoted key. */
    public static function problem(string $class): ?string
    {
        $keys = preg_match_all('/\$this->module_key\b/', $class);
        if ($keys === 0 || ($keys === 1 && preg_match("/\\n        \\\$this->module_key = '[0-9A-Za-z]{16,64}';\\n/", $class) === 1)) {
            return null;
        }

        return 'module_key is not one key of PrestaShop Addons, quoted';
    }
}
