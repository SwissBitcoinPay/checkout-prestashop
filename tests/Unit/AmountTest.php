<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Payment\Amount;

final class AmountTest extends TestCase
{
    /** @return iterable<array{float, int, string}> */
    public static function totals(): iterable
    {
        yield [24.5, 2, '24.50'];
        yield [24.499999999, 2, '24.50'];
        yield [0.1 + 0.2, 2, '0.30'];
        yield [1234.0, 0, '1234'];
        yield [12.3456, 3, '12.346'];
        yield [99.995, 2, '100.00'];
    }

    #[DataProvider('totals')]
    public function testATotalIsADecimalStringWithItsCurrencysDecimals(float $total, int $precision, string $expected): void
    {
        self::assertSame($expected, Amount::fromTotal($total, $precision));
    }

    public function testDatabaseAmountsAreRoundedHalfUpWithoutFloats(): void
    {
        self::assertSame('24.50', Amount::round('24.500000', 2));
        self::assertSame('24.51', Amount::round('24.505000', 2));
        self::assertSame('24.50', Amount::round('24.504999', 2));
        self::assertSame('25', Amount::round('24.500000', 0));
        self::assertSame('0.30', Amount::round('0.3', 2));
    }

    public function testSumsAndComparisonsAreExact(): void
    {
        self::assertSame('0.300000', Amount::add('0.100000', '0.200000', 6));
        self::assertSame(0, Amount::compare('24.50', '24.500000'));
        self::assertSame(1, Amount::compare('24.51', '24.50'));
        self::assertSame(-1, Amount::compare('0', '0.01'));
    }

    public function testWhatIsOwedMayFallBelowZero(): void
    {
        self::assertSame('5.820000', Amount::subtract('12.820000', '7.000000', 6));
        self::assertSame('-2.180000', Amount::subtract('12.820000', '15.000000', 6));
        self::assertSame('-2.18', Amount::round('-2.180000', 2));
        self::assertSame('-2.19', Amount::round('-2.185000', 2), 'half away from zero');
        self::assertSame('0.00', Amount::round('-0.001000', 2), 'no "-0.00"');
    }

    public function testAnythingButADecimalIsRefused(): void
    {
        foreach (['', '1e3', '-1', ' 1', '1,5', '0x10', 'NaN', '1.'] as $value) {
            self::assertFalse(Amount::isDecimal($value), $value);
            try {
                Amount::add($value, '1', 2);
                self::fail("{$value} was added");
            } catch (\InvalidArgumentException) {
                // Refused.
            }
        }
    }

    public function testSatsAreBitcoinsWithoutTrailingZeros(): void
    {
        self::assertSame('0.00049', Amount::btc('49000'));
        self::assertSame('1', Amount::btc('100000000'));
        self::assertSame('0.00000001', Amount::btc('1'));
        self::assertSame('0', Amount::btc('0'));
        self::assertSame('21000000', Amount::btc('2100000000000000'));
        $this->expectException(\InvalidArgumentException::class);
        Amount::btc('1.5');
    }
}
