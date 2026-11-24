<?php

declare(strict_types=1);

/*
 * The tests' switch of the module's SDK (GatewayFactory): the shop's config/defines_custom.inc.php
 * names this file in _SATTLEME_TEST_GATEWAY_ (tests/shop/shop.sh), and the module asks it for the
 * gateway of a key. Never in a real shop.
 */

use Sattleme\PrestaShop\Sdk\Gateway;
use Sattleme\PrestaShop\Tests\Fake\FakeGateway;
use Sattleme\PrestaShop\Tests\Fake\FakeState;

require_once __DIR__ . '/FakeState.php';
require_once __DIR__ . '/FakeGateway.php';

return static function (#[SensitiveParameter] string $key): Gateway {
    return FakeGateway::forKey($key, FakeState::forTests());
};
