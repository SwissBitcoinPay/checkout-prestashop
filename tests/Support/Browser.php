<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Support;

/**
 * A browser without JavaScript, for the tests that go through a shop's pages over HTTP: its
 * cookies kept (one session), redirects followed or not, forms read from the pages and submitted
 * as a browser submits them. No proxy: the shop runs on this machine.
 */
final class Browser
{
    /** @var \CurlHandle */
    private $curl;

    public function __construct(private readonly string $base)
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new \RuntimeException('curl cannot start');
        }
        $this->curl = $curl;
        // An empty file name: curl's cookie engine, in memory, for this handle's requests only.
        curl_setopt($this->curl, CURLOPT_COOKIEFILE, '');
    }

    public function base(): string
    {
        return rtrim($this->base, '/');
    }

    /** A path of the shop (/index.php?…) or a URL, as a URL. */
    public function url(string $path): string
    {
        return preg_match('#^https?://#', $path) === 1 ? $path : $this->base() . '/' . ltrim($path, '/');
    }

    /** GET, redirects followed. */
    public function get(string $path): Page
    {
        return $this->follow($this->request('GET', $this->url($path)));
    }

    /** GET, the answer as it is (a redirect not followed). */
    public function fetch(string $path, bool $ajax = false): Page
    {
        return $this->request('GET', $this->url($path), null, $ajax ? ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'] : []);
    }

    /**
     * POST of form fields (a list of [name, value] pairs, as a form sends them, or a map), the
     * answer as it is.
     *
     * @param list<array{0: string, 1: string}>|array<string, string> $fields
     * @param array<string, \CURLFile> $files
     */
    public function post(string $path, array $fields, array $files = []): Page
    {
        if ($files !== []) {
            $body = $files;
            foreach (self::pairs($fields) as [$name, $value]) {
                $body[$name] = $value;
            }

            return $this->request('POST', $this->url($path), $body);
        }
        $encoded = implode('&', array_map(static fn (array $pair): string => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]), self::pairs($fields)));

        return $this->request('POST', $this->url($path), $encoded, ['Content-Type: application/x-www-form-urlencoded']);
    }

    /**
     * The page a redirect leads to, after every redirect, as a browser follows them: a 307 or a
     * 308 sends the request again, its method and body; any other, a GET.
     */
    public function follow(Page $page): Page
    {
        for ($hops = 0; ($location = $page->location()) !== null; ++$hops) {
            if ($hops === 10) {
                throw new \RuntimeException('Too many redirects from ' . $page->url);
            }
            $page = in_array($page->status, [307, 308], true)
                ? $this->request($page->method, $location, $page->requestBody, $page->requestHeaders)
                : $this->request('GET', $location);
        }

        return $page;
    }

    /** The session's cookies, by name. */
    public function cookie(string $name): ?string
    {
        $cookies = curl_getinfo($this->curl, CURLINFO_COOKIELIST);
        foreach (is_array($cookies) ? $cookies : [] as $line) {
            $fields = explode("\t", (string) $line);
            if (count($fields) === 7 && $fields[5] === $name) {
                return $fields[6];
            }
        }

        return null;
    }

    /**
     * @param string|array<string, string|\CURLFile>|null $body
     * @param list<string> $headers
     */
    private function request(string $method, string $url, string|array|null $body = null, array $headers = []): Page
    {
        if ($method === '' || $url === '') {
            throw new \InvalidArgumentException('A request has a method and a URL');
        }
        $responseHeaders = [];
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPGET => $method === 'GET',
            CURLOPT_POST => $method === 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => 30,
            // A busy machine, PrestaShop building its caches on a first request: generous.
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => array_merge(['Accept-Language: en-US,en;q=0.8', 'User-Agent: sattleme-prestashop-tests'], $headers),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $body ?? '');
        }
        $text = curl_exec($this->curl);
        if (!is_string($text)) {
            throw new \RuntimeException("{$method} {$url}: " . curl_error($this->curl));
        }

        return new Page($url, (int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE), $responseHeaders, $text, $method, $body, $headers);
    }

    /**
     * @param list<array{0: string, 1: string}>|array<string, string> $fields
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function pairs(array $fields): array
    {
        $pairs = [];
        foreach ($fields as $name => $value) {
            $pairs[] = is_array($value) ? [$value[0], $value[1]] : [(string) $name, $value];
        }

        return $pairs;
    }
}
