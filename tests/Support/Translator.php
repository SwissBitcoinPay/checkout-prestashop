<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Support;

use Sattleme\PrestaShop\Tools\Translations;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A translator for the unit tests: the module's wordings in English, or in a locale from the
 * translation files of the built module, with their parameters replaced as Symfony's translator
 * replaces them. It records what it was asked.
 */
final class Translator implements TranslatorInterface
{
    /** @var list<array{id: string, domain: string|null}> */
    public array $asked = [];

    /** @param array<string, array<string, string>> $catalogues by domain: source => translation */
    private function __construct(private readonly string $locale, private readonly array $catalogues)
    {
    }

    public static function english(): self
    {
        return new self('en-US', []);
    }

    public static function forLocale(string $locale): self
    {
        $catalogues = [];
        foreach (Translations::DOMAINS as $domain) {
            $catalogues[$domain] = Translations::read(Translations::file(BuiltModule::extract(), $domain, $locale));
        }

        return new self($locale, $catalogues);
    }

    /** @param array<array-key, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $this->asked[] = ['id' => $id, 'domain' => $domain];
        $text = $this->catalogues[$domain ?? ''][$id] ?? $id;
        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return strtr($text, $replacements);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}
