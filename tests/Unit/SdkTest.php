<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Admin\KeyCheck;
use Sattleme\PrestaShop\Payment\Availability;
use Sattleme\PrestaShop\Sdk\Environment;
use Sattleme\PrestaShop\Sdk\GatewayFactory;
use Sattleme\PrestaShop\Sdk\SdkGateway;
use Sattleme\PrestaShop\Tests\Fake\FakeGateway;
use Sattleme\PrestaShop\Tests\Support\BuiltModule;
use Sattleme\PrestaShop\Tests\Support\Translator;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Client;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\PrestaShop\Vendor\Sattleme\Checkout\Internal\Pricing;

/**
 * The real SDK, embedded and prefixed, without the network: its environment, its pricing table,
 * its client, and the integration key it never lets out.
 */
final class SdkTest extends TestCase
{
    public function testThisPhpRunsTheSdk(): void
    {
        self::assertNull(Environment::missing());
    }

    public function testTheMethodIsOfferedForWhatTheSdkPrices(): void
    {
        self::assertTrue(Availability::accepts('24.50', 'CHF'));
        self::assertTrue(Availability::accepts('24.5', 'EUR'));
        self::assertTrue(Availability::accepts('1000', 'JPY'));
        self::assertTrue(Availability::accepts('1000.00', 'JPY'));
        self::assertTrue(Availability::accepts('1.234', 'KWD'));
        // More decimals than the currency has, nothing to pay, or a currency of no table.
        self::assertFalse(Availability::accepts('24.505', 'CHF'));
        self::assertFalse(Availability::accepts('1000.50', 'JPY'));
        self::assertFalse(Availability::accepts('0.00', 'CHF'));
        self::assertFalse(Availability::accepts('10.00', 'XAU'));
        self::assertFalse(Availability::accepts('10.00', 'chf'));
    }

    /**
     * The module's check of a price and the SDK's, on the cases of checkout-spec's fiat.json (its
     * copy at da3bdb8, as checkout-php 24deb0f pins it): they accept and refuse the same, as the
     * vectors say. The SDK's own check, Internal\Pricing, is read here only: the module calls
     * none of the SDK's internal classes.
     */
    public function testTheModuleAndTheSdkAcceptTheSamePrices(): void
    {
        $file = dirname(__DIR__) . '/Unit/vectors/fiat.json';
        self::assertSame('ccdd95c5a1865e88c8c333493ef873e8d28a224573c008047e094c049f57d947', hash_file('sha256', $file), 'checkout-spec da3bdb8\'s vectors/fiat.json');
        $vectors = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($vectors);
        self::assertIsArray($vectors['cases']);
        self::assertCount(31, $vectors['cases']);
        foreach ($vectors['cases'] as $case) {
            self::assertIsArray($case);
            self::assertIsArray($case['input']);
            self::assertIsArray($case['expected']);
            $amount = $case['input']['amount'];
            $currency = $case['input']['currency'];
            self::assertIsString($amount);
            self::assertIsString($currency);
            $valid = !isset($case['expected']['error']);
            try {
                Pricing::fiat(['amount' => $amount, 'currency' => $currency]);
                $sdk = true;
            } catch (InvalidOrderException) {
                $sdk = false;
            }
            self::assertSame($valid, $sdk, "the SDK, {$amount} {$currency}");
            self::assertSame($valid, Availability::accepts($amount, $currency), "the module, {$amount} {$currency}");
        }
        self::assertIsArray($vectors['digits']);
        foreach ($vectors['digits'] as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['code']);
            self::assertSame($case['expected'], Client::currencyDigits($case['code']), $case['code']);
        }
    }

    public function testTheModuleUsesNoInternalClassOfTheSdk(): void
    {
        $module = BuiltModule::extract();
        $users = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $relative = substr($file->getPathname(), strlen($module) + 1);
            if ($file->getExtension() === 'php' && !str_starts_with($relative, 'vendor/')
                && str_contains((string) file_get_contents($file->getPathname()), 'Vendor\\Sattleme\\Checkout\\Internal\\')) {
                $users[] = $relative;
            }
        }
        self::assertSame([], $users, 'the SDK\'s internal classes, outside its public API');
    }

    public function testTheRealClientIsMadeWithoutTheNetwork(): void
    {
        self::assertFalse(defined('_SATTLEME_TEST_GATEWAY_'), 'the unit tests run the real SDK');
        self::assertInstanceOf(SdkGateway::class, GatewayFactory::forKey(FakeGateway::KEY));
    }

    public function testAKeyThatIsNotAnIntegrationKeyIsRefusedWithoutBeingRepeated(): void
    {
        $key = 'sattleme_ck_not.a.key-' . bin2hex(random_bytes(8));
        try {
            new SdkGateway($key);
            self::fail('The key was accepted');
        } catch (InvalidKeyException $e) {
            self::assertStringNotContainsString($key, $e->getMessage());
            self::assertStringNotContainsString($key, $e->getTraceAsString());
            self::assertStringNotContainsString($key, print_r($e->getTrace(), true));
        }
        $result = KeyCheck::verify($key, Translator::english());
        self::assertNull($result['merchant']);
        self::assertSame('This is not the secret key of a sattle.me integration (sattleme_ck_…).', $result['error']);
    }

    public function testTheClientNeverShowsItsKey(): void
    {
        $gateway = new SdkGateway(FakeGateway::KEY);
        $secret = explode('.', FakeGateway::KEY)[1];
        ob_start();
        var_dump($gateway);
        $dump = (string) ob_get_clean();
        foreach ([$dump, print_r($gateway, true), var_export($gateway->__debugInfo(), true)] as $output) {
            self::assertStringNotContainsString(FakeGateway::KEY, $output);
            self::assertStringNotContainsString($secret, $output);
        }
        try {
            serialize($gateway);
            self::fail('The client was serialized');
        } catch (\LogicException) {
            // An integration key is never serialized.
        }
    }
}
