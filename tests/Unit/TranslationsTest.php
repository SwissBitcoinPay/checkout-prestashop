<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\BuiltModule;
use Sattleme\PrestaShop\Tests\Support\Translator;
use Sattleme\PrestaShop\Tools\Translations;
use Sattleme\PrestaShop\Wordings;

/**
 * French, German and Italian: every wording of the module translated, as PrestaShop's translation
 * system loads it (translations/<locale>/ModulesSattleme<Part>.<locale>.xlf in the ZIP).
 */
final class TranslationsTest extends TestCase
{
    public function testEveryWordingIsTranslatedInEveryLanguage(): void
    {
        $module = BuiltModule::extract();
        self::assertSame([], Translations::problems($module));
        $wordings = Translations::extract($module);
        self::assertGreaterThan(40, count($wordings['Modules.Sattleme.Admin']));
        self::assertGreaterThan(30, count($wordings['Modules.Sattleme.Shop']));
    }

    public function testTheFilesArePrestaShopsXliff(): void
    {
        $module = BuiltModule::extract();
        foreach (Translations::LOCALES as $locale) {
            foreach (Translations::DOMAINS as $domain) {
                $file = Translations::file($module, $domain, $locale);
                // PrestaShop loads a module's files whose names are ModulesSattleme<Part>.<locale>.xlf.
                self::assertMatchesRegularExpression('/^ModulesSattleme(Admin|Shop)\.' . preg_quote($locale, '/') . '\.xlf$/', basename($file));
                $document = new \DOMDocument();
                self::assertTrue($document->load($file, LIBXML_NONET));
                self::assertSame('urn:oasis:names:tc:xliff:document:1.2', $document->documentElement?->namespaceURI);
                self::assertSame('1.2', $document->documentElement->getAttribute('version'));
            }
        }
    }

    public function testSomeWordingsAsTheCustomerReadsThem(): void
    {
        $module = BuiltModule::extract();
        $expected = [
            'fr-FR' => 'Payer en bitcoin',
            'de-DE' => 'Mit Bitcoin bezahlen',
            'it-IT' => 'Paga con bitcoin',
        ];
        foreach ($expected as $locale => $text) {
            self::assertSame($text, Translations::read(Translations::file($module, 'Modules.Sattleme.Shop', $locale))['Pay with bitcoin']);
        }
    }

    public function testAPlaceholderIsCountedEachTimeItIsUsed(): void
    {
        self::assertSame(['%received%', '%sats%'], Translations::placeholders('%sats% sats: %received% came'));
        self::assertSame(['%ref%', '%ref%'], Translations::placeholders('%ref%, again %ref%'));
        self::assertSame([], Translations::placeholders('100% sure'));
    }

    public function testAWordingInAnotherLocaleComesFromTheModulesFilesWhenTheTranslatorHasNone(): void
    {
        // PrestaShop's translator of a request holds that request's catalogues only, and a module's
        // only once it found them in the module's directory (uploaded and installed in one
        // request, it has not): the order states' names, the method's title and description in
        // each language, the merchant's notes in the shop's language come from the module's files.
        $nothing = Translator::english();
        self::assertSame(BuiltModule::extract() . '/translations', Wordings::directory());
        self::assertSame('Payer en bitcoin', Wordings::trans($nothing, 'Pay with bitcoin', 'Modules.Sattleme.Shop', 'fr-FR'));
        self::assertSame('Warten auf Bitcoin-Zahlung', Wordings::trans($nothing, 'Awaiting bitcoin payment', 'Modules.Sattleme.Admin', 'de-DE'));
        self::assertSame(
            str_replace(['%received%', '%sats%', '%ref%'], ['12000', '49000', 'R-1'], Translations::read(Translations::file(BuiltModule::extract(), 'Modules.Sattleme.Admin', 'it-IT'))[self::UNDERPAID]),
            Wordings::trans($nothing, self::UNDERPAID, 'Modules.Sattleme.Admin', 'it-IT', ['%received%' => '12000', '%sats%' => '49000', '%ref%' => 'R-1']),
        );
        // The translator's own wording wins (a merchant's translation); English where the module has none.
        self::assertSame('Pay with bitcoin', Wordings::trans($nothing, 'Pay with bitcoin', 'Modules.Sattleme.Shop', 'es-ES'));
        self::assertSame('Payer en bitcoin', Wordings::trans(Translator::forLocale('fr-FR'), 'Pay with bitcoin', 'Modules.Sattleme.Shop', 'fr-FR'));
    }

    private const UNDERPAID = 'The bitcoin invoice ended underpaid: %received% of %sats% sats came in time, and reached your wallet. Settle the difference with the customer (refund it, or have the rest paid otherwise). Reference: %ref%.';
}
