<?php

declare(strict_types=1);

namespace Piwigo\Auth;

/**
 * Piwigo's own "pwg_*" cookie storage -- distinct from the session cookie,
 * used for auto-login and small persisted UI preferences.
 */
final class CookieService
{
    /**
     * Returns the path to use for the Piwigo cookie.
     * If Piwigo is installed on:
     * http://domain.org/meeting/gallery/
     * it will return: "/meeting/gallery"
     */
    public function cookiePath(): string
    {
        $redirectScriptName = isset($_SERVER['REDIRECT_SCRIPT_NAME']) && is_string($_SERVER['REDIRECT_SCRIPT_NAME'])
            ? $_SERVER['REDIRECT_SCRIPT_NAME']
            : '';

        if ($redirectScriptName !== '') {
            $scr = $redirectScriptName;
        } elseif (isset($_SERVER['REDIRECT_URL'])) {
            $redirect_url = is_string($_SERVER['REDIRECT_URL']) ? $_SERVER['REDIRECT_URL'] : '';
            $path_info = isset($_SERVER['PATH_INFO']) && is_string($_SERVER['PATH_INFO'])
                ? $_SERVER['PATH_INFO']
                : '';

            // mod_rewrite is activated for upper level directories. we must set the
            // cookie to the path shown in the browser otherwise it will be discarded.
            if (
                $path_info !== '' and
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
        $scr = $slash_pos !== false ? substr($scr, 0, $slash_pos) : '';

        // add a trailing '/' if needed
        if ((strlen($scr) === 0) or ($scr[strlen($scr) - 1] !== '/')) {
            $scr .= '/';
        }

        if (str_starts_with(PHPWG_ROOT_PATH, '../')) { // this is maybe a plugin inside pwg directory
            // TODO - what if it is an external script outside PWG ?
            $scr .= PHPWG_ROOT_PATH;
            while (true) {
                $new = preg_replace('#[^/]+/\.\.(/|$)#', '', $scr);
                // fixed, valid pattern -- preg_replace() only returns null on a
                // compile error, which is unreachable here.
                if ($new === null) {
                    break;
                }
                if ($new === $scr) {
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
     */
    public function setCookieVar(string $var, mixed $value, ?int $expire = null): bool
    {
        if ($value === null or $expire === 0) {
            unset($_COOKIE['pwg_' . $var]);

            return setcookie('pwg_' . $var, '', [
                'expires' => 0,
                'path' => $this->cookiePath(),
                'samesite' => 'Strict',
            ]);
        }

        $_COOKIE['pwg_' . $var] = $value;
        $expire = is_numeric($expire) ? $expire : strtotime('+10 years');
        $value_str = is_scalar($value) ? (string) $value : '';

        return setcookie('pwg_' . $var, $value_str, [
            'expires' => $expire,
            'path' => $this->cookiePath(),
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Retrieves the value of a persistent variable in pwg cookie.
     *
     * @see setCookieVar()
     */
    public function getCookieVar(string $var, mixed $default = null): mixed
    {
        if (isset($_COOKIE['pwg_' . $var])) {
            return $_COOKIE['pwg_' . $var];
        }

        return $default;
    }
}
