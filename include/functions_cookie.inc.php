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
 * Returns the path to use for the Piwigo cookie.
 * If Piwigo is installed on :
 * http://domain.org/meeting/gallery/
 * it will return : "/meeting/gallery"
 *
 * @return string
 */
function cookie_path(): ?string
{
    if (isset($_SERVER['REDIRECT_SCRIPT_NAME']) and
         !empty($_SERVER['REDIRECT_SCRIPT_NAME'])) {
        $scr = is_scalar($_SERVER['REDIRECT_SCRIPT_NAME']) ? (string) $_SERVER['REDIRECT_SCRIPT_NAME'] : '';
    } elseif (isset($_SERVER['REDIRECT_URL'])) {
        // mod_rewrite is activated for upper level directories. we must set the
        // cookie to the path shown in the browser otherwise it will be discarded.
        if (
            isset($_SERVER['PATH_INFO']) and !empty($_SERVER['PATH_INFO']) and
            ($_SERVER['REDIRECT_URL'] !== $_SERVER['PATH_INFO']) and
            (str_ends_with(is_scalar($_SERVER['REDIRECT_URL']) ? (string) $_SERVER['REDIRECT_URL'] : '', is_scalar($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : ''))
        ) {
            $redirect_url_str = is_scalar($_SERVER['REDIRECT_URL']) ? (string) $_SERVER['REDIRECT_URL'] : '';
            $path_info_str = is_scalar($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';
            $scr = substr(
                $redirect_url_str,
                0,
                strlen($redirect_url_str) - strlen($path_info_str)
            );
        } else {
            $scr = is_scalar($_SERVER['REDIRECT_URL']) ? (string) $_SERVER['REDIRECT_URL'] : '';
        }
    } else {
        $scr = is_scalar($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
    }

    $scr_str = $scr;
    $slash_pos = strrpos($scr_str, '/');
    $scr = $slash_pos !== false ? substr($scr_str, 0, $slash_pos) : '';

    // add a trailing '/' if needed
    if ((strlen($scr) == 0) or ($scr[strlen($scr) - 1] !== '/')) {
        $scr .= '/';
    }

    if (str_starts_with(PHPWG_ROOT_PATH, '../')) { // plugin inside the pwg directory tree
        $scr = $scr.PHPWG_ROOT_PATH;
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

/**
 * Persistently stores a variable in pwg cookie.
 * Set $value to null to delete the cookie.
 *
 *  string
 * @param mixed $value
 * @param int|null $expire
 */
function pwg_set_cookie_var(string $var, $value, $expire = null): bool
{
    if ($value == null or $expire === 0) {
        unset($_COOKIE['pwg_'.$var]);
        return setcookie('pwg_'.$var, '', ['expires' => 0, 'path' => cookie_path() ?? '/', 'samesite' => 'Strict']);

    } else {
        $_COOKIE['pwg_'.$var] = $value;
        $expire = is_numeric($expire) ? $expire : strtotime('+10 years');
        return setcookie('pwg_'.$var, is_scalar($value) ? (string) $value : '', ['expires' => $expire, 'path' => cookie_path() ?? '/', 'samesite' => 'Strict']);
    }
}

/**
 * Retrieves the value of a persistent variable in pwg cookie
 * @see pwg_set_cookie_var
 *
 * @param mixed $default
 * @return mixed
 */
function pwg_get_cookie_var(string $var, $default = null)
{
    if (isset($_COOKIE['pwg_'.$var])) {
        return $_COOKIE['pwg_'.$var];
    } else {
        return $default;
    }
}
