<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Sdk\Errors;
use Sattleme\PrestaShop\Tests\Support\Translator;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\CancelledException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\OnchainUnavailableException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\RateException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\RevokedException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;

/** The SDK's errors as the module tells them: stable codes, retries, plain words. */
final class ErrorsTest extends TestCase
{
    public function testCodesAndRetries(): void
    {
        $cases = [
            [new NetworkException(), 'network', true],
            [new RateException(), 'rate', true],
            [new OnchainUnavailableException(), 'onchain_unavailable', true],
            [new InvalidOrderException('fiat'), 'invalid_order', false],
            [new UnsupportedCurrencyException('XAU'), 'unsupported_currency', false],
            [new RevokedException(), 'revoked', false],
            [new InvalidKeyException(), 'invalid_key', false],
            [new ChannelNotFoundException(false), 'channel_not_found', true],
            [new CancelledException(), Errors::TIMEOUT, true],
            [new UnsupportedEnvironmentException('PHP lacks bcmath'), Errors::ENVIRONMENT, false],
            [new \RuntimeException('boom'), Errors::UNEXPECTED, true],
        ];
        foreach ($cases as [$error, $code, $retryable]) {
            self::assertSame($code, Errors::code($error), $error::class);
            self::assertSame($retryable, Errors::isRetryable($error), $error::class);
            self::assertStringStartsWith($code . ': ', Errors::describe($error));
        }
        // An unexpected error is described by its type, never its message (which could hold anything).
        self::assertSame('error: RuntimeException', Errors::describe(new \RuntimeException('secret sattleme_ck_abc')));
        self::assertTrue(Errors::isNewerFormat(new ChannelNotFoundException(true)));
        self::assertFalse(Errors::isNewerFormat(new ChannelNotFoundException(false)));
    }

    public function testTheCustomerIsToldWhatToDo(): void
    {
        $translator = Translator::english();
        self::assertSame('This order cannot be paid with bitcoin. Please choose another payment method.', Errors::forCustomer('invalid_order', $translator));
        self::assertSame('This order cannot be paid with bitcoin. Please choose another payment method.', Errors::forCustomer('unsupported_currency', $translator));
        foreach (['network', 'rate', 'onchain_unavailable', Errors::TIMEOUT, Errors::UNEXPECTED] as $code) {
            self::assertSame('We could not create your bitcoin invoice right now. Please try again in a moment.', Errors::forCustomer($code, $translator), $code);
        }
        foreach (['revoked', 'invalid_key', 'channel_not_found', Errors::NO_KEY, Errors::ENVIRONMENT] as $code) {
            self::assertSame('Bitcoin payments are not available right now. Please choose another payment method, or contact us.', Errors::forCustomer($code, $translator), $code);
        }
    }

    public function testTheMerchantReadsEveryErrorInPlainWordsInItsLanguage(): void
    {
        $codes = ['network', 'rate', 'onchain_unavailable', 'invalid_key', 'revoked', 'channel_not_found', 'invalid_invoice', 'invalid_order', 'unsupported_currency', Errors::TIMEOUT, Errors::ENVIRONMENT, Errors::NO_KEY, Errors::APPLY, 'anything else'];
        $english = Translator::english();
        $texts = array_map(static fn (string $code): string => Errors::forMerchant($code, $english), $codes);
        self::assertCount(count($codes), array_unique($texts));
        foreach (['fr-FR', 'de-DE', 'it-IT'] as $locale) {
            $translator = Translator::forLocale($locale);
            foreach ($codes as $i => $code) {
                self::assertNotSame($texts[$i], Errors::forMerchant($code, $translator), "{$code} in {$locale}");
            }
        }
    }
}
