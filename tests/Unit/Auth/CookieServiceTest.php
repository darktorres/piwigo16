<?php

declare(strict_types=1);

use Piwigo\Auth\CookieService;

if (! defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', './');
}

beforeEach(function (): void {
    unset($_SERVER['REDIRECT_SCRIPT_NAME'], $_SERVER['REDIRECT_URL'], $_SERVER['PATH_INFO']);
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
