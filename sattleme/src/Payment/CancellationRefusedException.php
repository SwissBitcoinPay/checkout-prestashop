<?php

/**
 * sattle.me for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License that is bundled with this module in the file
 * LICENSE, also available at https://opensource.org/licenses/MIT
 *
 * @author    sattle.me
 * @copyright 2026 sattle.me
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace Sattleme\PrestaShop\Payment;

use PrestaShop\PrestaShop\Core\Module\Exception\ModuleErrorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The cancellation of an order refused (Cancellation), thrown from the hook
 * actionOrderStatusUpdate, which PrestaShop runs before it changes anything of the order (its
 * state, its stock, its history, its e-mails). A ModuleErrorInterface: PrestaShop's Hook rethrows
 * it (any other exception of a module it swallows), and the back office shows its message as it
 * is; a webservice request fails with it.
 */
final class CancellationRefusedException extends \RuntimeException implements ModuleErrorInterface
{
}
