<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Support;

/**
 * A page a Browser got: its URL, status, headers (names in lower case) and body, and what a test
 * reads of it: its forms, its links, its text.
 */
final class Page
{
    private ?\DOMDocument $document = null;

    /**
     * @param array<string, list<string>> $headers the answer's
     * @param string|array<string, string|\CURLFile>|null $requestBody what the request sent
     * @param list<string> $requestHeaders the request's own headers
     */
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $method = 'GET',
        public readonly string|array|null $requestBody = null,
        public readonly array $requestHeaders = [],
    ) {
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : implode(', ', $values);
    }

    /** Where a redirect leads, as a URL; null when the page is no redirect. */
    public function location(): ?string
    {
        $location = $this->header('location');
        if ($this->status < 300 || $this->status > 399 || $location === null || $location === '') {
            return null;
        }

        return self::resolve($this->url, $location);
    }

    /** The page's text, its tags removed, its spaces collapsed: what a reader sees. */
    public function text(): string
    {
        $body = $this->dom()->getElementsByTagName('body')->item(0);
        $text = $body !== null ? $body->textContent : strip_tags($this->body);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** @return array<string, mixed> the body as JSON */
    public function json(): array
    {
        $value = json_decode($this->body, true);
        if (!is_array($value)) {
            throw new \RuntimeException('Not JSON: ' . substr($this->body, 0, 300));
        }
        $typed = [];
        foreach ($value as $key => $item) {
            $typed[(string) $key] = $item;
        }

        return $typed;
    }

    /** @return list<Form> */
    public function forms(): array
    {
        $forms = [];
        foreach ($this->dom()->getElementsByTagName('form') as $element) {
            $forms[] = Form::of($element, $this->url);
        }

        return $forms;
    }

    /**
     * The first form $match accepts, else an error that names it ($what).
     *
     * @param callable(Form): bool $match
     */
    public function form(callable $match, string $what): Form
    {
        foreach ($this->forms() as $form) {
            if ($match($form)) {
                return $form;
            }
        }
        throw new \RuntimeException("No form {$what} in {$this->url}: " . substr($this->text(), 0, 500));
    }

    /**
     * The URLs of the page's links, its scripts, its stylesheets.
     *
     * @return list<string>
     */
    public function urls(string $tag = 'a', string $attribute = 'href'): array
    {
        $urls = [];
        foreach ($this->dom()->getElementsByTagName($tag) as $element) {
            $value = $element->getAttribute($attribute);
            if ($value !== '') {
                $urls[] = self::resolve($this->url, $value);
            }
        }

        return $urls;
    }

    /** The first link whose URL holds $part, else an error. */
    public function link(string $part): string
    {
        foreach ($this->urls() as $url) {
            if (str_contains($url, $part)) {
                return $url;
            }
        }
        throw new \RuntimeException("No link to {$part} in {$this->url}");
    }

    /** The element of an id, or null. */
    public function element(string $id): ?\DOMElement
    {
        return $this->dom()->getElementById($id);
    }

    /** @return list<\DOMElement> the elements of a tag whose name is $name (disabled ones too) */
    public function byName(string $tag, string $name): array
    {
        $found = [];
        foreach ($this->dom()->getElementsByTagName($tag) as $element) {
            if ($element->getAttribute('name') === $name) {
                $found[] = $element;
            }
        }

        return $found;
    }

    /** @return list<\DOMElement> the elements of a tag whose class attribute holds $class */
    public function byClass(string $tag, string $class): array
    {
        $found = [];
        foreach ($this->dom()->getElementsByTagName($tag) as $element) {
            $classes = preg_split('/\s+/', $element->getAttribute('class'));
            if (is_array($classes) && in_array($class, $classes, true)) {
                $found[] = $element;
            }
        }

        return $found;
    }

    /** A URL of a page, absolute: $reference, relative to $base. */
    public static function resolve(string $base, string $reference): string
    {
        $reference = html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) === 1) {
            return $reference;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'http';
        $origin = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($reference, '//')) {
            return $scheme . ':' . $reference;
        }
        if (str_starts_with($reference, '/')) {
            return $origin . $reference;
        }
        if (str_starts_with($reference, '?')) {
            return $origin . ($parts['path'] ?? '/') . $reference;
        }
        if (str_starts_with($reference, '#') || $reference === '') {
            return $base;
        }
        $path = $parts['path'] ?? '/';

        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $reference;
    }

    private function dom(): \DOMDocument
    {
        if ($this->document === null) {
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            // HTML5's elements read as HTML 4's do: their attributes and text are what tests read.
            $document->loadHTML('<?xml encoding="UTF-8">' . $this->body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $this->document = $document;
        }

        return $this->document;
    }
}
