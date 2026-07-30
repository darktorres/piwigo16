<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Url;

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Html\HtmlService;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Url\RootPathOverride;
use Piwigo\Url\UrlService;
use RuntimeException;

/**
 * Records the message passed to whichever of badRequest()/pageNotFound()/
 * fatalError() fired, then throws -- lets parseSectionUrl()/
 * parseWellKnownParamsUrl() tests below assert on the exact message
 * without needing a real Template/Lang/DB stack the concrete HtmlService
 * would otherwise reach for via RedirectService::redirectHtml().
 */
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
    CurrentConfig::setUrlPort('none');
    RootPathOverride::reset();
    SectionContextRegistry::reset();
    RequestMountDepth::reset();
});

afterEach(function (): void {
    CurrentConfig::reset();
    DeploymentPolicy::reset();
    RootPathOverride::reset();
    SectionContextRegistry::reset();
    RequestMountDepth::reset();
});

test('getActionUrl builds action.php with id/part, adding a bare download flag when requested', function (): void {
    // addUrlParams()'s own default separator is the HTML-safe '&amp;'
    // (outside a WS request context) -- see that method's own docblock
    // example.
    $service = new UrlService(new HtmlService());

    expect($service->getActionUrl(42, 'e', false))->toBe('action.php?id=42&amp;part=e');
    expect($service->getActionUrl(42, 'e', true))->toBe('action.php?id=42&amp;part=e&amp;download');
});

test('getGalleryHomeUrl returns a remote gallery_url unchanged', function (): void {
    CurrentConfig::setGalleryUrl('https://elsewhere.example.test/gallery/');
    $service = new UrlService(new HtmlService());

    expect($service->getGalleryHomeUrl())->toBe('https://elsewhere.example.test/gallery/');
});

test('getGalleryHomeUrl prefixes a relative gallery_url with the root URL', function (): void {
    CurrentConfig::setGalleryUrl('my-gallery/');
    $service = new UrlService(new HtmlService());

    expect($service->getGalleryHomeUrl())->toBe('my-gallery/');
});

test('getGalleryHomeUrl falls back to makeIndexUrl when gallery_url is unset', function (): void {
    CurrentConfig::setGalleryUrl(null);
    $service = new UrlService(new HtmlService());

    expect($service->getGalleryHomeUrl())->toBe($service->makeIndexUrl());
});

test('getRootUrl returns an empty string at the app\'s real root (no mount depth, no override)', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->getRootUrl())->toBe('');
});

test('getRootUrl returns a ../ prefix per RequestMountDepth level when no override is active', function (): void {
    RequestMountDepth::set(1);
    $service = new UrlService(new HtmlService());

    expect($service->getRootUrl())->toBe('../');
});

test('getRootUrl prefers RootPathOverride over RequestMountDepth', function (): void {
    RequestMountDepth::set(1);
    RootPathOverride::push('/gallery/');
    $service = new UrlService(new HtmlService());

    try {
        expect($service->getRootUrl())->toBe('/gallery/');
    } finally {
        RootPathOverride::pop();
    }
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
    CurrentConfig::setUrlPort('none');
    CurrentConfig::setGalleryUrl('https://canonical.example.test/gallery/');
    $_SERVER['HTTP_HOST'] = 'evil.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://canonical.example.test/piwigo/');
});

test('getAbsoluteRootUrl keeps gallery_url\'s configured port', function (): void {
    CurrentConfig::setUrlPort('none');
    CurrentConfig::setGalleryUrl('https://canonical.example.test:8080/gallery/');
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://canonical.example.test:8080/piwigo/');
});

test('getAbsoluteRootUrl accepts a Host that matches the allowed_hosts list', function (): void {
    CurrentConfig::setUrlPort('none');
    DeploymentPolicy::set(new DeploymentPolicy(allowedHosts: ['gallery.example.test']));
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl [SEC-29] falls back to the first allowed host when Host is forged', function (): void {
    CurrentConfig::setUrlPort('none');
    DeploymentPolicy::set(new DeploymentPolicy(allowedHosts: ['gallery.example.test', 'gallery-alt.example.test']));
    $_SERVER['HTTP_HOST'] = 'evil.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl [SEC-29] falls back for a forged X-Forwarded-Host too', function (): void {
    CurrentConfig::setUrlPort('none');
    DeploymentPolicy::set(new DeploymentPolicy(allowedHosts: ['gallery.example.test']));
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl [SEC-29] reflects a real DB-persisted gallery_url the way load_conf_from_db() would set it', function (): void {
    // Regression test for the historical CurrentConfig::-accessor bug this file's
    // own history records (see EphemeralKeyService's docblock for the
    // mechanism and fix): a real DB-persisted gallery_url, simulated here
    // via CurrentConfig::setGalleryUrl() the same way
    // ConfigService::loadConfFromDb() now populates CurrentConfig's own
    // properties, must be reflected by getAbsoluteRootUrl().
    CurrentConfig::setUrlPort('none');
    CurrentConfig::setGalleryUrl('https://real-admin-configured.example.test/');
    $_SERVER['HTTP_HOST'] = 'evil.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://real-admin-configured.example.test/piwigo/');
});

test('getAbsoluteRootUrl treats X-Forwarded-Proto=https as HTTPS', function (): void {
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('https://gallery.example.test/piwigo/');
    // The real side effect the guard itself performs, not just its result.
    expect($_SERVER['HTTPS'])->toBe('on');
});

test('getAbsoluteRootUrl detects HTTPS from $_SERVER[\'HTTPS\']=on', function (): void {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('https://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl detects HTTPS from $_SERVER[\'HTTPS\']=1', function (): void {
    $_SERVER['HTTPS'] = '1';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('https://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl appends a non-standard auto-detected port', function (): void {
    CurrentConfig::setUrlPort('auto');
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $_SERVER['SERVER_PORT'] = '8080';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://gallery.example.test:8080/piwigo/');
});

test('getAbsoluteRootUrl omits the standard auto-detected port 80 for http', function (): void {
    CurrentConfig::setUrlPort('auto');
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $_SERVER['SERVER_PORT'] = '80';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl appends an explicitly configured custom port', function (): void {
    CurrentConfig::setUrlPort('9000');
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://gallery.example.test:9000/piwigo/');
});

test('getAbsoluteRootUrl falls back to the Host header when gallery_url has no parseable host', function (): void {
    CurrentConfig::setUrlPort('none');
    CurrentConfig::setGalleryUrl('not-a-real-url-at-all');
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = new UrlService(new HtmlService());

    expect($service->getAbsoluteRootUrl())->toBe('http://gallery.example.test/piwigo/');
});

test('paramsForDuplication includes root_path when setMakeFullUrl\'s override is active', function (): void {
    RootPathOverride::push('/custom-root/');

    try {
        $service = new UrlService(new HtmlService());

        $params = $service->paramsForDuplication([], []);

        expect($params['root_path'])->toBe('/custom-root/');
    } finally {
        RootPathOverride::pop();
    }
});

test('makePictureUrl uses the id-file style, appending a slugified filename', function (): void {
    // Both default to true (config_default.inc.php's own real production
    // values), which would otherwise prefix every assertion below with
    // 'picture.php?' -- disabled here to isolate the picture_url_style
    // switch itself, already covered separately for makeIndexUrl-adjacent
    // behavior elsewhere.
    CurrentConfig::setPhpExtensionInUrls(false);
    CurrentConfig::setQuestionMarkInUrls(false);
    CurrentConfig::setPictureUrlStyle('id-file');
    $service = new UrlService(new HtmlService());

    $url = $service->makePictureUrl(['image_id' => 42, 'image_file' => 'Summer Trip.jpg']);

    // getRootUrl() is '' here (no mount depth, no override, same baseline
    // as the "getRootUrl returns an empty string" test above) -- no
    // leading slash.
    expect($url)->toBe('picture/42-summer_trip');
});

test('makePictureUrl uses the file style directly when the filename does not start with a digit', function (): void {
    CurrentConfig::setPhpExtensionInUrls(false);
    CurrentConfig::setQuestionMarkInUrls(false);
    CurrentConfig::setPictureUrlStyle('file');
    $service = new UrlService(new HtmlService());

    $url = $service->makePictureUrl(['image_id' => 42, 'image_file' => 'sunset.jpg']);

    expect($url)->toBe('picture/sunset');
});

test('makePictureUrl falls through the file style to the bare id when the filename starts with digits', function (): void {
    CurrentConfig::setPhpExtensionInUrls(false);
    CurrentConfig::setQuestionMarkInUrls(false);
    CurrentConfig::setPictureUrlStyle('file');
    $service = new UrlService(new HtmlService());

    // '42-something.jpg' matches /^\d+(-|$)/ -- falls through (no break) to
    // the default arm, using the bare image_id instead of the filename.
    $url = $service->makePictureUrl(['image_id' => 42, 'image_file' => '42-something.jpg']);

    expect($url)->toBe('picture/42');
});

test('makeSectionInUrl defaults a non-array category param to an empty array', function (): void {
    $service = new UrlService(new HtmlService());

    set_error_handler(static fn (): bool => true);
    try {
        $result = $service->makeSectionInUrl(['section' => 'categories', 'category' => 'not-an-array']);
    } finally {
        restore_error_handler();
    }

    expect($result)->toBe('/category/');
});

test('makeSectionInUrl uses the category permalink directly when set', function (): void {
    $service = new UrlService(new HtmlService());

    $result = $service->makeSectionInUrl(['section' => 'categories', 'category' => ['id' => 7, 'name' => 'Vacation', 'permalink' => 'my-vacation']]);

    expect($result)->toBe('/category/my-vacation');
});

test('makeSectionInUrl appends the slugified name in id-name style', function (): void {
    CurrentConfig::setCategoryUrlStyle('id-name');
    $service = new UrlService(new HtmlService());

    $result = $service->makeSectionInUrl(['section' => 'categories', 'category' => ['id' => 7, 'name' => 'Vacation Photos', 'permalink' => null]]);

    expect($result)->toBe('/category/7-vacation_photos');
});

test('makeSectionInUrl appends combined categories, defaulting a non-array entry gracefully', function (): void {
    CurrentConfig::setCategoryUrlStyle('id-name');
    $service = new UrlService(new HtmlService());

    $result = $service->makeSectionInUrl([
        'section' => 'categories',
        'category' => ['id' => 7, 'name' => 'Main', 'permalink' => null],
        'combined_categories' => [
            'not-an-array',
            ['id' => 9, 'name' => 'Second', 'permalink' => null],
            ['id' => 11, 'name' => '', 'permalink' => 'third-perma'],
        ],
    ]);

    // The 'not-an-array' entry resets to [] (no id/name/permalink), so it
    // still contributes its own '/' + '' (id) + '-' + '' (name) segment.
    expect($result)->toBe('/category/7-main/-/9-second/third-perma');
});

test('makeSectionInUrl builds a tags section in the "id" style', function (): void {
    CurrentConfig::setTagUrlStyle('id');
    $service = new UrlService(new HtmlService());

    $result = $service->makeSectionInUrl(['section' => 'tags', 'tags' => [['id' => 3, 'url_name' => 'nature'], ['id' => 5, 'url_name' => 'travel']]]);

    expect($result)->toBe('/tags/3/5');
});

test('makeSectionInUrl builds a tags section in the "tag" style using url_name', function (): void {
    CurrentConfig::setTagUrlStyle('tag');
    $service = new UrlService(new HtmlService());

    $result = $service->makeSectionInUrl(['section' => 'tags', 'tags' => [['id' => 3, 'url_name' => 'nature']]]);

    expect($result)->toBe('/tags/nature');
});

test('makeSectionInUrl falls through the "tag" style to id-name when url_name is absent', function (): void {
    CurrentConfig::setTagUrlStyle('tag');
    $service = new UrlService(new HtmlService());

    // No url_name -- falls through (no break) to the default arm.
    $result = $service->makeSectionInUrl(['section' => 'tags', 'tags' => [['id' => 3]]]);

    expect($result)->toBe('/tags/3');
});

test('makeSectionInUrl builds a tags section in the default id-name style', function (): void {
    CurrentConfig::setTagUrlStyle('id-tag');
    $service = new UrlService(new HtmlService());

    $result = $service->makeSectionInUrl(['section' => 'tags', 'tags' => [['id' => 3, 'url_name' => 'nature']]]);

    expect($result)->toBe('/tags/3-nature');
});

test('makeSectionInUrl builds a search section', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->makeSectionInUrl(['section' => 'search', 'search' => 'psk-20260101-abcdefghij']))
        ->toBe('/search/psk-20260101-abcdefghij');
});

test('makeSectionInUrl builds a list section from scalar ids only', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->makeSectionInUrl(['section' => 'list', 'list' => [12, 34, 'not-scalar' => ['x']]]))
        ->toBe('/list/12,34');
});

test('parseSectionUrl recognizes the favorites/most_visited/best_rated/recent_pics/recent_cats tokens', function (): void {
    $service = new UrlService(new HtmlService());
    $redirect = new UrlServiceTestRedirectService();

    foreach (['favorites', 'most_visited', 'best_rated', 'recent_pics', 'recent_cats'] as $token) {
        $i = 0;
        $page = $service->parseSectionUrl([$token], $i, $redirect);

        expect($page['section'])->toBe($token)
            ->and($i)->toBe(1);
    }
});

test('parseSectionUrl parses a valid psk-formatted search token', function (): void {
    $service = new UrlService(new HtmlService());
    $i = 0;

    $page = $service->parseSectionUrl(['search', 'psk-20260101-abcdefghij'], $i, new UrlServiceTestRedirectService());

    expect($page['section'])->toBe('search')
        ->and($page['search'])->toBe('psk-20260101-abcdefghij')
        ->and($i)->toBe(2);
});

test('parseSectionUrl falls back to a plain numeric search identifier', function (): void {
    $service = new UrlService(new HtmlService());
    $i = 0;

    $page = $service->parseSectionUrl(['search', '42'], $i, new UrlServiceTestRedirectService());

    expect($page['search'])->toBe('42');
});

test('parseSectionUrl rejects a search token with no usable identifier', function (): void {
    $service = new UrlService(new UrlServiceTestHtmlRenderer());
    $i = 0;

    expect(fn () => $service->parseSectionUrl(['search', 'no-digits-here'], $i, new UrlServiceTestRedirectService()))
        ->toThrow(RuntimeException::class, 'badRequest: search identifier is missing');
});

test('parseSectionUrl defaults an empty list token to the dummy [-1] element', function (): void {
    $service = new UrlService(new HtmlService());
    $i = 0;

    $page = $service->parseSectionUrl(['list', ''], $i, new UrlServiceTestRedirectService());

    expect($page['section'])->toBe('list')
        ->and($page['list'])->toBe([-1]);
});

test('parseSectionUrl parses a comma-separated list of image ids', function (): void {
    $service = new UrlService(new HtmlService());
    $i = 0;

    $page = $service->parseSectionUrl(['list', '12,34,56'], $i, new UrlServiceTestRedirectService());

    expect($page['list'])->toBe(['12', '34', '56']);
});

test('parseSectionUrl rejects a malformed list token', function (): void {
    $htmlRenderer = new UrlServiceTestHtmlRenderer();
    $service = new UrlService($htmlRenderer);
    $i = 0;

    expect(fn () => $service->parseSectionUrl(['list', 'not-a-list'], $i, new UrlServiceTestRedirectService()))
        ->toThrow(RuntimeException::class, 'badRequest: wrong format on list GET parameter');
});

test('parseWellKnownParamsUrl parses a chronology token with an explicit calendar view', function (): void {
    $service = new UrlService(new HtmlService());
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['created-monthly-calendar-2026-07'], $i);

    expect($result)->toBe([
        'chronology_field' => 'created',
        'chronology_style' => 'monthly',
        'chronology_view' => 'calendar',
        'chronology_date' => ['2026', '07'],
    ]);
});

test('parseWellKnownParamsUrl parses a startcat token', function (): void {
    $service = new UrlService(new HtmlService());
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['startcat-15'], $i);

    expect($result)->toBe(['startcat' => '15']);
});

test('parseWellKnownParamsUrl rejects an unrecognized chronology style', function (): void {
    $htmlRenderer = new UrlServiceTestHtmlRenderer();
    $service = new UrlService($htmlRenderer);
    $i = 0;

    expect(fn () => $service->parseWellKnownParamsUrl(['created-bogus'], $i))
        ->toThrow(RuntimeException::class, 'fatalError: bad chronology field (style)');
});

test('parseWellKnownParamsUrl rejects a non-numeric chronology date token', function (): void {
    $htmlRenderer = new UrlServiceTestHtmlRenderer();
    $service = new UrlService($htmlRenderer);
    $i = 0;

    expect(fn () => $service->parseWellKnownParamsUrl(['created-monthly-not-a-number'], $i))
        ->toThrow(RuntimeException::class, 'fatalError: bad chronology field (date)');
});

test('getElementUrl embellishes a non-remote path with the root URL', function (): void {
    RequestMountDepth::set(1);
    $service = new UrlService(new HtmlService());

    expect($service->getElementUrl(['path' => 'galleries/2026/photo.jpg']))
        ->toBe('../galleries/2026/photo.jpg');
});

test('getElementUrl leaves a remote path unchanged', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->getElementUrl(['path' => 'https://cdn.example.test/photo.jpg']))
        ->toBe('https://cdn.example.test/photo.jpg');
});

test('getElementUrl coerces a non-string path to its string form', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->getElementUrl(['path' => 123]))->toBe('123');
});

test('embellishUrl leaves a /../ segment unresolved when there is no preceding segment to collapse against', function (): void {
    $service = new UrlService(new HtmlService());

    expect($service->embellishUrl('a/../b'))->toBe('a/../b');
});

test('getUserFavorites returns an empty array for a guest', function (): void {
    \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray(['id' => 2, 'status' => 'guest']));

    try {
        $service = new UrlService(new HtmlService());

        expect($service->getUserFavorites())->toBe([]);
    } finally {
        \Piwigo\Users\CurrentUser::reset();
    }
});
