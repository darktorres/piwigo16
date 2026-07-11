<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Auth\CookieService;

/**
 * Returns the path to use for the Piwigo cookie.
 * If Piwigo is installed on :
 * http://domain.org/meeting/gallery/
 * it will return : "/meeting/gallery"
 */
function cookie_path(): string
{
    return new CookieService()
        ->cookiePath();
}

/**
 * Persistently stores a variable in pwg cookie.
 * Set $value to null to delete the cookie.
 *
 * @param mixed $value
 * @param int|null $expire
 */
function pwg_set_cookie_var(string $var, $value, $expire = null): bool
{
    return new CookieService()
        ->setCookieVar($var, $value, $expire);
}

/**
 * Retrieves the value of a persistent variable in pwg cookie
 * @see pwg_set_cookie_var
 *
 * @param string $var
 * @param mixed $default
 * @return mixed
 */
function pwg_get_cookie_var($var, $default = null)
{
    return new CookieService()
        ->getCookieVar($var, $default);
}
