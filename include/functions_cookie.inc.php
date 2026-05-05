<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\cookie
 */


function cookie_path(): ?string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Auth\CookieService::class)->cookiePath();
}

function pwg_set_cookie_var(string $var, mixed $value, ?int $expire = null): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Auth\CookieService::class)->setCookieVar($var, $value, $expire);
}

function pwg_get_cookie_var(string $var, mixed $default = null): mixed
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Auth\CookieService::class)->getCookieVar($var, $default);
}
