<?php

declare(strict_types=1);

/*
 * A module of the test shop's (tests/shop/shop.sh), never of a real one: another module that fails
 * while it reacts to a payment, as a buggy module does, with a PHP Error, which PrestaShop's
 * Hook::exec lets through (it catches Exception only). It fails while the file
 * fail-payment-confirmation is in the fake SDK's directory of the shop (_SATTLEME_TEST_FAKE_DIR_):
 * the tests make it so (tests/Integration/CompletionTest.php). It also reacts to a change of an
 * order's state slowly, or aborts it (ConcurrencyTest), while the files SLOW or ABORT are there.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/** What PrestaShop's Hook rethrows of a module (any other exception it swallows): the change aborted. */
class SattlemefailingAbort extends RuntimeException implements PrestaShop\PrestaShop\Core\Module\Exception\ModuleErrorInterface
{
}

class Sattlemefailing extends Module
{
    /** The file whose presence makes the module fail, in the fake's directory. */
    public const SWITCH = 'fail-payment-confirmation';

    /** The file that makes its reaction to a change of an order's state last its content's seconds. */
    public const SLOW = 'slow-order-status-update';

    /** The file that makes it abort a change of an order's state, after sattle.me let it through. */
    public const ABORT = 'abort-order-status-update';

    public function __construct()
    {
        $this->name = 'sattlemefailing';
        $this->version = '1.0.0';
        $this->author = 'sattle.me tests';
        $this->tab = 'others';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        parent::__construct();
        $this->displayName = 'sattle.me tests: a module that fails on a payment';
    }

    public function install(): bool
    {
        return (bool) parent::install() && (bool) $this->registerHook(['actionPaymentConfirmation', 'actionOrderStatusUpdate']);
    }

    /**
     * A module that reacts slowly to a change of an order's state, or aborts it, after sattle.me
     * (installed after it): the change is written once every module reacted.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionOrderStatusUpdate(array $params): void
    {
        $dir = defined('_SATTLEME_TEST_FAKE_DIR_') ? constant('_SATTLEME_TEST_FAKE_DIR_') : null;
        if (is_string($dir) && is_file($dir . '/' . self::SLOW)) {
            sleep((int) file_get_contents($dir . '/' . self::SLOW));
        }
        if (is_string($dir) && is_file($dir . '/' . self::ABORT)) {
            throw new SattlemefailingAbort('sattlemefailing: aborting the change on purpose');
        }
    }

    /** @param array<string, mixed> $params */
    public function hookActionPaymentConfirmation(array $params): void
    {
        $dir = defined('_SATTLEME_TEST_FAKE_DIR_') ? constant('_SATTLEME_TEST_FAKE_DIR_') : null;
        if (is_string($dir) && is_file($dir . '/' . self::SWITCH)) {
            throw new TypeError('sattlemefailing: failing on purpose while reacting to the payment');
        }
    }
}
