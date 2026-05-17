<?php

declare(strict_types=1);

namespace Piwigo\Auth;

final class CookieService
{
    public static function cookiePath(): string
    {
        $redirectScriptNameRaw = $_SERVER['REDIRECT_SCRIPT_NAME'] ?? null;
        $redirectScriptName    = is_string($redirectScriptNameRaw) ? $redirectScriptNameRaw : '';
        $redirectUrlRaw        = $_SERVER['REDIRECT_URL'] ?? null;
        $redirectUrl           = is_string($redirectUrlRaw) ? $redirectUrlRaw : '';
        $pathInfoRaw           = $_SERVER['PATH_INFO'] ?? null;
        $pathInfo              = is_string($pathInfoRaw) ? $pathInfoRaw : '';
        $scriptNameRaw         = $_SERVER['SCRIPT_NAME'] ?? null;
        $scriptName            = is_string($scriptNameRaw) ? $scriptNameRaw : '';
        if (isset($_SERVER['REDIRECT_SCRIPT_NAME']) and
             !empty($_SERVER['REDIRECT_SCRIPT_NAME'])) {
            $scr = $redirectScriptName;
        } elseif (isset($_SERVER['REDIRECT_URL'])) {
            // mod_rewrite is activated for upper level directories. we must set the
            // cookie to the path shown in the browser otherwise it will be discarded.
            if (
                isset($_SERVER['PATH_INFO']) and !empty($_SERVER['PATH_INFO']) and
                ($redirectUrl !== $pathInfo) and
                (str_ends_with($redirectUrl, $pathInfo))
            ) {
                $scr = substr(
                    $redirectUrl,
                    0,
                    strlen($redirectUrl) - strlen($pathInfo)
                );
            } else {
                $scr = $redirectUrl;
            }
        } else {
            $scr = $scriptName;
        }

        $scr_str = $scr;
        $slash_pos = strrpos($scr_str, '/');
        $scr = $slash_pos !== false ? substr($scr_str, 0, $slash_pos) : '';

        if ((strlen($scr) == 0) or ($scr[strlen($scr) - 1] !== '/')) {
            $scr .= '/';
        }

        // The legacy "plugin inside the pwg directory tree" branch (which
        // joined PHPWG_ROOT_PATH = '../' onto $scr and normalised the result)
        // is gone: v17's unified index.php only ever sets PHPWG_ROOT_PATH to
        // './', so the branch was unreachable. Removed during the
        // PHPWG_ROOT_PATH elimination work.
        return $scr;
    }

    public function setCookieVar(string $var, string|null $value, ?int $expire = null): bool
    {
        if ($value == null or $expire === 0) {
            unset($_COOKIE['pwg_' . $var]);
            return setcookie('pwg_' . $var, '', ['expires' => 0, 'path' => self::cookiePath(), 'samesite' => 'Strict']);
        } else {
            $_COOKIE['pwg_' . $var] = $value;
            $expire = is_numeric($expire) ? $expire : strtotime('+10 years');
            return setcookie('pwg_' . $var, $value, ['expires' => $expire, 'path' => self::cookiePath(), 'samesite' => 'Strict']);
        }
    }

    public function getCookieVar(string $var, string $default = ''): string
    {
        $value = $_COOKIE['pwg_' . $var] ?? null;
        return is_string($value) ? $value : $default;
    }
}
