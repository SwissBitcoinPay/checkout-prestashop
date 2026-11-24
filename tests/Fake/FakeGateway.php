<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Fake;

use Sattleme\PrestaShop\Clock;
use Sattleme\PrestaShop\Sdk\Gateway;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\DeclaredInvoice;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\CheckoutUnavailableException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\OnchainUnavailableException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\RateException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\RevokedException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Fiat;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\PaymentStatus;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\RateInfo;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Rest;

/**
 * The SDK as the tests have it answer (the module's Gateway, its one seam): every state of a
 * payment, every error, InvoicePending and OrderPaid, on the SDK's own value classes (the module's
 * prefixed copy). Its answers and the calls it got are in a FakeState shared with the tests.
 *
 * Prices: one bitcoin is 50 000 of any currency, so 1.00 is 2 000 sats (24.50 CHF: 49 000 sats).
 */
final class FakeGateway implements Gateway
{
    public const PRICE = '50000';
    public const SATS_PER_UNIT = '2000';

    /** Made by the app from BIP-39's test mnemonic "abandon … about" (never a real wallet), for integration 0123456789abcdef0123456789abcdef, relay relay.example. */
    public const KEY = 'sattleme_ck_-kKjB8mG0CLDp3tcdT76Nnq66ATU4wzzgS0xt7DCKdo.17wserpSaW-xtDOhk6tHDf_9VWdelRxRCfUUFPdVHeg.cmVsYXkuZXhhbXBsZQ';

    public function __construct(private readonly FakeState $state, private readonly string $key)
    {
    }

    /** The fake of a key: one the SDK refuses (not an integration key) is refused alike. */
    public static function forKey(#[\SensitiveParameter] string $key, FakeState $state): self
    {
        IntegrationKey::parse($key);

        return new self($state, $key);
    }

    public function createInvoice(array $order, float $seconds): Invoice
    {
        $ref = is_string($order['ref'] ?? null) ? $order['ref'] : '';
        $state = $this->record('create', $ref);
        $spec = is_array($state['create'] ?? null) ? $state['create'] : [];
        self::delay($spec);
        $now = Clock::now();
        switch ($spec['mode'] ?? 'ok') {
            case 'error':
                throw self::error(is_string($spec['error'] ?? null) ? $spec['error'] : 'network');
            case 'timeout':
                throw new CancelledException();
            case 'pending':
                // An earlier call made an invoice whose answer was lost: still payable, or not.
                throw new InvoicePendingException(self::declared($order, $spec, $now), null);
            case 'paid':
                throw new OrderPaidException(self::declared($order, $spec, $now), PaymentStatus::paidByLightning(self::hex('preimage|' . $ref)));
        }

        return self::invoice($order, $spec, $now);
    }

    public function getStatus(array $invoice, float $seconds): PaymentStatus
    {
        $ref = is_string($invoice['ref'] ?? null) ? $invoice['ref'] : '';
        $state = $this->record('status', $ref);
        $statuses = is_array($state['statuses'] ?? null) ? $state['statuses'] : [];
        // The status of this invoice, or of any ('*': for invoices whose reference the test cannot know).
        $spec = is_array($statuses[$ref] ?? null) ? $statuses[$ref] : (is_array($statuses['*'] ?? null) ? $statuses['*'] : null);
        $expiresAt = is_int($invoice['expiresAt'] ?? null) ? $invoice['expiresAt'] : 0;
        if ($spec === null) {
            return Clock::now() < $expiresAt ? PaymentStatus::open($expiresAt, '0', null) : PaymentStatus::expired('0');
        }
        self::delay($spec);

        return self::status($spec, $ref, $expiresAt);
    }

    public function merchant(float $seconds): Merchant
    {
        $state = $this->record('merchant', '');
        $spec = is_array($state['merchant'] ?? null) ? $state['merchant'] : [];
        if (is_string($spec['error'] ?? null)) {
            throw self::error($spec['error']);
        }

        return new Merchant(is_string($spec['businessName'] ?? null) ? $spec['businessName'] : 'Test Merchant', '1', '100000000', 10);
    }

    /**
     * The status a spec describes: state open (received, rest), unconfirmed (received,
     * confirmations, txids), paid (method lightning, onchain or mixed), expired (received), error.
     *
     * @param array<mixed> $spec
     */
    public static function status(array $spec, string $ref, int $expiresAt): PaymentStatus
    {
        $received = self::digits($spec['received'] ?? '0');
        $txids = [self::hex('tx1|' . $ref)];
        switch ($spec['state'] ?? 'open') {
            case 'error':
                throw self::error(is_string($spec['error'] ?? null) ? $spec['error'] : 'network');
            case 'timeout':
                throw new CancelledException();
            case 'unconfirmed':
                return PaymentStatus::unconfirmed($received, is_int($spec['confirmations'] ?? null) ? $spec['confirmations'] : 1, $txids);
            case 'paid':
                return match ($spec['method'] ?? 'lightning') {
                    'onchain' => PaymentStatus::paidOnchain($received, $txids),
                    'mixed' => PaymentStatus::paidMixed($received, $txids, [self::hex('preimage2|' . $ref)]),
                    default => PaymentStatus::paidByLightning(self::hex('preimage|' . $ref)),
                };
            case 'expired':
                return PaymentStatus::expired($received);
            default:
                $rest = null;
                if (is_array($spec['rest'] ?? null)) {
                    $sats = self::digits($spec['rest']['sats'] ?? '0');
                    $bolt11 = 'lnbc' . $sats . '0n1prest' . substr(self::hex('rest|' . $ref), 0, 40);
                    $address = 'bc1q' . substr(self::hex('address|' . $ref), 0, 38);
                    $rest = new Rest($sats, $bolt11, 'bitcoin:' . $address . '?amount=' . self::btc($sats) . '&lightning=' . $bolt11);
                }

                return PaymentStatus::open($expiresAt, $received, $rest);
        }
    }

    /**
     * An invoice for an order: its spec may name its bolt11 and onchainAddress (null: none).
     *
     * @param array<string, mixed> $order
     * @param array<mixed> $spec
     */
    private static function invoice(array $order, array $spec, int $now): Invoice
    {
        $ref = is_string($order['ref'] ?? null) ? $order['ref'] : '';
        [$amount, $currency, $sats] = self::price($order);
        $bolt11 = array_key_exists('bolt11', $spec) ? (is_string($spec['bolt11']) ? $spec['bolt11'] : null)
            : 'lnbc' . $sats . '0n1p' . substr(self::hex('bolt11|' . $ref), 0, 48);
        $address = array_key_exists('onchainAddress', $spec) ? (is_string($spec['onchainAddress']) ? $spec['onchainAddress'] : null)
            : 'bc1q' . substr(self::hex('address|' . $ref), 0, 38);
        $uri = $address !== null
            ? 'bitcoin:' . $address . '?amount=' . self::btc($sats) . ($bolt11 !== null ? '&lightning=' . $bolt11 : '')
            : 'lightning:' . $bolt11;
        $duration = is_int($order['durationSeconds'] ?? null) ? $order['durationSeconds'] : 900;

        return new Invoice(
            $ref,
            $bolt11,
            $bolt11 !== null ? self::hex('hash|' . $ref) : null,
            null,
            $address,
            $uri,
            $sats,
            self::fiat($amount, $currency),
            new RateInfo('fake', $currency, self::PRICE, $now, $now),
            is_string($order['note'] ?? null) ? $order['note'] : '',
            $now,
            $now + $duration,
            null,
            null,
        );
    }

    /**
     * An earlier invoice of the order's reference, as the merchant's registry holds it: made a
     * minute ago, payable until its spec's expiresIn (seconds from now; 600 by default).
     *
     * @param array<string, mixed> $order
     * @param array<mixed> $spec
     */
    private static function declared(array $order, array $spec, int $now): DeclaredInvoice
    {
        $invoice = self::invoice($order, $spec, $now - 60);
        $expiresAt = $now + (is_int($spec['expiresIn'] ?? null) ? $spec['expiresIn'] : 600);

        return new DeclaredInvoice(
            $invoice->ref,
            $invoice->bolt11,
            $invoice->paymentHash,
            null,
            $invoice->onchainAddress,
            $invoice->paymentUri,
            $invoice->sats,
            $invoice->fiat,
            $invoice->note,
            $invoice->createdAt,
            $expiresAt,
            $invoice->createdAt + 1,
        );
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array{0: string, 1: string, 2: numeric-string}
     */
    private static function price(array $order): array
    {
        $fiat = is_array($order['fiat'] ?? null) ? $order['fiat'] : [];
        $amount = is_string($fiat['amount'] ?? null) ? $fiat['amount'] : '0';
        $currency = is_string($fiat['currency'] ?? null) ? $fiat['currency'] : 'CHF';
        if (!is_numeric($amount)) {
            throw new InvalidOrderException('fiat');
        }
        $sats = bcmul($amount, self::SATS_PER_UNIT, 0);

        return [$amount, $currency, $sats];
    }

    /**
     * Records a call, with the key's digest (the module passes the key it stored).
     *
     * @return array<string, mixed> the state, once the call is recorded
     */
    private function record(string $op, string $ref): array
    {
        $digest = hash('sha256', $this->key);

        return $this->state->update(static function (array $state) use ($op, $ref, $digest): array {
            $calls = is_array($state['calls'] ?? null) ? $state['calls'] : [];
            $calls[] = ['op' => $op, 'ref' => $ref, 'at' => microtime(true), 'key' => $digest, 'pid' => getmypid()];
            $state['calls'] = $calls;

            return [$state, $state];
        });
    }

    /**
     * The call's time: delayMs, a real wait; takesSeconds, the module's clock (Clock) moved on by
     * that much, as a slow call would (with the clock frozen by the test).
     *
     * @param array<mixed> $spec
     */
    private static function delay(array $spec): void
    {
        if (is_int($spec['delayMs'] ?? null) && $spec['delayMs'] > 0) {
            usleep($spec['delayMs'] * 1000);
        }
        if (is_int($spec['takesSeconds'] ?? null) && $spec['takesSeconds'] > 0) {
            Clock::freeze(Clock::now() + $spec['takesSeconds']);
        }
    }

    public static function error(string $code): \Throwable
    {
        return match ($code) {
            'rate' => new RateException(),
            'onchain_unavailable' => new OnchainUnavailableException(),
            'invalid_order' => new InvalidOrderException('fiat'),
            'unsupported_currency' => new UnsupportedCurrencyException('XYZ'),
            'revoked' => new RevokedException(),
            'channel_not_found' => new ChannelNotFoundException(false),
            'channel_newer' => new ChannelNotFoundException(true),
            'invalid_invoice' => new InvalidInvoiceException(),
            'invalid_key' => new InvalidKeyException(),
            'checkout_unavailable' => new CheckoutUnavailableException(),
            'timeout' => new CancelledException(),
            'unexpected' => new \RuntimeException('Something unexpected'),
            default => new NetworkException(),
        };
    }

    private static function fiat(string $amount, string $currency): Fiat
    {
        $dot = strpos($amount, '.');
        $minor = ltrim(str_replace('.', '', $amount), '0');
        $minor = $minor === '' ? '0' : $minor;
        if (!is_numeric($minor)) {
            throw new InvalidOrderException('fiat');
        }

        return new Fiat($amount, $currency, $minor, $dot === false ? 0 : strlen($amount) - $dot - 1);
    }

    /** @return numeric-string */
    private static function digits(mixed $value): string
    {
        return is_string($value) && preg_match('/^[0-9]{1,18}$/D', $value) === 1 && is_numeric($value) ? $value : '0';
    }

    /** A sats amount in bitcoins, as BIP-21 writes it. */
    private static function btc(string $sats): string
    {
        if (!is_numeric($sats)) {
            return '0';
        }

        return rtrim(rtrim(bcdiv($sats, '100000000', 8), '0'), '.');
    }

    /** 64 hexadecimal characters derived from $seed: a txid, a preimage, a hash. */
    public static function hex(string $seed): string
    {
        return hash('sha256', $seed);
    }
}
