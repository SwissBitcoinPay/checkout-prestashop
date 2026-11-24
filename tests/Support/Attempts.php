<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Support;

use Sattleme\PrestaShop\Payment\Attempt;

/**
 * Payment attempts for the unit tests, as the table's rows read them (PDO gives integer columns as
 * integers, the others as strings).
 */
final class Attempts
{
    public const NOW = 1_800_000_000;
    public const REF = 'prestashop-42-0123456789abcdef0123456789abcdef';
    public const ADDRESS = 'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq';
    public const BOLT11 = 'lnbc490u1p3xnhl2pp5jptserfk3zk4qy42tlucycrfwxhydvlemu9pqr93tuzlv9cc7g3sdqsvfhkcap3xyhx7un8cqzpgxqzjcsp5f8c52y2stc300gl6s4xswtjpc37hrnnr3c9wvtgjfuvqmpm35evq9qyyssqy4lgd8tj637qcjp05rdpxxykjenthxftej7a2zzmwrmrl70fyj9hvj0rewhzj7jfyuwkwcg9g2jpwtk3wkjtwnkdks84hsnu8xps5vsq4gj5hs';
    public const URI = 'bitcoin:' . self::ADDRESS . '?amount=0.00049&lightning=' . self::BOLT11;

    private function __construct()
    {
    }

    /**
     * An attempt: by default its invoice open for 15 minutes from NOW, nothing checked yet.
     *
     * @param array<string, mixed> $row columns to change
     * @param array<string, mixed> $invoice fields of the invoice to change (null: no invoice)
     * @param array<string, mixed>|null $status the last status found
     */
    public static function make(array $row = [], ?array $invoice = [], ?array $status = null): Attempt
    {
        $fullInvoice = $invoice === null ? null : $invoice + [
            'ref' => self::REF,
            'bolt11' => self::BOLT11,
            'paymentHash' => str_repeat('ab', 32),
            'verifyUrl' => null,
            'onchainAddress' => self::ADDRESS,
            'paymentUri' => self::URI,
            'sats' => '49000',
            'fiat' => ['amount' => '24.50', 'currency' => 'CHF', 'minor' => '2450', 'digits' => 2],
            'rate' => ['source' => 'kraken', 'currency' => 'CHF', 'price' => '50000', 'fetchedAt' => self::NOW, 'time' => self::NOW],
            'note' => 'Shop · ABCDEFGHI',
            'createdAt' => self::NOW,
            'expiresAt' => self::NOW + 900,
            'checkoutUrl' => null,
            'redirectUrl' => null,
        ];

        return Attempt::fromRow($row + [
            'id_sattleme_payment' => 7,
            'id_shop' => 1,
            'id_cart' => 42,
            'id_order' => 5,
            'order_reference' => 'ABCDEFGHI',
            'ref' => self::REF,
            'amount' => '24.50',
            'currency' => 'CHF',
            'state' => $invoice === null ? Attempt::NEW : Attempt::OPEN,
            'final' => 0,
            'origin' => Attempt::ORIGIN_CREATED,
            'invoice' => $fullInvoice === null ? null : json_encode($fullInvoice),
            'status' => $status === null ? null : json_encode($status),
            'expires_at' => $fullInvoice === null ? null : $fullInvoice['expiresAt'],
            'created_at' => self::NOW,
            'checked_at' => null,
            'next_check_at' => self::NOW,
            'lease_until' => null,
            'error' => '',
            'errors' => 0,
            'completed_at' => null,
            'notes' => '',
            'shredded' => 0,
        ]);
    }
}
