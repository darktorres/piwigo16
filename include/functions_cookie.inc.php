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


/**
 * Called during session setup — before Kernel::boot() — so it must have its
 * own implementation rather than delegating through ServiceLocator.
 * CookieService::cookiePath() contains the same logic for post-boot callers.
 */
function cookie_path(): ?string
{
    if (isset($_SERVER['REDIRECT_SCRIPT_NAME']) and !empty($_SERVER['REDIRECT_SCRIPT_NAME'])) {
        $scr = is_scalar($_SERVER['REDIRECT_SCRIPT_NAME']) ? (string) $_SERVER['REDIRECT_SCRIPT_NAME'] : '';
    } elseif (isset($_SERVER['REDIRECT_URL'])) {
        if (isset($_SERVER['PATH_INFO']) and !empty($_SERVER['PATH_INFO']) and
            ($_SERVER['REDIRECT_URL'] !== $_SERVER['PATH_INFO']) and
            str_ends_with(is_scalar($_SERVER['REDIRECT_URL']) ? (string) $_SERVER['REDIRECT_URL'] : '', is_scalar($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '')) {
            $r = is_scalar($_SERVER['REDIRECT_URL']) ? (string) $_SERVER['REDIRECT_URL'] : '';
            $p = is_scalar($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';
            $scr = substr($r, 0, strlen($r) - strlen($p));
        } else {
            $scr = is_scalar($_SERVER['REDIRECT_URL']) ? (string) $_SERVER['REDIRECT_URL'] : '';
        }
    } else {
        $scr = is_scalar($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
    }
    $slash_pos = strrpos($scr, '/');
    $scr = $slash_pos !== false ? substr($scr, 0, $slash_pos) : '';
    if ((strlen($scr) == 0) or ($scr[strlen($scr) - 1] !== '/')) {
        $scr .= '/';
    }
    if (str_starts_with(PHPWG_ROOT_PATH, '../')) {
        $scr = $scr . PHPWG_ROOT_PATH;
        while (1) {
            $new = preg_replace('#[^/]+/\.\.(/|$)#', '', (string) $scr);
            if ($new == $scr) {
                break;
            }
            $scr = $new;
        }
    }
    return $scr;
}

function pwg_set_cookie_var(string $var, mixed $value, ?int $expire = null): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Auth\CookieService::class)->setCookieVar($var, $value, $expire);
}

function pwg_get_cookie_var(string $var, mixed $default = null): mixed
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Auth\CookieService::class)->getCookieVar($var, $default);
}
