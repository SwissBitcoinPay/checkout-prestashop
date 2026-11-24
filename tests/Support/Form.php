<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Support;

/**
 * A form of a page, as a browser without JavaScript submits it: its fields with their values
 * (checked boxes and radios, selected options, hidden fields), changed by the test, then sent.
 */
final class Form
{
    /**
     * @param list<array{0: string, 1: string}> $fields name and value, in the page's order
     * @param array<string, list<string>> $choices the values each radio, checkbox or select offers
     */
    private function __construct(
        public readonly string $action,
        public readonly string $method,
        public readonly string $id,
        private array $fields,
        public readonly array $choices,
    ) {
    }

    public static function of(\DOMElement $form, string $pageUrl): self
    {
        $action = $form->getAttribute('action');
        $fields = [];
        $choices = [];
        $xpath = new \DOMXPath($form->ownerDocument ?? new \DOMDocument());
        $elements = $xpath->query('.//input | .//select | .//textarea', $form);
        foreach ($elements === false ? [] : $elements as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $name = $element->getAttribute('name');
            if ($name === '' || $element->hasAttribute('disabled')) {
                continue;
            }
            $tag = strtolower($element->tagName);
            $type = strtolower($element->getAttribute('type'));
            if ($tag === 'select') {
                // The option selected, else the first, as a browser shows it.
                $options = $xpath->query('.//option', $element);
                $first = null;
                $selected = null;
                foreach ($options === false ? [] : $options as $option) {
                    if (!$option instanceof \DOMElement) {
                        continue;
                    }
                    $value = $option->hasAttribute('value') ? $option->getAttribute('value') : trim($option->textContent);
                    $choices[$name][] = $value;
                    $first ??= $value;
                    if ($option->hasAttribute('selected')) {
                        $selected = $value;
                    }
                }
                if (($selected ?? $first) !== null) {
                    $fields[] = [$name, $selected ?? $first];
                }
            } elseif ($tag === 'textarea') {
                $fields[] = [$name, $element->textContent];
            } elseif (in_array($type, ['checkbox', 'radio'], true)) {
                $value = $element->hasAttribute('value') ? $element->getAttribute('value') : 'on';
                $choices[$name][] = $value;
                if ($element->hasAttribute('checked')) {
                    $fields[] = [$name, $value];
                }
            } elseif (!in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) {
                $fields[] = [$name, $element->getAttribute('value')];
            }
        }

        return new self(
            Page::resolve($pageUrl, $action === '' ? $pageUrl : $action),
            strtoupper($form->getAttribute('method')) === 'POST' ? 'POST' : 'GET',
            $form->getAttribute('id'),
            $fields,
            $choices,
        );
    }

    /** The value of a field, or null. */
    public function value(string $name): ?string
    {
        foreach ($this->fields as [$field, $value]) {
            if ($field === $name) {
                return $value;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        if (array_key_exists($name, $this->choices)) {
            return true;
        }
        foreach ($this->fields as [$field]) {
            if ($field === $name) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the names of its fields, in order */
    public function names(): array
    {
        return array_values(array_unique(array_merge(array_map(static fn (array $field): string => $field[0], $this->fields), array_keys($this->choices))));
    }

    /**
     * The form with these fields set (replacing their values; a field it lacks is added).
     *
     * @param array<string, string> $values
     */
    public function with(array $values): self
    {
        $fields = [];
        foreach ($this->fields as [$name, $value]) {
            if (!array_key_exists($name, $values)) {
                $fields[] = [$name, $value];
            }
        }
        foreach ($values as $name => $value) {
            $fields[] = [(string) $name, $value];
        }

        return new self($this->action, $this->method, $this->id, $fields, $this->choices);
    }

    /** The form without these fields. */
    public function without(string ...$names): self
    {
        $fields = array_values(array_filter($this->fields, static fn (array $field): bool => !in_array($field[0], $names, true)));

        return new self($this->action, $this->method, $this->id, $fields, $this->choices);
    }

    /** @return list<array{0: string, 1: string}> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** Sent as a browser sends it: the answer as it is (a redirect not followed). */
    public function submit(Browser $browser): Page
    {
        if ($this->method === 'POST') {
            return $browser->post($this->action, $this->fields);
        }
        $query = http_build_query(array_map(static fn (array $field): string => $field[1], array_column($this->fields, null, 0)));
        $url = $this->action . (str_contains($this->action, '?') ? '&' : '?') . $query;

        return $browser->fetch($url);
    }
}
