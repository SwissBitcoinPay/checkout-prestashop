<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\BuiltModule;

/**
 * The module's templates, script and stylesheet as the ZIP holds them: every value escaped as it is
 * written (Smarty escapes nothing in the back office, and PrestaShop's front office escaping is a
 * setting), none written as it is (nofilter: PrestaShop Addons' validator), no inline script,
 * nothing loaded from another host.
 */
final class TemplatesTest extends TestCase
{
    /** @return list<string> */
    private static function templates(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BuiltModule::extract() . '/views/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->getExtension() === 'tpl') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function testEveryValueIsEscapedAsItIsWritten(): void
    {
        $templates = self::templates();
        self::assertCount(7, $templates);
        foreach ($templates as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all('/\{\$[^}]*\}/', $code, $matches);
            foreach ($matches[0] as $output) {
                self::assertMatchesRegularExpression("/\\|(escape:'htmlall':'UTF-8'|escape:'url':'UTF-8'|intval)\\}$/", $output, basename($file) . ': ' . $output);
            }
            self::assertStringNotContainsString('nofilter', $code, basename($file));
            self::assertStringNotContainsString('{literal}', $code, basename($file));
            self::assertStringNotContainsStringIgnoringCase('<script', $code, basename($file));
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $code, basename($file) . ': an inline event handler');
        }
    }

    public function testNothingIsLoadedFromAnotherHost(): void
    {
        foreach (self::templates() as $file) {
            // Its comments (the licence header) aside.
            $code = preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents($file)) ?? '';
            // No address of another site, neither loaded nor linked (PrestaShop Addons: no external links).
            self::assertDoesNotMatchRegularExpression('#(https?:)?//[a-z0-9.-]+\.[a-z]{2,}#i', $code, basename($file));
        }
        $script = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(BuiltModule::extract() . '/views/js/pay.js')) ?? '';
        self::assertDoesNotMatchRegularExpression('#https?://#', $script);
        self::assertDoesNotMatchRegularExpression('/\beval\s*\(|new Function|document\.write|innerHTML|insertAdjacentHTML|console\./', $script);
        $style = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(BuiltModule::extract() . '/views/css/pay.css')) ?? '';
        self::assertStringNotContainsString('@import', $style);
        self::assertStringNotContainsString('url(', $style);
    }

    public function testThePageWorksWithoutItsScript(): void
    {
        $page = (string) file_get_contents(BuiltModule::extract() . '/views/templates/front/pay.tpl');
        // Its "Check again" is a link to the page itself, its new invoice a form: no script needed.
        self::assertStringContainsString('<a class="btn btn-secondary sattleme-pay__check" href="{$sattleme_urls.page|escape:\'htmlall\':\'UTF-8\'}">', $page);
        self::assertStringContainsString('<form method="post" action="{$sattleme_urls.page|escape:\'htmlall\':\'UTF-8\'}" class="sattleme-pay__renew">', $page);
        self::assertStringContainsString('<noscript>', $page);
        // The copy buttons need the script: hidden until it shows them.
        self::assertSame(2, substr_count($page, 'data-sattleme-copy='));
        self::assertSame(2, preg_match_all('/<button type="button"[^>]*data-sattleme-copy="[a-z-]+" hidden>/', $page));
    }
}
