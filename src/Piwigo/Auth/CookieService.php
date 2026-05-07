<?php

declare(strict_types=1);

namespace Piwigo\Auth;

final class CookieService
{
    public static function cookiePath(): ?string
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

        if ((strlen($scr) == 0) or ($scr[strlen($scr) - 1] !== '/')) {
            $scr .= '/';
        }

        if (str_starts_with(PHPWG_ROOT_PATH, '../')) { // plugin inside the pwg directory tree
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

    public function setCookieVar(string $var, mixed $value, ?int $expire = null): bool
    {
        if ($value == null or $expire === 0) {
            unset($_COOKIE['pwg_' . $var]);
            return setcookie('pwg_' . $var, '', ['expires' => 0, 'path' => self::cookiePath() ?? '/', 'samesite' => 'Strict']);
        } else {
            $_COOKIE['pwg_' . $var] = $value;
            $expire = is_numeric($expire) ? $expire : strtotime('+10 years');
            return setcookie('pwg_' . $var, is_scalar($value) ? (string) $value : '', ['expires' => $expire, 'path' => self::cookiePath() ?? '/', 'samesite' => 'Strict']);
        }
    }

    public function getCookieVar(string $var, mixed $default = null): mixed
    {
        if (isset($_COOKIE['pwg_' . $var])) {
            return $_COOKIE['pwg_' . $var];
        } else {
            return $default;
        }
    }
}
