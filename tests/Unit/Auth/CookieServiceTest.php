<?php

declare(strict_types=1);

use Piwigo\Auth\CookieService;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Tests\Support\KernelContainerOverride;

beforeEach(function (): void {
    unset($_SERVER['REDIRECT_SCRIPT_NAME'], $_SERVER['REDIRECT_URL'], $_SERVER['PATH_INFO']);
});

/**
 * CookieService's own private lazy requestMountDepth() helper gracefully
 * falls back to 0 when Kernel hasn't booted -- most tests in this file
 * need no container at all. Tests needing a non-zero depth boot a real
 * one via KernelContainerOverride::with().
 */
function cookieServiceTestWithMountDepth(int $depth, callable $fn): mixed
{
    return KernelContainerOverride::with([RequestMountDepth::class => new RequestMountDepth($depth)], $fn);
}

test('cookiePath falls back to SCRIPT_NAME when no rewrite headers are present', function (): void {
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';

    expect(new CookieService()->cookiePath())->toBe('/piwigo/');
});

test('cookiePath prefers REDIRECT_SCRIPT_NAME when set', function (): void {
    $_SERVER['SCRIPT_NAME'] = '/should-not-be-used/index.php';
    $_SERVER['REDIRECT_SCRIPT_NAME'] = '/redirected/index.php';

    expect(new CookieService()->cookiePath())->toBe('/redirected/');
});

test('cookiePath strips the PATH_INFO suffix from REDIRECT_URL before deriving the directory', function (): void {
    // mod_rewrite appends PATH_INFO to the script name in REDIRECT_URL;
    // cookiePath() must strip it before deriving the containing directory,
    // not treat the whole REDIRECT_URL as the script path.
    $_SERVER['REDIRECT_URL'] = '/piwigo/index.php/foo/bar';
    $_SERVER['PATH_INFO'] = '/foo/bar';

    expect(new CookieService()->cookiePath())->toBe('/piwigo/');
});

test('setCookieVar writes the pwg_-prefixed key into $_COOKIE', function (): void {
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';
    $service = new CookieService();

    $service->setCookieVar('rememberme', 'yes');

    expect($_COOKIE['pwg_rememberme'] ?? null)->toBe('yes');
});

test('setCookieVar with null value clears the cookie', function (): void {
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';
    $_COOKIE['pwg_rememberme'] = 'yes';
    $service = new CookieService();

    $service->setCookieVar('rememberme', null);

    expect(isset($_COOKIE['pwg_rememberme']))->toBeFalse();
});

test('setCookieVar clears the cookie when expire is 0, even with a non-null value', function (): void {
    // `$value === null or $expire === 0` is a real OR, not an AND -- a
    // non-null value with expire=0 must still clear the cookie.
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';
    $_COOKIE['pwg_rememberme'] = 'stale';
    $service = new CookieService();

    $service->setCookieVar('rememberme', 'ignored-because-expire-is-0', 0);

    expect(isset($_COOKIE['pwg_rememberme']))->toBeFalse();
});

test('setCookieVar treats a non-numeric expire as absent, defaulting to a far-future expiry', function (): void {
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';
    $service = new CookieService();

    // is_numeric($expire) is false for a non-numeric int|null param in
    // practice only via a genuinely absent (null, the method's own
    // default) expire -- passing null here exercises the same fallback
    // strtotime('+10 years') branch without needing a numeric-string
    // param type this method doesn't even accept.
    $result = $service->setCookieVar('sessionpref', 'value', null);

    expect($result)->toBeTrue();
    expect($_COOKIE['pwg_sessionpref'] ?? null)->toBe('value');
});

test('setCookieVar does not throw for a non-scalar value', function (): void {
    // is_scalar($value)'s false branch (falling back to '' for the actual
    // cookie wire value setcookie() receives) isn't independently
    // observable in a CLI test process -- setcookie() doesn't emit real
    // headers under the CLI SAPI (confirmed live: headers_list() stays
    // empty after a real setcookie() call here), so this only proves the
    // non-scalar path doesn't throw, not the exact fallback string.
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';
    $service = new CookieService();

    $result = $service->setCookieVar('arraypref', ['not', 'scalar']);

    expect($result)->toBeTrue();
    // $_COOKIE itself mirrors the raw, unstringified value (a separate
    // assignment from the setcookie() wire value) -- checked directly,
    // not through an accessor: both real named readers (P17-23 Phase 9)
    // narrow to ?string, so they can't observe this non-scalar shape.
    expect($_COOKIE['pwg_arraypref'] ?? null)->toBe(['not', 'scalar']);
});

test('getDisplayThumbnailPref returns null when the cookie is unset', function (): void {
    unset($_COOKIE['pwg_display_thumbnail']);

    expect(new CookieService()->getDisplayThumbnailPref())->toBeNull();
});

test('getDisplayThumbnailPref returns the raw cookie value when set', function (): void {
    $_COOKIE['pwg_display_thumbnail'] = 'no_display_thumbnail';

    expect(new CookieService()->getDisplayThumbnailPref())->toBe('no_display_thumbnail');
});

test('getDisplayThumbnailPref narrows a non-string cookie value to null', function (): void {
    // Same $_COOKIE-can-hold-a-nested-array shape as the setCookieVar
    // non-scalar test above (a crafted `cookie[pwg_display_thumbnail][]=x`
    // request header) -- must not leak the array back out as if it were a
    // real preference string.
    $_COOKIE['pwg_display_thumbnail'] = ['not', 'scalar'];

    expect(new CookieService()->getDisplayThumbnailPref())->toBeNull();
});

test('getAnonymousRaterId returns null when the cookie is unset', function (): void {
    unset($_COOKIE['pwg_anonymous_rater']);

    expect(new CookieService()->getAnonymousRaterId())->toBeNull();
});

test('getAnonymousRaterId returns the raw cookie value when set', function (): void {
    $_COOKIE['pwg_anonymous_rater'] = '10.0.0.1';

    expect(new CookieService()->getAnonymousRaterId())->toBe('10.0.0.1');
});

test('cookiePath does not throw when PATH_INFO is present but not a string', function (): void {
    // isset()+is_string() must both hold (a real AND, not an OR) -- a
    // present-but-non-string PATH_INFO that slipped through an OR would
    // reach str_ends_with()'s own strict `string $needle` parameter and
    // throw a TypeError instead of defaulting to ''.
    $_SERVER['REDIRECT_URL'] = '/piwigo/index.php';
    $_SERVER['PATH_INFO'] = ['not', 'a', 'string'];

    expect(new CookieService()->cookiePath())->toBe('/piwigo/');
});

test('cookiePath defaults to just a trailing slash when no script-name source is present at all', function (): void {
    unset($_SERVER['SCRIPT_NAME']);

    expect(new CookieService()->cookiePath())->toBe('/');
});

test('cookiePath does not double the trailing slash when the directory already ends in one after truncation', function (): void {
    // strrpos()+substr() truncates up to (not including) the *last* '/'
    // -- for the result to already end in '/', that last '/' must itself
    // be immediately preceded by another '/'. Checking the wrong index
    // (off by one) here would see a non-'/' character and wrongly append
    // a second slash.
    $_SERVER['SCRIPT_NAME'] = '/a//index.php';

    expect(new CookieService()->cookiePath())->toBe('/a/');
});

test('cookiePath strips a trailing non-slash character down to the containing directory', function (): void {
    // strrpos()'s own false branch (no '/' at all) and the exact
    // strlen()-1 boundary for "already ends in /" both matter here.
    $_SERVER['SCRIPT_NAME'] = 'index.php';

    expect(new CookieService()->cookiePath())->toBe('/');
});

test('cookiePath leaves an already-slash-terminated path alone, not appending a second slash', function (): void {
    $_SERVER['SCRIPT_NAME'] = '/piwigo/sub/';

    expect(new CookieService()->cookiePath())->toBe('/piwigo/sub/');
});

test('cookiePath does not append any ../ when mount depth is exactly 0', function (): void {
    // 0 is RequestMountDepth's own default (no container needed at all --
    // currentStatic() gracefully falls back to it when Kernel isn't
    // booted), so this needs no explicit setup.
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';

    expect(new CookieService()->cookiePath())->toBe('/piwigo/');
});

test('cookiePath repeats ../ exactly once per mount depth level, not just once', function (): void {
    // Distinguishes str_repeat('../', $mountDepth) from a version that
    // appends '../' only once regardless of depth -- depth 2 must
    // normalize 2 levels up, not 1.
    cookieServiceTestWithMountDepth(2, function (): void {
        $_SERVER['SCRIPT_NAME'] = '/piwigo/admin/deep/popuphelp.php';

        expect(new CookieService()->cookiePath())->toBe('/piwigo/');
    });
});

test('cookiePath throws when the container returns an unexpected type for RequestMountDepth', function (): void {
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';

    KernelContainerOverride::withWrongTypeFor(
        RequestMountDepth::class,
        static fn () => new CookieService()->cookiePath()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . RequestMountDepth::class);

test('cookiePath requires both the redirect-vs-path-info mismatch and the suffix match, not just one', function (): void {
    // The 3-clause `and` chain (path_info !== '', REDIRECT_URL !== PATH_INFO,
    // str_ends_with(redirect_url, path_info)) collapses to the *same* final
    // cookiePath() result whenever path_info is '' (a 0-length substr() is a
    // no-op) -- so REDIRECT_URL === PATH_INFO (an identical, non-empty
    // string) is the only input shape where the middle clause's truth value
    // actually changes the outcome: str_ends_with(X, X) is trivially true,
    // so with REDIRECT_URL === PATH_INFO the first and third clauses are
    // already true and only the (real, `!==`) middle clause keeps the whole
    // condition false. A version with `or` instead of `and` anywhere in the
    // chain, or with the `?? null` on the right side of `!==` dropped
    // (making that side unconditionally null, which a set REDIRECT_URL can
    // never equal), makes the condition wrongly true here.
    $_SERVER['REDIRECT_URL'] = '/foo/bar';
    $_SERVER['PATH_INFO'] = '/foo/bar';

    expect(new CookieService()->cookiePath())->toBe('/foo/');
});

test('setCookieVar casts a scalar non-string value to a string before handing it to setcookie()', function (): void {
    // is_scalar($value) is true for int/float/bool too, not just string --
    // the (string) cast must actually run, not just be gated behind
    // is_scalar(), otherwise a raw int would reach setcookie()'s own
    // `string $value` parameter, and this file's strict_types=1 makes that
    // call-site type-strict even for a built-in function: an unconverted
    // int would throw a TypeError instead of silently coercing.
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';
    $service = new CookieService();

    $result = $service->setCookieVar('intpref', 42);

    expect($result)->toBeTrue();
    expect($service->getCookieVar('intpref'))->toBe(42);
});

test('cookiePath normalizes back to the real app root when the entry file is one directory deeper', function (): void {
    // admin/popuphelp.php's own shape: SCRIPT_NAME resolves the containing
    // directory to '/piwigo/admin/', and RequestMountDepth (set by that
    // entry file) says it's one level deeper than the app's real root --
    // the '../' it appends must normalize back to '/piwigo/', not stay as
    // '/piwigo/admin/../'.
    //
    // This exercises the while loop's own "converged, $new === $scr" break
    // (cookiePath()'s normalization loop). Its sibling "$new === null"
    // break is not chased by any test here: the pattern
    // ('#[^/]+/\.\.(/|$)#') is a fixed string literal, and preg_replace()
    // only ever returns null for a pattern compile error -- there is no
    // real-world SCRIPT_NAME/mount-depth input that changes the pattern
    // itself, so that branch is provably unreachable (the source's own
    // inline comment right above `if ($new === null)` already documents
    // the same conclusion).
    cookieServiceTestWithMountDepth(1, function (): void {
        $_SERVER['SCRIPT_NAME'] = '/piwigo/admin/popuphelp.php';

        expect(new CookieService()->cookiePath())->toBe('/piwigo/');
    });
});
