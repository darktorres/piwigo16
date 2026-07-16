<?php

declare(strict_types=1);

use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

if (! defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', './');
}

beforeEach(function (): void {
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_HOST'], $_SERVER['HTTP_HOST']);
    // getAbsoluteRootUrl() calls the real Piwigo\Auth\CookieService::cookiePath()
    // (P23 batch 8c retargeted the former unqualified cookie_path() free
    // function call) -- deterministically produce '/piwigo/' the same way
    // cookiePath()'s own SCRIPT_NAME fallback would under a real request
    // rooted at /piwigo/, rather than depending on whatever the Pest CLI
    // runner's ambient $_SERVER happens to contain.
    unset($_SERVER['REDIRECT_SCRIPT_NAME'], $_SERVER['REDIRECT_URL'], $_SERVER['PATH_INFO']);
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';
    // getAbsoluteRootUrl()'s gallery_url/allowed_hosts reads go through the
    // live $conf global, not Piwigo\Config\Config -- see UrlService's own
    // configuredHost()/trustedHost() doc comments for why (Config::$data is
    // never synced with the real, admin-configurable DB-persisted config
    // table during a live request).
    $GLOBALS['conf'] = ['url_port' => 'none'];
});

test('urlIsRemote is true for http and https URLs', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->urlIsRemote('http://example.test/x'))->toBeTrue()
        ->and($service->urlIsRemote('https://example.test/x'))->toBeTrue();
});

test('urlIsRemote is false for a relative path', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->urlIsRemote('/gallery/category/1'))->toBeFalse()
        ->and($service->urlIsRemote('category/1'))->toBeFalse();
});

test('embellishUrl collapses /./ segments', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->embellishUrl('/a/./b/./c'))->toBe('/a/b/c');
});

test('embellishUrl resolves /../ segments', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->embellishUrl('/a/b/../c'))->toBe('/a/c');
});

test('addUrlParams appends a query string to a URL with none', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->addUrlParams('/x', ['a' => 'b']))->toBe('/x?a=b');
});

test('addUrlParams appends with the given separator to a URL that already has a query string', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->addUrlParams('/x?cat_id=10', ['a' => 'b']))->toBe('/x?cat_id=10&amp;a=b');
});

test('addUrlParams returns the URL unchanged for empty params', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->addUrlParams('/x', []))->toBe('/x');
});

test('addUrlParams omits the value for a null param', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->addUrlParams('/x', ['download' => null]))->toBe('/x?download');
});

test('getQueryStringDiff returns empty string when QUERY_STRING is unset', function (): void {
    unset($_SERVER['QUERY_STRING']);
    $service = new UrlService(new HtmlService());

    expect($service->getQueryStringDiff())->toBe('');
});

test('getQueryStringDiff removes rejected keys and keeps the rest', function (): void {
    $_SERVER['QUERY_STRING'] = 'a=1&b=2&c=3';
    $service = new UrlService(new HtmlService());

    expect($service->getQueryStringDiff(['b']))->toBe('?a=1&amp;c=3');
});

test('getQueryStringDiff can use a plain ampersand instead of the escaped form', function (): void {
    $_SERVER['QUERY_STRING'] = 'a=1&b=2';
    $service = new UrlService(new HtmlService());

    expect($service->getQueryStringDiff([], false))->toBe('?a=1&b=2');
});

test('makeSectionInUrl returns /categories when no category param is set', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->makeSectionInUrl(['section' => 'categories']))->toBe('/categories');
});

test('makeSectionInUrl returns an empty string for the none section', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->makeSectionInUrl(['section' => 'none']))->toBe('');
});

test('makeSectionInUrl falls through to a bare /section for an unrecognized section name', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->makeSectionInUrl(['section' => 'favorites']))->toBe('/favorites');
});

test('addWellKnownParamsInUrl appends /flat when the flat param is set', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->addWellKnownParamsInUrl('/x', ['flat' => true]))->toBe('/x/flat');
});

test('addWellKnownParamsInUrl appends /start-N when start is greater than zero', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->addWellKnownParamsInUrl('/x', ['start' => 20]))->toBe('/x/start-20');
});

test('addWellKnownParamsInUrl ignores a zero start', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->addWellKnownParamsInUrl('/x', ['start' => 0]))->toBe('/x');
});

test('parseWellKnownParamsUrl parses flat and start tokens', function (): void {
    $service = new UrlService(new HtmlService());
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['flat', 'start-40'], $i);

    expect($result)->toBe(['flat' => true, 'start' => '40'])
        ->and($i)->toBe(2);
});

test('parseWellKnownParamsUrl parses a chronology token', function (): void {
    $service = new UrlService(new HtmlService());
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['created-monthly-2026-07'], $i);

    expect($result)->toBe([
        'chronology_field' => 'created',
        'chronology_style' => 'monthly',
        'chronology_date' => ['2026', '07'],
    ]);
});

test('getAbsoluteRootUrl trusts the Host header when allowed_hosts is unconfigured', function (): void {
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl uses gallery_url\'s host, ignoring the Host header entirely', function (): void {
    $GLOBALS['conf'] = ['url_port' => 'none', 'gallery_url' => 'https://canonical.example.test/gallery/'];
    $_SERVER['HTTP_HOST'] = 'evil.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://canonical.example.test/piwigo/');
});

test('getAbsoluteRootUrl keeps gallery_url\'s configured port', function (): void {
    $GLOBALS['conf'] = ['url_port' => 'none', 'gallery_url' => 'https://canonical.example.test:8080/gallery/'];
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://canonical.example.test:8080/piwigo/');
});

test('getAbsoluteRootUrl accepts a Host that matches the allowed_hosts list', function (): void {
    $GLOBALS['conf'] = ['url_port' => 'none', 'allowed_hosts' => ['gallery.example.test']];
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl [SEC-29] falls back to the first allowed host when Host is forged', function (): void {
    $GLOBALS['conf'] = ['url_port' => 'none', 'allowed_hosts' => ['gallery.example.test', 'gallery-alt.example.test']];
    $_SERVER['HTTP_HOST'] = 'evil.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl [SEC-29] falls back for a forged X-Forwarded-Host too', function (): void {
    $GLOBALS['conf'] = ['url_port' => 'none', 'allowed_hosts' => ['gallery.example.test']];
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl [SEC-29] reflects a real DB-persisted gallery_url the way load_conf_from_db() would set it', function (): void {
    // Regression test for the Config::-accessor bug this file's own history
    // records: MailService's build caught that Piwigo\Config\Config's typed
    // accessors are never synced with the real config DB table on a live
    // request (see load_conf_from_db(), include/functions.inc.php) -- only
    // $conf is. Simulates exactly that: a raw array write to $conf, the
    // same shape load_conf_from_db() itself produces, with no Config::
    // involvement at all.
    $GLOBALS['conf'] = ['url_port' => 'none', 'gallery_url' => 'https://real-admin-configured.example.test/'];
    $_SERVER['HTTP_HOST'] = 'evil.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://real-admin-configured.example.test/piwigo/');
});
