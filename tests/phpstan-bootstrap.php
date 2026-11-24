<?php

declare(strict_types=1);

/*
 * The constants of an installed PrestaShop that php-dev-tools' bootstrap does not define (the
 * shop's own keys), for PHPStan only: their values do not matter, their types do.
 */
if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'def00000');
}
