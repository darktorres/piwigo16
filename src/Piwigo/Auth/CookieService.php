<?php

declare(strict_types=1);

namespace Piwigo\Auth;

/**
 * Piwigo's own "pwg_*" cookie storage -- distinct from the session cookie,
 * used for auto-login and small persisted UI preferences.
 *
 * setCookieVar()/getCookieVar() stay mixed by design: $_COOKIE itself is
 * PHP-untyped (a crafted `cookie[]=a&cookie[]=b` request header parses into
 * a nested array, same as $_GET/$_POST), not just "every real caller
 * happens to pass a string" -- narrowing here would misrepresent
 * attacker-controlled input as trusted.
 */
final class CookieService
{
    /**
     * Container resolve, not a constructor property -- this class is still
     * manually `new`'d at ~34 real call sites (singleton/service-locator
     * elimination campaign, Phase 11 sub-phase 11G: a required constructor
     * param would ripple across all of them for the sake of this one
     * internal read). Falls back to 0 (the same value an unset instance
     * already defaults to) when Kernel::boot() hasn't run, matching
     * RequestMountDepth::currentStatic()'s own former identical
     * pre-boot fallback.
     */
    private function requestMountDepth(): int
    {
        if (\Piwigo\Core\Kernel::isBooted()) {
            $requestMountDepth = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\RequestMountDepth::class);
            if (! $requestMountDepth instanceof \Piwigo\Core\RequestMountDepth) {
                throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\RequestMountDepth::class);
            }

            return $requestMountDepth->current();
        }

        return 0;
    }

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
                ($_SERVER['REDIRECT_URL'] !== ($_SERVER['PATH_INFO'] ?? null)) and
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
            $scr = $_SERVER['SCRIPT_NAME'] ?? null;
        }

        // Investigated for a mutation-testing gap: this fallback (and the
        // $redirect_url/$path_info fallbacks above, and the 3-clause `and`
        // chain above them) only ever feed into strrpos()/substr()/
        // str_ends_with() calls, all of which treat any slash-free string
        // identically to '' -- so a mutated non-'' fallback (confirmed via
        // manual mutation) produces the exact same final cookiePath()
        // result as long as the replacement text contains no '/', which is
        // true of pest's own mutation placeholder text. Not chased further
        // (would require asserting against that specific undocumented
        // internal placeholder string, which is fragile and tests the tool
        // rather than real behavior).
        $scr = is_string($scr) ? $scr : '';
        $slash_pos = strrpos($scr, '/');
        $scr = $slash_pos !== false ? substr($scr, 0, $slash_pos) : '';

        // add a trailing '/' if needed
        if ((strlen($scr) === 0) or ($scr[strlen($scr) - 1] !== '/')) {
            $scr .= '/';
        }

        $mountDepth = $this->requestMountDepth();
        // `> 0` vs `>= 0`/`> -1` at the mountDepth===0 boundary is
        // unobservable: entering the block below with mountDepth=0 makes
        // str_repeat('../', 0) a no-op ('') and the while loop's own
        // preg_replace() finds nothing to normalize (no '../' segment was
        // ever appended), so $scr comes out identical whether the block
        // runs or not. Confirmed while investigating a mutation-testing
        // gap.
        if ($mountDepth > 0) { // this is maybe a plugin inside pwg directory
            // Known, accepted scope boundary: this branch only normalizes
            // the already-narrow "plugin inside the Piwigo directory tree"
            // case above -- a genuinely external script (outside PWG
            // entirely) is a narrower, unsupported sub-case, not handled.
            // Legacy Coupling Retirement gap-closure (entry-shell
            // define()/include round): used to read the raw PHPWG_ROOT_PATH
            // constant here -- see RequestMountDepth's own docblock.
            $scr .= str_repeat('../', $mountDepth);
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
     *
     * [SEC-13/14] secure/httponly added -- confirmed missing entirely in
     * the original (the separate remember-me cookie, set directly by
     * functions_user.inc.php's log_user()/auto_login()/logout_user(), was
     * already setting both correctly; this is the same, already-proven
     * ini_get()-based pattern, applied here for consistency).
     */
    public function setCookieVar(string $var, mixed $value, ?int $expire = null): bool
    {
        // Neither setcookie() options array below (here or in the branch
        // further down) is independently verifiable from a CLI test
        // process: setcookie() doesn't emit real, inspectable headers
        // under the CLI SAPI (confirmed live -- headers_list() stays
        // empty after a real setcookie() call), and there's no
        // dependency-injectable seam to intercept the call itself.
        // setcookie()'s own bool return value and $_COOKIE's own
        // superglobal mutation are the only externally observable effects
        // this method has. Confirmed while investigating a
        // mutation-testing gap.
        if ($value === null or $expire === 0) {
            unset($_COOKIE['pwg_' . $var]);

            return setcookie('pwg_' . $var, '', [
                'expires' => 0,
                'path' => $this->cookiePath(),
                'samesite' => 'Strict',
                'secure' => (bool) ini_get('session.cookie_secure'),
                'httponly' => (bool) ini_get('session.cookie_httponly'),
            ]);
        }

        $_COOKIE['pwg_' . $var] = $value;
        // Both fallbacks below feed only the same not-independently-
        // observable setcookie() call documented above -- confirmed live
        // that neither an unconverted $expire (e.g. null) nor a
        // non-empty $value_str changes setcookie()'s own bool return
        // value, and $_COOKIE was already written with the raw $value on
        // the line above, unaffected by either.
        $expire = is_numeric($expire) ? $expire : strtotime('+10 years');
        $value_str = is_scalar($value) ? (string) $value : '';

        return setcookie('pwg_' . $var, $value_str, [
            'expires' => $expire,
            'path' => $this->cookiePath(),
            'samesite' => 'Strict',
            'secure' => (bool) ini_get('session.cookie_secure'),
            'httponly' => (bool) ini_get('session.cookie_httponly'),
        ]);
    }

    /**
     * Retrieves the value of a persistent variable in pwg cookie.
     *
     * @see setCookieVar()
     */
    public function getCookieVar(string $var, mixed $default = null): mixed
    {
        return $_COOKIE['pwg_' . $var] ?? $default;
    }
}
