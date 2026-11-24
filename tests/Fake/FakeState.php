<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Fake;

/**
 * What the fake SDK answers and what it was asked, in a JSON file that the tests and the shop's
 * web requests share (each request is a process of its own): read and written under an exclusive
 * lock, so that requests at once never lose a call.
 *
 * - create: how createInvoice answers (CreateSpec): an invoice, an error, InvoicePending, OrderPaid;
 *   with a delay, to make two requests overlap;
 * - statuses: what getStatus answers for an invoice's reference (StatusSpec); without one, open
 *   until the invoice's end, then expired;
 * - merchant: what merchant() answers;
 * - calls: every call, in order: create, status, merchant, with the reference.
 */
final class FakeState
{
    public function __construct(private readonly string $dir)
    {
    }

    /** The fake's directory of the test shop (shop.sh), or of the tests' process. */
    public static function forTests(): self
    {
        $dir = defined('_SATTLEME_TEST_FAKE_DIR_') ? constant('_SATTLEME_TEST_FAKE_DIR_') : getenv('SATTLEME_SHOP_FAKE');
        if (!is_string($dir) || $dir === '') {
            $dir = dirname(__DIR__, 2) . '/build/fake';
        }

        return new self($dir);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        return $this->update(static fn (array $state): array => [$state, $state]);
    }

    /**
     * Changes the state under the lock: $change gets it and returns [the new state, a result].
     *
     * @template T
     *
     * @param callable(array<string, mixed>): array{0: array<string, mixed>, 1: T} $change
     *
     * @return T
     */
    public function update(callable $change): mixed
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0777, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create {$this->dir}");
        }
        $handle = fopen($this->dir . '/state.json', 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the fake\'s state');
        }
        // The tests and the shop's web server may run as two users (PrestaShop's Docker image
        // runs PHP as www-data): whichever makes the file lets the other write it.
        @chmod($this->dir . '/state.json', 0666);
        try {
            flock($handle, LOCK_EX);
            $json = stream_get_contents($handle);
            $state = is_string($json) && $json !== '' ? json_decode($json, true) : [];
            [$state, $result] = $change(is_array($state) ? self::stringKeys($state) : []);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function reset(): void
    {
        $this->update(static fn (array $state): array => [[], null]);
    }

    /**
     * How the next createInvoice calls answer, until changed (CreateSpec's keys).
     *
     * @param array<string, mixed> $spec
     */
    public function setCreate(array $spec): void
    {
        $this->update(static function (array $state) use ($spec): array {
            $state['create'] = $spec;

            return [$state, null];
        });
    }

    /**
     * What getStatus answers for the invoice of a reference (StatusSpec's keys).
     *
     * @param array<string, mixed> $spec
     */
    public function setStatus(string $ref, array $spec): void
    {
        $this->update(static function (array $state) use ($ref, $spec): array {
            $statuses = is_array($state['statuses'] ?? null) ? $state['statuses'] : [];
            $statuses[$ref] = $spec;
            $state['statuses'] = $statuses;

            return [$state, null];
        });
    }

    /** @param array<string, mixed> $spec businessName, or error */
    public function setMerchant(array $spec): void
    {
        $this->update(static function (array $state) use ($spec): array {
            $state['merchant'] = $spec;

            return [$state, null];
        });
    }

    /**
     * The calls made, of one kind or all.
     *
     * @return list<array{op: string, ref: string, at: float}>
     */
    public function calls(?string $op = null): array
    {
        $calls = [];
        $state = $this->read();
        foreach (is_array($state['calls'] ?? null) ? $state['calls'] : [] as $call) {
            if (is_array($call) && is_string($call['op'] ?? null) && ($op === null || $call['op'] === $op)) {
                $calls[] = ['op' => $call['op'], 'ref' => is_string($call['ref'] ?? null) ? $call['ref'] : '', 'at' => is_float($call['at'] ?? null) || is_int($call['at'] ?? null) ? (float) $call['at'] : 0.0];
            }
        }

        return $calls;
    }

    /**
     * @param array<mixed> $array
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $array): array
    {
        $typed = [];
        foreach ($array as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }
}
