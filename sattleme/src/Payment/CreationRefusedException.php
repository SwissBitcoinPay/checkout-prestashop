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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * No invoice could be made: the error's code (Errors), which tells the customer what to do. Its
 * message is the code alone: never a key, nor the SDK's internals.
 */
final class CreationRefusedException extends \RuntimeException
{
    /** @var string */
    private $errorCode;

    public function __construct(string $errorCode, ?\Throwable $previous = null)
    {
        parent::__construct('No invoice: ' . $errorCode, 0, $previous);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
