<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Returns the path to use for the Piwigo cookie.
 * If Piwigo is installed on :
 * http://domain.org/meeting/gallery/
 * it will return : "/meeting/gallery"
 */
function cookie_path(): string
{
    if (isset($_SERVER['REDIRECT_SCRIPT_NAME']) and
         ! empty($_SERVER['REDIRECT_SCRIPT_NAME'])) {
        $scr = $_SERVER['REDIRECT_SCRIPT_NAME'];
    } elseif (isset($_SERVER['REDIRECT_URL'])) {
        $redirect_url = is_string($_SERVER['REDIRECT_URL']) ? $_SERVER['REDIRECT_URL'] : '';
        $path_info = isset($_SERVER['PATH_INFO']) && is_string($_SERVER['PATH_INFO'])
            ? $_SERVER['PATH_INFO']
            : '';

        // mod_rewrite is activated for upper level directories. we must set the
        // cookie to the path shown in the browser otherwise it will be discarded.
        if (
            isset($_SERVER['PATH_INFO']) and ! empty($_SERVER['PATH_INFO']) and
            ($_SERVER['REDIRECT_URL'] !== $_SERVER['PATH_INFO']) and
            (str_ends_with($redirect_url, $path_info))
        ) {
            $scr = substr(
                $redirect_url,
                0,
                strlen($redirect_url) - strlen($path_info)
            );
        } else {
            $scr = $_SERVER['REDIRECT_URL'];
        }
    } else {
        $scr = $_SERVER['SCRIPT_NAME'];
    }

    $scr = is_string($scr) ? $scr : '';
    $slash_pos = strrpos($scr, '/');
    assert($slash_pos !== false);
    $scr = substr($scr, 0, $slash_pos);

    // add a trailing '/' if needed
    if ((strlen($scr) == 0) or ($scr[strlen($scr) - 1] !== '/')) {
        $scr .= '/';
    }

    if (str_starts_with(PHPWG_ROOT_PATH, '../')) { // this is maybe a plugin inside pwg directory
        // TODO - what if it is an external script outside PWG ?
        $scr .= PHPWG_ROOT_PATH;
        while (true) {
            $new = preg_replace('#[^/]+/\.\.(/|$)#', '', $scr);
            // fixed, valid pattern -- preg_replace() only returns null on a
            // compile error, which is unreachable here.
            assert($new !== null);
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
 * @param mixed $value
 * @param int|null $expire
 */
function pwg_set_cookie_var(string $var, $value, $expire = null): bool
{
    if ($value == null or $expire === 0) {
        unset($_COOKIE['pwg_' . $var]);
        return setcookie('pwg_' . $var, '', [
            'expires' => 0,
            'path' => cookie_path(),
        ]);

    } else {
        $_COOKIE['pwg_' . $var] = $value;
        $expire = is_numeric($expire) ? $expire : strtotime('+10 years');
        $value_str = is_scalar($value) ? (string) $value : '';
        return setcookie('pwg_' . $var, $value_str, [
            'expires' => $expire,
            'path' => cookie_path(),
        ]);
    }
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
    if (isset($_COOKIE['pwg_' . $var])) {
        return $_COOKIE['pwg_' . $var];
    } else {
        return $default;
    }
}
