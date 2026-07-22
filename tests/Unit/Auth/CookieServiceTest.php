<?php

declare(strict_types=1);

use Piwigo\Auth\CookieService;
use Piwigo\Core\RequestMountDepth;

beforeEach(function (): void {
    unset($_SERVER['REDIRECT_SCRIPT_NAME'], $_SERVER['REDIRECT_URL'], $_SERVER['PATH_INFO']);
    RequestMountDepth::reset();
});

afterEach(function (): void {
    RequestMountDepth::reset();
});

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

test('setCookieVar then getCookieVar round-trips through $_COOKIE', function (): void {
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';
    $service = new CookieService();

    $service->setCookieVar('rememberme', 'yes');

    expect($_COOKIE['pwg_rememberme'] ?? null)->toBe('yes')
        ->and($service->getCookieVar('rememberme'))->toBe('yes');
});

test('setCookieVar with null value clears the cookie', function (): void {
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';
    $_COOKIE['pwg_rememberme'] = 'yes';
    $service = new CookieService();

    $service->setCookieVar('rememberme', null);

    expect($service->getCookieVar('rememberme', 'gone'))->toBe('gone');
});

test('getCookieVar returns the default when unset', function (): void {
    unset($_COOKIE['pwg_missing']);

    expect(new CookieService()->getCookieVar('missing', 'fallback'))->toBe('fallback');
});

test('cookiePath normalizes back to the real app root when the entry file is one directory deeper', function (): void {
    // admin/popuphelp.php's own shape: SCRIPT_NAME resolves the containing
    // directory to '/piwigo/admin/', and RequestMountDepth (set by that
    // entry file) says it's one level deeper than the app's real root --
    // the '../' it appends must normalize back to '/piwigo/', not stay as
    // '/piwigo/admin/../'.
    $_SERVER['SCRIPT_NAME'] = '/piwigo/admin/popuphelp.php';
    RequestMountDepth::set(1);

    expect(new CookieService()->cookiePath())->toBe('/piwigo/');
});
