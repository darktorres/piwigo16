<?php

declare(strict_types=1);

use Piwigo\Url\UrlService;

if (! defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', './');
}

beforeEach(function (): void {
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_HOST']);
});

test('urlIsRemote is true for http and https URLs', function (): void {
    $service = new UrlService();

    expect($service->urlIsRemote('http://example.test/x'))->toBeTrue()
        ->and($service->urlIsRemote('https://example.test/x'))->toBeTrue();
});

test('urlIsRemote is false for a relative path', function (): void {
    $service = new UrlService();

    expect($service->urlIsRemote('/gallery/category/1'))->toBeFalse()
        ->and($service->urlIsRemote('category/1'))->toBeFalse();
});

test('embellishUrl collapses /./ segments', function (): void {
    $service = new UrlService();

    expect($service->embellishUrl('/a/./b/./c'))->toBe('/a/b/c');
});

test('embellishUrl resolves /../ segments', function (): void {
    $service = new UrlService();

    expect($service->embellishUrl('/a/b/../c'))->toBe('/a/c');
});

test('addUrlParams appends a query string to a URL with none', function (): void {
    $service = new UrlService();

    expect($service->addUrlParams('/x', ['a' => 'b']))->toBe('/x?a=b');
});

test('addUrlParams appends with the given separator to a URL that already has a query string', function (): void {
    $service = new UrlService();

    expect($service->addUrlParams('/x?cat_id=10', ['a' => 'b']))->toBe('/x?cat_id=10&amp;a=b');
});

test('addUrlParams returns the URL unchanged for empty params', function (): void {
    $service = new UrlService();

    expect($service->addUrlParams('/x', []))->toBe('/x');
});

test('addUrlParams omits the value for a null param', function (): void {
    $service = new UrlService();

    expect($service->addUrlParams('/x', ['download' => null]))->toBe('/x?download');
});

test('getQueryStringDiff returns empty string when QUERY_STRING is unset', function (): void {
    unset($_SERVER['QUERY_STRING']);
    $service = new UrlService();

    expect($service->getQueryStringDiff())->toBe('');
});

test('getQueryStringDiff removes rejected keys and keeps the rest', function (): void {
    $_SERVER['QUERY_STRING'] = 'a=1&b=2&c=3';
    $service = new UrlService();

    expect($service->getQueryStringDiff(['b']))->toBe('?a=1&amp;c=3');
});

test('getQueryStringDiff can use a plain ampersand instead of the escaped form', function (): void {
    $_SERVER['QUERY_STRING'] = 'a=1&b=2';
    $service = new UrlService();

    expect($service->getQueryStringDiff([], false))->toBe('?a=1&b=2');
});

test('makeSectionInUrl returns /categories when no category param is set', function (): void {
    $service = new UrlService();

    expect($service->makeSectionInUrl(['section' => 'categories']))->toBe('/categories');
});

test('makeSectionInUrl returns an empty string for the none section', function (): void {
    $service = new UrlService();

    expect($service->makeSectionInUrl(['section' => 'none']))->toBe('');
});

test('makeSectionInUrl falls through to a bare /section for an unrecognized section name', function (): void {
    $service = new UrlService();

    expect($service->makeSectionInUrl(['section' => 'favorites']))->toBe('/favorites');
});

test('addWellKnownParamsInUrl appends /flat when the flat param is set', function (): void {
    $service = new UrlService();

    expect($service->addWellKnownParamsInUrl('/x', ['flat' => true]))->toBe('/x/flat');
});

test('addWellKnownParamsInUrl appends /start-N when start is greater than zero', function (): void {
    $service = new UrlService();

    expect($service->addWellKnownParamsInUrl('/x', ['start' => 20]))->toBe('/x/start-20');
});

test('addWellKnownParamsInUrl ignores a zero start', function (): void {
    $service = new UrlService();

    expect($service->addWellKnownParamsInUrl('/x', ['start' => 0]))->toBe('/x');
});

test('parseWellKnownParamsUrl parses flat and start tokens', function (): void {
    $service = new UrlService();
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['flat', 'start-40'], $i);

    expect($result)->toBe(['flat' => true, 'start' => '40'])
        ->and($i)->toBe(2);
});

test('parseWellKnownParamsUrl parses a chronology token', function (): void {
    $service = new UrlService();
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['created-monthly-2026-07'], $i);

    expect($result)->toBe([
        'chronology_field' => 'created',
        'chronology_style' => 'monthly',
        'chronology_date' => ['2026', '07'],
    ]);
});
