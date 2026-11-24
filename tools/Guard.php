<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tools;

/**
 * The guard PrestaShop's technical standards ask of every PHP file of a module, its libraries
 * included: a file reached directly, outside PrestaShop, does nothing. The module's own files carry
 * it as written; the embedded libraries get it from PHP-Scoper (scoper.inc.php's patcher) and
 * Composer's autoloader files from tools/guard.php, both through add().
 *
 * A development tool: never shipped in the module's ZIP.
 */
final class Guard
{
    /** The guard, as the module's files and PrestaShop's validator write it. */
    public const GUARD = "if (!defined('_PS_VERSION_')) {\n    exit;\n}";

    private function __construct()
    {
    }

    /**
     * $code with the guard as its first statement: after its declare, namespace and use
     * statements (so that a class keeps its comment), or after its first comment when it has none
     * (Composer's "@generated" line). Code that has it already is returned as it is.
     *
     * @throws \UnexpectedValueException code that is not one PHP file of one plain namespace at most
     */
    public static function add(string $code): string
    {
        if (str_contains($code, "\n" . self::GUARD . "\n")) {
            return $code;
        }
        $tokens = \PhpToken::tokenize($code);
        if (($tokens[0] ?? null)?->id !== \T_OPEN_TAG) {
            throw new \UnexpectedValueException('not a PHP file');
        }
        $offset = strlen($tokens[0]->text);
        $statement = false;
        $count = count($tokens);
        for ($i = 1; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                if (!$statement && $token->is(\T_COMMENT) && $offset === strlen($tokens[0]->text)) {
                    $offset = $token->pos + strlen($token->text);
                }
                continue;
            }
            if (!$token->is([\T_DECLARE, \T_NAMESPACE, \T_USE])) {
                break;
            }
            // The statement, up to its semicolon: a namespace with braces is not taken.
            for (; $i < $count && $tokens[$i]->text !== ';'; ++$i) {
                if ($tokens[$i]->text === '{') {
                    throw new \UnexpectedValueException('a namespace or declare with braces');
                }
            }
            if ($i === $count) {
                throw new \UnexpectedValueException('a statement without its semicolon');
            }
            $offset = $tokens[$i]->pos + 1;
            $statement = true;
        }
        if (substr_count($code, "\nnamespace ") > 1) {
            throw new \UnexpectedValueException('more than one namespace');
        }
        if (($code[$offset] ?? '') !== "\n") {
            throw new \UnexpectedValueException('no line break where the guard goes');
        }

        return substr($code, 0, $offset) . "\n\n" . self::GUARD . substr($code, $offset);
    }
}
