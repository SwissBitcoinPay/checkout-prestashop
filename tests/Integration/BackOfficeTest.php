<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Tests\Support\HttpShop;

/**
 * The shop's back office over HTTP, once the tests' process booted PrestaShop (tests/bootstrap.php,
 * with the repository's development autoloader): it answers, its container compiled by the shop
 * itself (tests/shop/shop.sh setup, or its console after tests/shop/shop.sh module), never by the
 * tests' process with classes the shop lacks.
 */
final class BackOfficeTest extends TestCase
{
    public function testTheBackOfficeAnswersOnceTheTestsBootedPrestaShop(): void
    {
        $merchant = HttpShop::merchant();
        self::assertSame(200, $merchant->browser->get($merchant->url('improve/modules/manage'))->status, 'the Module Manager');
        self::assertSame(200, $merchant->configurePage()->status, 'the module\'s settings');
    }
}
