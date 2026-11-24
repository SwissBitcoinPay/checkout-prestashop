<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\BuiltModule;

/**
 * docs/REVIEW-NOTES.md, for PrestaShop Addons' reviewers: the repository's, not the ZIP's; each
 * line of the module and of the SDK it cites holds what it says there.
 */
final class ReviewNotesTest extends TestCase
{
    /** "path:line" as the notes cite them, and what the line holds. */
    private const CITED = [
        'sattleme/src/Payment/Creator.php:117' => '$this->module->validateOrder(',
        'sattleme/src/Payment/Creator.php:102' => '$cart->getOrderTotal(true, \Cart::BOTH)',
        'sattleme/src/Payment/Checker.php:92' => '$gateway->getStatus(',
        'sattleme/src/Payment/Settler.php:158' => 'private function complete(',
        'sattleme/src/Sdk/SdkGateway.php:51' => '$this->client = new Client(',
        'sattleme/src/Sdk/SdkGateway.php:54' => "'logger' => Logger::forSdk(),",
        'sattleme/src/Sdk/Environment.php:63' => "new Client(['key' => '']);",
        'sdk/src/Client.php:116' => 'IntegrationKey::parse(',
        'sdk/src/Client.php:145' => "self::logger(\$options['logger'] ?? null)",
        'sdk/src/Client.php:894' => 'error_log(',
        'sattleme/controllers/front/validation.php:42' => "!== 'POST'",
        'sattleme/controllers/front/validation.php:51' => 'Tokens::equals(Tokens::checkout($idCart)',
        'sattleme/controllers/front/validation.php:62' => '(int) $first->id_customer === $idCustomer',
        'sattleme/controllers/front/validation.php:66' => '(int) $cart->id !== $idCart || (int) $cart->id_customer !== $idCustomer',
        'sattleme/controllers/front/validation.php:67' => '(int) $cart->id_address_delivery === 0',
        'sattleme/controllers/front/validation.php:76' => '!Validate::isLoadedObject($customer)',
    ];

    public function testItsCitationsHoldWhatItSays(): void
    {
        $root = BuiltModule::root();
        $notes = (string) file_get_contents($root . '/docs/REVIEW-NOTES.md');
        foreach (self::CITED as $where => $code) {
            [$file, $line] = explode(':', $where);
            $lines = file($root . '/' . $file, \FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines, $file);
            self::assertStringContainsString($code, $lines[(int) $line - 1] ?? '', $where);
        }
        foreach (['Creator.php:117', 'Creator.php:102', 'Checker.php:92', 'SdkGateway.php:51', 'Environment.php:63', 'Client.php:894', 'Client.php:116', 'Client.php:145', 'line 42', 'line 51', 'lines 66 to 67', 'lines 75 to 76'] as $cited) {
            self::assertStringContainsString($cited, $notes);
        }
        // ps_wirepayment's line, as the notes quote it (v3.0.0 and v2.2.1, line 63).
        self::assertStringContainsString("\$this->module->validateOrder(\$cart->id, (int) Configuration::get('PS_OS_BANKWIRE'), \$total, \$this->module->displayName, null, \$mailVars, (int) \$currency->id, false, \$customer->secure_key);", $notes);
        // Not the module's: never in its ZIP.
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(BuiltModule::zip(), \ZipArchive::RDONLY));
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            self::assertStringNotContainsString('REVIEW-NOTES', (string) $zip->getNameIndex($i));
        }
        $zip->close();
    }
}
