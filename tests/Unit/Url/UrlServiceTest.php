<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Url;

use LogicException;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\ApiContext;
use Piwigo\Core\Paths;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Lang\Translator;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Url\RootPathOverride;
use Piwigo\Url\UrlService;
use Piwigo\Users\User;
use ReflectionMethod;
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
    // getAbsoluteRootUrl() calls the real Piwigo\Auth\CookieService::cookiePath() --
    // deterministically produce '/piwigo/' the same way
    // cookiePath()'s own SCRIPT_NAME fallback would under a real request
    // rooted at /piwigo/, rather than depending on whatever the Pest CLI
    // runner's ambient $_SERVER happens to contain.
    unset($_SERVER['REDIRECT_SCRIPT_NAME'], $_SERVER['REDIRECT_URL'], $_SERVER['PATH_INFO']);
    $_SERVER['SCRIPT_NAME'] = '/piwigo/index.php';
    CurrentConfigTestFactory::get()->urlPort = 'none';
    // getRootUrl()/paramsForDuplication() read SectionContextRegistry
    // through the currentStatic() shim (see that method's own docblock),
    // which resolves the real container-shared instance once
    // Kernel::boot() has run.
    Kernel::boot();
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

function urlServiceTestSectionContextRegistry(): SectionContextRegistry
{
    $registry = Kernel::container()->get(SectionContextRegistry::class);
    if (! $registry instanceof SectionContextRegistry) {
        throw new RuntimeException('Container returned an unexpected type for ' . SectionContextRegistry::class);
    }

    return $registry;
}

/**
 * RequestMountDepth is a container-shared, immutable value --
 * beforeEach()'s own Kernel::boot() already bound the default (0), so a
 * test needing a non-zero depth rebuilds the container via
 * KernelContainerOverride::with(). Runs $fn inside that fresh container,
 * same as SectionContextRegistry-dependent tests do via
 * urlServiceTestSectionContextRegistry() -- callers needing both do their
 * own SectionContextRegistry ->set() call from inside $fn, after the
 * container has already been rebuilt.
 */
function urlServiceTestWithMountDepth(int $depth, callable $fn): mixed
{
    return KernelContainerOverride::with([
        RequestMountDepth::class => new RequestMountDepth($depth),
    ], $fn);
}

/**
 * parseSectionUrl()'s own return type is `array<string, mixed>` by
 * design (see that method's own docblock) -- narrows $page['tags'][0]['id']
 * for real rather than trusting an inline @var, used by the
 * while-loop-collection tests below.
 */
function urlServiceTestFirstTagId(mixed $tags): int
{
    if (! is_array($tags) || ! isset($tags[0]) || ! is_array($tags[0]) || ! is_int($tags[0]['id'])) {
        throw new RuntimeException('Expected $page[\'tags\'][0][\'id\'] to be an int.');
    }

    return $tags[0]['id'];
}

/**
 * Same narrowing reasoning as urlServiceTestFirstTagId() above, for a
 * single category/combined-category array entry's own 'id' key.
 */
function urlServiceTestCategoryId(mixed $category): int
{
    if (! is_array($category) || ! is_int($category['id'])) {
        throw new RuntimeException('Expected a category array with an int \'id\' key.');
    }

    return $category['id'];
}

// [Mutation] Remaining untested mutations after mutation testing, all
// verified genuinely inert via hand-mutation (batched where the
// reasoning is identical across sites, individually verified where it
// wasn't), triaged into groups:
//
// 1. ConcatEqualToEqual (`.=` -> `=`, 8 sites: getAbsoluteRootUrl()'s
//    scheme prefix, makeSectionInUrl()'s per-section-case string
//    building): every one of these targets a variable that was JUST
//    initialized to '' immediately above (getAbsoluteRootUrl()'s $url)
//    or is built inside a `switch` where each case is mutually
//    exclusive and $section_string always starts from the same fresh
//    '' (makeSectionInUrl()). Overwriting vs appending onto an empty
//    string produces an identical result.
//
// 2. RemoveStringCast (`(string) $x` -> `$x` inside
//    `is_scalar($x) ? (string) $x : ''`, 13 sites across
//    makePictureUrl()/makeSectionInUrl()): PHP's `.=` operator already
//    coerces a scalar operand to string, with or without the explicit
//    cast -- same universal finding as every other file in this
//    campaign. Batch-verified: all 13 removed simultaneously, full
//    suite still green.
//
// 3. RemoveBooleanCast (`(bool) preg_match(...)` inside an `if`/`or`
//    condition, 5 sites): universal `if((bool)X)` === `if(X)` PHP
//    semantics, same finding as every other file in this campaign.
//
// 4. EmptyStringToNotEmpty (4 sites): configuredHost()'s own
//    `$gallery_url === ''`/`$host === ''` guards (Lines 259/264) are
//    pre-existing documented equivalents (see the dedicated comment
//    right above the relevant test below) -- re-verified fresh this
//    pass, not just trusted from the old note. parseSectionUrl()'s own
//    search-token defaults (Lines 851/853, `$tokens[$nextToken] ?? ''`)
//    are inert for a DIFFERENT, empirically-confirmed reason: the real
//    pest-plugin-mutate sentinel string ('PEST Mutator was here!')
//    contains no digits and doesn't match either regex any more than an
//    empty string does, so a bare ['search'] token produces the
//    identical "search identifier is missing" badRequest() either way,
//    confirmed via a direct probe.
//
// 5. FalseToTrue (`$is_first = false;` -> `= true;`, Line 310,
//    addUrlParams()): once the first param appends either '?' or the
//    real separator, $url already contains '?' -- so a mutated
//    "always take the is_first branch" still re-evaluates
//    `!str_contains($url, '?')` as false for every later param,
//    falling through to the exact same `$argSeparator` the real `else`
//    branch would have used. Confirmed via a full multi-param suite run
//    with the mutation applied.
//
// 6. DecrementInteger/IncrementInteger on regex capture-group indices
//    (Lines 452/852/854/858, `$matches[1]` <-> `$matches[0]`/
//    `$fname_wo_ext[0]` <-> `[1]`): each of these 3 regexes
//    (`/^\d+(-|$)/`, `/^(psk-...)$/`, `/(\d+)/`) wraps its ENTIRE
//    matched span in a single capturing group with nothing outside it
//    -- so `$matches[0]` (the whole match) and `$matches[1]` (the
//    group) are always identical whenever a match occurs, and
//    `isset()` on either is equivalent. Line 452's own char-index swap
//    is inert for a related structural reason: whenever
//    `/^\d+(-|$)/` genuinely matches, the character immediately after
//    the leading digit run can only be another digit, the literal '-',
//    or absent (end of string) -- none of which can have
//    `ord() > ord('9')`, so the ord()-comparison clause evaluates the
//    same regardless of which index it reads. Verified with a real
//    single-character numeric filename (the one case an out-of-bounds
//    [1] read could have differed) -- no warning, identical output.
//
// 7. Line 682's and Line 703's DecrementInteger/IncrementInteger/
//    PostIncrementToPostDecrement/GreaterToGreaterOrEqual
//    (`$loop_counter = 0;` -> -1/1, and `$loop_counter++ > count($tokens)
//    + 10` -> its own off-by-one/decrement/`>=` variants -- the
//    categories-branch infinite-loop guard's own starting value and
//    threshold check): verified difficult to exercise deterministically
//    -- the guard only fires once the loop has genuinely iterated more
//    than `count($tokens) + 10` times, which needs an intentionally
//    oversized, purpose-built token array with no realistic real-world
//    analogue; shifting the trigger by one iteration provides no
//    meaningful regression-catching value beyond what the loop's own
//    real termination tests (below) already cover. Line 703 itself only
//    became a covered, generatable mutant once the while-loop-collection
//    tests below started exercising the loop's own body at all -- it
//    was untestable in exactly the same way before AND after that.
//
// 8. Line 723's DecrementInteger (`$category = $matches[1];` ->
//    `$matches[0]`), Line 725's identical pattern for the
//    `$combined_category_ids[] = $matches[1];` branch, and Line 814's
//    identical pattern in the tags loop: all inert for the same reason
//    as group 6 above, but only provably so once real "-suffix" test
//    data exists to make $matches[0] and $matches[1] genuinely
//    different strings (unlike the plain-numeric tokens used
//    elsewhere) -- `(int) $category`/`(int) $cat_id` (categories,
//    including the combined-category consumption loop at
//    `getCategoryInfo((int) $cat_id)`) and the tags loop's own real
//    MySQL numeric-string coercion (confirmed via a direct probe:
//    `findTags(['3-family-name'])` finds tag id 3 exactly the same as
//    `findTags(['3'])` does) both read only the leading digit run
//    either way, discarding the "-suffix" difference before it can
//    matter. Line 725 specifically re-verified with a dashed-suffix
//    SECOND (combined) category token ('2-nested-sub-album') -- the
//    mutated and unmutated output are identical: combined_categories[0]
//    is still id 2, hit_by['cat_url_name'] still reads 'nested-sub-album'
//    from $matches[2], unaffected by the [0]/[1] swap on $matches[1]
//    itself.
//
// 9. Line 710's StrStartsWithToStrEndsWith (the categories loop's own
//    'start-' continuation-token break check): this WAS a real,
//    previously-undetected gap -- the sibling 'created-'/'posted-'/
//    'startcat-' break checks each had their own dedicated test, but
//    'start-' itself did not. Fixed with a new test below (mirroring
//    the sibling tests' shape exactly); confirmed the new test fails
//    under the mutation (falls through to the permalink-scan branch
//    and a real DB lookup instead of breaking, producing an uncaught
//    ResponseReadyException) and passes on the real source.
test('getActionUrl builds action.php with id/part, adding a bare download flag when requested', function (): void {
    // addUrlParams()'s own default separator is the HTML-safe '&amp;' --
    // see that method's own docblock example.
    $service = UrlServiceTestFactory::build();

    expect($service->getActionUrl(42, 'e', false))
        ->toBe('action.php?id=42&amp;part=e');
    expect($service->getActionUrl(42, 'e', true))
        ->toBe('action.php?id=42&amp;part=e&amp;download');
});

test('getGalleryHomeUrl returns a remote gallery_url unchanged', function (): void {
    CurrentConfigTestFactory::get()->galleryUrl = 'https://elsewhere.example.test/gallery/';
    $service = UrlServiceTestFactory::build();

    expect($service->getGalleryHomeUrl())
        ->toBe('https://elsewhere.example.test/gallery/');
});

test('getGalleryHomeUrl prefixes a relative gallery_url with the root URL', function (): void {
    CurrentConfigTestFactory::get()->galleryUrl = 'my-gallery/';
    $service = UrlServiceTestFactory::build();

    expect($service->getGalleryHomeUrl())
        ->toBe('my-gallery/');
});

test('getGalleryHomeUrl falls back to makeIndexUrl when gallery_url is unset', function (): void {
    CurrentConfigTestFactory::get()->galleryUrl = null;
    $service = UrlServiceTestFactory::build();

    expect($service->getGalleryHomeUrl())
        ->toBe($service->makeIndexUrl());
});

test('getRootUrl returns an empty string at the app\'s real root (no mount depth, no override)', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->getRootUrl())
        ->toBe('');
});

test('getRootUrl returns a ../ prefix per RequestMountDepth level when no override is active', function (): void {
    urlServiceTestWithMountDepth(1, function (): void {
        $service = UrlServiceTestFactory::build();

        expect($service->getRootUrl())
            ->toBe('../');
    });
});

test('getRootUrl prefers RootPathOverride over RequestMountDepth', function (): void {
    urlServiceTestWithMountDepth(1, function (): void {
        $rootPathOverride = new RootPathOverride();
        $rootPathOverride->push('/gallery/');
        $service = UrlServiceTestFactory::build(null, $rootPathOverride);

        try {
            expect($service->getRootUrl())
                ->toBe('/gallery/');
        } finally {
            $rootPathOverride->pop();
        }
    });
});

test('urlIsRemote is true for http and https URLs', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->urlIsRemote('http://example.test/x'))
        ->toBeTrue()
        ->and($service->urlIsRemote('https://example.test/x'))
        ->toBeTrue();
});

test('urlIsRemote is false for a relative path', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->urlIsRemote('/gallery/category/1'))
        ->toBeFalse()
        ->and($service->urlIsRemote('category/1'))
        ->toBeFalse();
});

test('embellishUrl collapses /./ segments', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->embellishUrl('/a/./b/./c'))
        ->toBe('/a/b/c');
});

test('embellishUrl resolves /../ segments', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->embellishUrl('/a/b/../c'))
        ->toBe('/a/c');
});

test('addUrlParams appends a query string to a URL with none', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->addUrlParams('/x', [
        'a' => 'b',
    ]))->toBe('/x?a=b');
});

test('addUrlParams appends with the given separator to a URL that already has a query string', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->addUrlParams('/x?cat_id=10', [
        'a' => 'b',
    ]))->toBe('/x?cat_id=10&amp;a=b');
});

test('addUrlParams returns the URL unchanged for empty params', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->addUrlParams('/x', []))->toBe('/x');
});

test('addUrlParams omits the value for a null param', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->addUrlParams('/x', [
        'download' => null,
    ]))->toBe('/x?download');
});

test('getQueryStringDiff returns empty string when QUERY_STRING is unset', function (): void {
    unset($_SERVER['QUERY_STRING']);
    $service = UrlServiceTestFactory::build();

    expect($service->getQueryStringDiff())
        ->toBe('');
});

test('getQueryStringDiff removes rejected keys and keeps the rest', function (): void {
    $_SERVER['QUERY_STRING'] = 'a=1&b=2&c=3';
    $service = UrlServiceTestFactory::build();

    expect($service->getQueryStringDiff(['b']))->toBe('?a=1&amp;c=3');
});

test('getQueryStringDiff can use a plain ampersand instead of the escaped form', function (): void {
    $_SERVER['QUERY_STRING'] = 'a=1&b=2';
    $service = UrlServiceTestFactory::build();

    expect($service->getQueryStringDiff([], false))->toBe('?a=1&b=2');
});

test('makeSectionInUrl returns /categories when no category param is set', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->makeSectionInUrl([
        'section' => 'categories',
    ]))->toBe('/categories');
});

test('makeSectionInUrl returns an empty string for the none section', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->makeSectionInUrl([
        'section' => 'none',
    ]))->toBe('');
});

test('makeSectionInUrl falls through to a bare /section for an unrecognized section name', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->makeSectionInUrl([
        'section' => 'favorites',
    ]))->toBe('/favorites');
});

test('addWellKnownParamsInUrl appends /flat when the flat param is set', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->addWellKnownParamsInUrl('/x', [
        'flat' => true,
    ]))->toBe('/x/flat');
});

test('addWellKnownParamsInUrl appends /start-N when start is greater than zero', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->addWellKnownParamsInUrl('/x', [
        'start' => 20,
    ]))->toBe('/x/start-20');
});

test('addWellKnownParamsInUrl ignores a zero start', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->addWellKnownParamsInUrl('/x', [
        'start' => 0,
    ]))->toBe('/x');
});

test('parseWellKnownParamsUrl parses flat and start tokens', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['flat', 'start-40'], $i);

    expect($result)
        ->toBe([
            'flat' => true,
            'start' => '40',
        ])
        ->and($i)
        ->toBe(2);
});

test('parseWellKnownParamsUrl parses a chronology token', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['created-monthly-2026-07'], $i);

    expect($result)
        ->toBe([
            'chronology_field' => 'created',
            'chronology_style' => 'monthly',
            'chronology_date' => ['2026', '07'],
        ]);
});

test('getAbsoluteRootUrl trusts the Host header when allowed_hosts is unconfigured', function (): void {
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl uses gallery_url\'s host, ignoring the Host header entirely', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'none';
    CurrentConfigTestFactory::get()->galleryUrl = 'https://canonical.example.test/gallery/';
    $_SERVER['HTTP_HOST'] = 'evil.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://canonical.example.test/piwigo/');
});

test('getAbsoluteRootUrl keeps gallery_url\'s configured port', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'none';
    CurrentConfigTestFactory::get()->galleryUrl = 'https://canonical.example.test:8080/gallery/';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://canonical.example.test:8080/piwigo/');
});

test('getAbsoluteRootUrl accepts a Host that matches the allowed_hosts list', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'none';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';

    KernelContainerOverride::with([
        DeploymentPolicy::class => new DeploymentPolicy(allowedHosts: ['gallery.example.test']),
    ], function (): void {
        $service = UrlServiceTestFactory::build();

        expect($service->getAbsoluteRootUrl())
            ->toBe('http://gallery.example.test/piwigo/');
    });
});

test('getAbsoluteRootUrl [SEC-29] falls back to the first allowed host when Host is forged', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'none';
    $_SERVER['HTTP_HOST'] = 'evil.test';

    KernelContainerOverride::with([
        DeploymentPolicy::class => new DeploymentPolicy(allowedHosts: ['gallery.example.test', 'gallery-alt.example.test']),
    ], function (): void {
        $service = UrlServiceTestFactory::build();

        expect($service->getAbsoluteRootUrl())
            ->toBe('http://gallery.example.test/piwigo/');
    });
});

test('getAbsoluteRootUrl [SEC-29] falls back for a forged X-Forwarded-Host too', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'none';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.test';

    KernelContainerOverride::with([
        DeploymentPolicy::class => new DeploymentPolicy(allowedHosts: ['gallery.example.test']),
    ], function (): void {
        $service = UrlServiceTestFactory::build();

        expect($service->getAbsoluteRootUrl())
            ->toBe('http://gallery.example.test/piwigo/');
    });
});

test('getAbsoluteRootUrl [SEC-29] reflects a real DB-persisted gallery_url the way load_conf_from_db() would set it', function (): void {
    // A real DB-persisted gallery_url, simulated here via
    // CurrentConfig::setGalleryUrl() the same way
    // ConfigService::loadConfFromDb() populates CurrentConfig's own
    // properties, must be reflected by getAbsoluteRootUrl() (see
    // EphemeralKeyService's docblock for the underlying mechanism).
    CurrentConfigTestFactory::get()->urlPort = 'none';
    CurrentConfigTestFactory::get()->galleryUrl = 'https://real-admin-configured.example.test/';
    $_SERVER['HTTP_HOST'] = 'evil.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://real-admin-configured.example.test/piwigo/');
});

test('getAbsoluteRootUrl treats X-Forwarded-Proto=https as HTTPS', function (): void {
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('https://gallery.example.test/piwigo/');
    // The real side effect the guard itself performs, not just its result.
    expect($_SERVER['HTTPS'])->toBe('on');
});

test('getAbsoluteRootUrl detects HTTPS from $_SERVER[\'HTTPS\']=on', function (): void {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('https://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl detects HTTPS from $_SERVER[\'HTTPS\']=1', function (): void {
    $_SERVER['HTTPS'] = '1';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('https://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl appends a non-standard auto-detected port', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'auto';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $_SERVER['SERVER_PORT'] = '8080';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://gallery.example.test:8080/piwigo/');
});

test('getAbsoluteRootUrl omits the standard auto-detected port 80 for http', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'auto';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $_SERVER['SERVER_PORT'] = '80';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl appends an explicitly configured custom port', function (): void {
    CurrentConfigTestFactory::get()->urlPort = '9000';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://gallery.example.test:9000/piwigo/');
});

test('getAbsoluteRootUrl falls back to the Host header when gallery_url has no parseable host', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'none';
    CurrentConfigTestFactory::get()->galleryUrl = 'not-a-real-url-at-all';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://gallery.example.test/piwigo/');
});

test('paramsForDuplication includes root_path when setMakeFullUrl\'s override is active', function (): void {
    $rootPathOverride = new RootPathOverride();
    $rootPathOverride->push('/custom-root/');

    try {
        $service = UrlServiceTestFactory::build(null, $rootPathOverride);

        $params = $service->paramsForDuplication([], []);

        expect($params['root_path'])->toBe('/custom-root/');
    } finally {
        $rootPathOverride->pop();
    }
});

test('makePictureUrl uses the id-file style, appending a slugified filename', function (): void {
    // Both default to true (config_default.inc.php's own real production
    // values), which would otherwise prefix every assertion below with
    // 'picture.php?' -- disabled here to isolate the picture_url_style
    // switch itself, already covered separately for makeIndexUrl-adjacent
    // behavior elsewhere.
    CurrentConfigTestFactory::get()->phpExtensionInUrls = false;
    CurrentConfigTestFactory::get()->questionMarkInUrls = false;
    CurrentConfigTestFactory::get()->pictureUrlStyle = 'id-file';
    $service = UrlServiceTestFactory::build();

    $url = $service->makePictureUrl([
        'image_id' => 42,
        'image_file' => 'Summer Trip.jpg',
    ]);

    // getRootUrl() is '' here (no mount depth, no override, same baseline
    // as the "getRootUrl returns an empty string" test above) -- no
    // leading slash.
    expect($url)
        ->toBe('picture/42-summer_trip');
});

test('makePictureUrl uses the file style directly when the filename does not start with a digit', function (): void {
    CurrentConfigTestFactory::get()->phpExtensionInUrls = false;
    CurrentConfigTestFactory::get()->questionMarkInUrls = false;
    CurrentConfigTestFactory::get()->pictureUrlStyle = 'file';
    $service = UrlServiceTestFactory::build();

    $url = $service->makePictureUrl([
        'image_id' => 42,
        'image_file' => 'sunset.jpg',
    ]);

    expect($url)
        ->toBe('picture/sunset');
});

test('makePictureUrl falls through the file style to the bare id when the filename starts with digits', function (): void {
    CurrentConfigTestFactory::get()->phpExtensionInUrls = false;
    CurrentConfigTestFactory::get()->questionMarkInUrls = false;
    CurrentConfigTestFactory::get()->pictureUrlStyle = 'file';
    $service = UrlServiceTestFactory::build();

    // '42-something.jpg' matches /^\d+(-|$)/ -- falls through (no break) to
    // the default arm, using the bare image_id instead of the filename.
    $url = $service->makePictureUrl([
        'image_id' => 42,
        'image_file' => '42-something.jpg',
    ]);

    expect($url)
        ->toBe('picture/42');
});

test('getRootUrl treats an empty-string section rootPath the same as no rootPath at all', function (): void {
    urlServiceTestWithMountDepth(1, function (): void {
        urlServiceTestSectionContextRegistry()->set(new SectionContext(rootPath: ''));
        $service = UrlServiceTestFactory::build();

        expect($service->getRootUrl())
            ->toBe('../');
    });
});

/**
 * A mutation-testing sweep found two confirmed-equivalent mutants inside
 * getAbsoluteRootUrl() -- verified live by sed-mutating the exact source
 * line, rerunning this file's full suite, and confirming it still passes,
 * then restoring the original:
 *
 * - `$url .= 'https://';` (the "then" arm) and `$url .= 'http://';` (the
 *   "else" arm) each mutate to `$url = '...'` (ConcatEqualToEqual). `$url`
 *   is unconditionally assigned `''` immediately before the enclosing
 *   `if ($withScheme)` block, and nothing between that assignment and
 *   either arm can change it -- so `.=` and `=` write the exact same value
 *   in both arms, for every possible input.
 */
test('getAbsoluteRootUrl detects HTTPS from a case-insensitive $_SERVER[\'HTTPS\'] value', function (): void {
    $_SERVER['HTTPS'] = 'ON';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('https://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl omits the standard auto-detected port 443 for https', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'auto';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $_SERVER['SERVER_PORT'] = '443';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('https://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl trusts a forwarded host header when allowed_hosts is unconfigured', function (): void {
    $_SERVER['HTTP_X_FORWARDED_HOST'] = 'forwarded.example.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://forwarded.example.test/piwigo/');
});

test('getAbsoluteRootUrl falls back to an empty host segment when no host header, forwarded host, or gallery_url is present', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'none';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http:///piwigo/');
});

test('getAbsoluteRootUrl omits the port segment entirely when the auto-detected server port is unavailable', function (): void {
    // A missing SERVER_PORT defaults $server_port to 80 (the standard HTTP
    // port) rather than null, so no port segment is appended for this
    // non-HTTPS request.
    CurrentConfigTestFactory::get()->urlPort = 'auto';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    unset($_SERVER['SERVER_PORT']);
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://gallery.example.test/piwigo/');
});

test('getAbsoluteRootUrl does not duplicate a port already present via the Host header', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'auto';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test:8080';
    $_SERVER['SERVER_PORT'] = '8080';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://gallery.example.test:8080/piwigo/');
});

test('getAbsoluteRootUrl falls back to the Host header when gallery_url is an empty string', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'none';
    CurrentConfigTestFactory::get()->galleryUrl = '';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://gallery.example.test/piwigo/');
});

/**
 * configuredHost()'s `$gallery_url === ''` (Line 259 as of this
 * mutation-gap-closure pass -- re-verified fresh via hand-mutation, not
 * just trusting this note's own stale prior line reference) is a
 * confirmed equivalent. For the one input that distinguishes it
 * (`$gallery_url === ''`), real code returns null immediately via this
 * guard; the mutant instead falls through to
 * `parse_url('', PHP_URL_HOST)`, which itself returns null (not a
 * string) -- so the very next guard (`! is_string($host) || ...`,
 * Line 264) catches it and returns null anyway. Both paths produce the
 * exact same final `null`, so no test (including the one right above,
 * which exercises this literal input) can tell them apart. Line 264's
 * own `$host === ''` EmptyStringToNotEmpty mutant is equivalent for the
 * identical reason, re-verified the same way.
 */
test('getAbsoluteRootUrl falls back to the Host header when gallery_url has an empty authority (no host at all)', function (): void {
    // parse_url('http:///x', PHP_URL_HOST) returns bool(false) (not a
    // string, not null) -- a distinct shape from the 'not-a-real-url-at-all'
    // case above (which parses to null): both must hit the same "! is_string
    // || ===''" early-return in configuredHost(), covering the '! is_string'
    // operand specifically (host is bool false here, never the empty
    // string) rather than the "===''" operand.
    CurrentConfigTestFactory::get()->urlPort = 'none';
    CurrentConfigTestFactory::get()->galleryUrl = 'http:///x';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://gallery.example.test/piwigo/');
});

/**
 * addUrlParams()'s `$is_first = false;` (line 210) FalseToTrue mutant and
 * its `(string) $val` (line 217) RemoveStringCast mutant are both confirmed
 * equivalents -- verified live the same sed-mutate-and-rerun way.
 *
 * - FalseToTrue: once mutated, $is_first never becomes false, so every
 *   iteration re-enters the `if ($is_first)` branch and re-evaluates
 *   `! str_contains($url, '?') ? '?' : $argSeparator`. But by the time any
 *   iteration after the first runs, $url is *guaranteed* to already contain
 *   a '?' -- either it appended the literal '?' itself on iteration 0, or
 *   the caller's $url already had one going in (in which case iteration 0
 *   appended $argSeparator instead of '?', but the '?' from before was
 *   already there). So from iteration 1 onward this ternary always
 *   resolves to $argSeparator, byte-for-byte identical to what the real
 *   "else" branch (`$url .= $argSeparator;`) does -- no input can produce
 *   a different result.
 * - RemoveStringCast: `$url .= '=' . (is_scalar($val) ? $val : '')` --
 *   PHP's `.=`/`.` operators coerce any scalar operand (int, float, bool,
 *   string) to a string using the exact same rules as an explicit
 *   `(string)` cast, so dropping the cast immediately before a
 *   concatenation is a no-op for every scalar value.
 */
test('addUrlParams switches the default separator to a plain ampersand inside an API request context', function (): void {
    KernelContainerOverride::with([
        ApiContext::class => new ApiContext(true),
    ], function (): void {
        $service = UrlServiceTestFactory::build();

        expect($service->addUrlParams('/x', [
            'a' => 'b',
            'c' => 'd',
        ]))->toBe('/x?a=b&c=d');
    });
});

test('addUrlParams appends an empty value for a non-scalar param', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->addUrlParams('/x', [
        'a' => ['nested'],
    ]))->toBe('/x?a=');
});

test('makeIndexUrl builds a real path when params add a section, keeping the php extension and question mark', function (): void {
    $service = UrlServiceTestFactory::build();

    $url = $service->makeIndexUrl([
        'section' => 'categories',
    ]);

    expect($url)
        ->toBe('index.php?/categories');
});

test('makeIndexUrl falls back to the absolute root URL when no params add anything to the path', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->makeIndexUrl())
        ->toBe($service->getAbsoluteRootUrl(false));
});

test('paramsForDuplication seeds params from the current section context', function (): void {
    urlServiceTestSectionContextRegistry()->set(new SectionContext(section: Section::Tags));
    $service = UrlServiceTestFactory::build();

    $params = $service->paramsForDuplication([], []);

    expect($params['section'])->toBe('tags');
});

test('paramsForDuplication removes listed keys and applies redefinitions', function (): void {
    urlServiceTestSectionContextRegistry()->set(new SectionContext(section: Section::Tags, start: 20));
    $service = UrlServiceTestFactory::build();

    $params = $service->paramsForDuplication([
        'section' => 'categories',
    ], ['start']);

    expect($params['section'])->toBe('categories')
        ->and(array_key_exists('start', $params))
        ->toBeFalse();
});

/**
 * A mutation-testing sweep found four more confirmed-equivalent mutants
 * inside makePictureUrl(), all verified live the same sed-mutate-and-rerun
 * way:
 *
 * - The three `is_scalar($image_id) ? (string) $image_id : ''` /
 *   `is_scalar($start) ? (string) $start : ''`-shaped RemoveStringCast
 *   mutants (the id-file-style, default-arm, and addWellKnownParamsInUrl's
 *   own start segment) are equivalent for the same reason as addUrlParams's
 *   own `(string) $val` above: concatenation already coerces any scalar to
 *   string identically to an explicit cast.
 * - `! (bool) preg_match(...)` (in the 'file' style's digit-boundary
 *   guard) RemoveBooleanCast: `!` itself always coerces its operand to
 *   bool first (preg_match() only ever returns int|false, both of which
 *   `!` and `(bool)` interpret identically), so the explicit cast changes
 *   nothing.
 * - `$fname_wo_ext[0]` (same guard) IncrementInteger, mutating the index
 *   to `[1]`: the guard is
 *   `ord($fname_wo_ext[0]) > ord('9') or ! (bool) preg_match('/^\d+(-|$)/', $fname_wo_ext)`.
 *   Whenever the regex fails to match (preg_match's second operand is
 *   true), $fname_wo_ext doesn't start with `\d+(-|$)` at all, which
 *   happens precisely when index 0 is *not* itself part of a clean
 *   digit-then-dash-or-end run -- and by construction of `\d+(-|$)`, on
 *   every string where the regex *does* match, index 1 (if it exists) is
 *   either another digit or the terminating '-', so `ord(...) > ord('9')`
 *   is false at index 1 exactly when it's false at index 0 too. Every
 *   input therefore produces the same overall boolean via index 1 as via
 *   index 0 -- confirmed against both the 'sunset.jpg' (non-digit-led),
 *   '9-something.jpg' (single-digit-then-dash), and '42-something.jpg'
 *   (multi-digit-then-dash) shapes exercised by the tests in this file.
 */
test('makePictureUrl prefixes the root URL before the picture path segment', function (): void {
    urlServiceTestWithMountDepth(1, function (): void {
        CurrentConfigTestFactory::get()->phpExtensionInUrls = false;
        CurrentConfigTestFactory::get()->questionMarkInUrls = false;
        CurrentConfigTestFactory::get()->pictureUrlStyle = 'id-file';
        $service = UrlServiceTestFactory::build();

        $url = $service->makePictureUrl([
            'image_id' => 5,
        ]);

        expect($url)
            ->toBe('../picture/5');
    });
});

test('makePictureUrl appends the php extension and question mark by default, preserving the picture prefix', function (): void {
    CurrentConfigTestFactory::get()->pictureUrlStyle = 'id-file';
    $service = UrlServiceTestFactory::build();

    $url = $service->makePictureUrl([
        'image_id' => 5,
    ]);

    expect($url)
        ->toBe('picture.php?/5');
});

test('makePictureUrl in id-file style uses an empty id segment when image_id is absent', function (): void {
    CurrentConfigTestFactory::get()->phpExtensionInUrls = false;
    CurrentConfigTestFactory::get()->questionMarkInUrls = false;
    CurrentConfigTestFactory::get()->pictureUrlStyle = 'id-file';
    $service = UrlServiceTestFactory::build();

    $url = $service->makePictureUrl([]);

    expect($url)
        ->toBe('picture/');
});

test('makePictureUrl in id-file style omits the filename suffix when image_file is not a string', function (): void {
    CurrentConfigTestFactory::get()->phpExtensionInUrls = false;
    CurrentConfigTestFactory::get()->questionMarkInUrls = false;
    CurrentConfigTestFactory::get()->pictureUrlStyle = 'id-file';
    $service = UrlServiceTestFactory::build();

    $url = $service->makePictureUrl([
        'image_id' => 5,
        'image_file' => 123,
    ]);

    expect($url)
        ->toBe('picture/5');
});

test('makePictureUrl in file style falls through to the bare id when image_file is not a string', function (): void {
    CurrentConfigTestFactory::get()->phpExtensionInUrls = false;
    CurrentConfigTestFactory::get()->questionMarkInUrls = false;
    CurrentConfigTestFactory::get()->pictureUrlStyle = 'file';
    $service = UrlServiceTestFactory::build();

    $url = $service->makePictureUrl([
        'image_id' => 5,
        'image_file' => 123,
    ]);

    expect($url)
        ->toBe('picture/5');
});

test('makePictureUrl in file style respects the ord(\'9\') boundary exactly (a lone leading 9 still falls through)', function (): void {
    CurrentConfigTestFactory::get()->phpExtensionInUrls = false;
    CurrentConfigTestFactory::get()->questionMarkInUrls = false;
    CurrentConfigTestFactory::get()->pictureUrlStyle = 'file';
    $service = UrlServiceTestFactory::build();

    // '9-something' starts with '9' (ord 57, not > ord('9')) and matches
    // /^\d+(-|$)/ -- both operands of the guard are false, so this falls
    // through to the bare image_id, same as the existing '42-something.jpg'
    // test above but isolating the exact '9' boundary instead of '4'.
    $url = $service->makePictureUrl([
        'image_id' => 99,
        'image_file' => '9-something.jpg',
    ]);

    expect($url)
        ->toBe('picture/99');
});

test('makePictureUrl in file style uses the filename when it starts with digits but does not match the id-like pattern', function (): void {
    CurrentConfigTestFactory::get()->phpExtensionInUrls = false;
    CurrentConfigTestFactory::get()->questionMarkInUrls = false;
    CurrentConfigTestFactory::get()->pictureUrlStyle = 'file';
    $service = UrlServiceTestFactory::build();

    // '42abc' starts with a digit (first operand false) but does not match
    // /^\d+(-|$)/ since the digit run is followed by a letter, not '-' or
    // end-of-string (second operand true) -- isolates the "or" from an "and".
    $url = $service->makePictureUrl([
        'image_id' => 77,
        'image_file' => '42abc.jpg',
    ]);

    expect($url)
        ->toBe('picture/42abc');
});

test('makePictureUrl uses an empty id segment in the default style branch when image_id is absent', function (): void {
    CurrentConfigTestFactory::get()->phpExtensionInUrls = false;
    CurrentConfigTestFactory::get()->questionMarkInUrls = false;
    CurrentConfigTestFactory::get()->pictureUrlStyle = 'unrecognized-style';
    $service = UrlServiceTestFactory::build();

    $url = $service->makePictureUrl([]);

    expect($url)
        ->toBe('picture/');
});

test('makePictureUrl drops the flat param when no category is given (shorter urls)', function (): void {
    CurrentConfigTestFactory::get()->phpExtensionInUrls = false;
    CurrentConfigTestFactory::get()->questionMarkInUrls = false;
    CurrentConfigTestFactory::get()->pictureUrlStyle = 'id-file';
    $service = UrlServiceTestFactory::build();

    $url = $service->makePictureUrl([
        'image_id' => 5,
        'flat' => true,
    ]);

    expect($url)
        ->toBe('picture/5');
});

test('addWellKnownParamsInUrl appends /start-N for the boundary value start=1', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->addWellKnownParamsInUrl('/x', [
        'start' => 1,
    ]))->toBe('/x/start-1');
});

test('addWellKnownParamsInUrl appends an empty start segment when start is a non-scalar truthy value', function (): void {
    // ['nested'] > 0 is true (PHP: an array always compares greater than an
    // int), so the isset()+>0 guard passes even though $start itself is not
    // scalar -- isolates the (string) cast's own is_scalar() fallback.
    $service = UrlServiceTestFactory::build();

    expect($service->addWellKnownParamsInUrl('/x', [
        'start' => ['nested'],
    ]))->toBe('/x/start-');
});

test('makeSectionInUrl defaults a non-array category param to an empty array', function (): void {
    $service = UrlServiceTestFactory::build();

    set_error_handler(static fn (): bool => true);
    try {
        $result = $service->makeSectionInUrl([
            'section' => 'categories',
            'category' => 'not-an-array',
        ]);
    } finally {
        restore_error_handler();
    }

    expect($result)
        ->toBe('/category/');
});

test('makeSectionInUrl uses the category permalink directly when set', function (): void {
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'categories',
        'category' => [
            'id' => 7,
            'name' => 'Vacation',
            'permalink' => 'my-vacation',
        ],
    ]);

    expect($result)
        ->toBe('/category/my-vacation');
});

test('makeSectionInUrl appends the slugified name in id-name style', function (): void {
    CurrentConfigTestFactory::get()->categoryUrlStyle = 'id-name';
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'categories',
        'category' => [
            'id' => 7,
            'name' => 'Vacation Photos',
            'permalink' => null,
        ],
    ]);

    expect($result)
        ->toBe('/category/7-vacation_photos');
});

test('makeSectionInUrl appends combined categories, defaulting a non-array entry gracefully', function (): void {
    CurrentConfigTestFactory::get()->categoryUrlStyle = 'id-name';
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'categories',
        'category' => [
            'id' => 7,
            'name' => 'Main',
            'permalink' => null,
        ],
        'combined_categories' => [
            'not-an-array',
            [
                'id' => 9,
                'name' => 'Second',
                'permalink' => null,
            ],
            [
                'id' => 11,
                'name' => '',
                'permalink' => 'third-perma',
            ],
        ],
    ]);

    // The 'not-an-array' entry resets to [] (no id/name/permalink), so it
    // still contributes its own '/' + '' (id) + '-' + '' (name) segment.
    expect($result)
        ->toBe('/category/7-main/-/9-second/third-perma');
});

test('makeSectionInUrl builds a tags section in the "id" style', function (): void {
    CurrentConfigTestFactory::get()->tagUrlStyle = 'id';
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'tags',
        'tags' => [[
            'id' => 3,
            'url_name' => 'nature',
        ], [
            'id' => 5,
            'url_name' => 'travel',
        ]],
    ]);

    expect($result)
        ->toBe('/tags/3/5');
});

test('makeSectionInUrl builds a tags section in the "tag" style using url_name', function (): void {
    CurrentConfigTestFactory::get()->tagUrlStyle = 'tag';
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'tags',
        'tags' => [[
            'id' => 3,
            'url_name' => 'nature',
        ]],
    ]);

    expect($result)
        ->toBe('/tags/nature');
});

test('makeSectionInUrl falls through the "tag" style to id-name when url_name is absent', function (): void {
    CurrentConfigTestFactory::get()->tagUrlStyle = 'tag';
    $service = UrlServiceTestFactory::build();

    // No url_name -- falls through (no break) to the default arm.
    $result = $service->makeSectionInUrl([
        'section' => 'tags',
        'tags' => [[
            'id' => 3,
        ]],
    ]);

    expect($result)
        ->toBe('/tags/3');
});

test('makeSectionInUrl builds a tags section in the default id-name style', function (): void {
    CurrentConfigTestFactory::get()->tagUrlStyle = 'id-tag';
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'tags',
        'tags' => [[
            'id' => 3,
            'url_name' => 'nature',
        ]],
    ]);

    expect($result)
        ->toBe('/tags/3-nature');
});

test('makeSectionInUrl defaults a non-array tags entry gracefully', function (): void {
    CurrentConfigTestFactory::get()->tagUrlStyle = 'id-tag';
    $service = UrlServiceTestFactory::build();

    // Same "reset to []" defaulting as the analogous combined_categories
    // entry above -- a non-array tag contributes its own '/' + '' (id)
    // segment (no '-name' suffix, since url_name is also unset once reset).
    $result = $service->makeSectionInUrl([
        'section' => 'tags',
        'tags' => [
            'not-an-array', [
                'id' => 3,
                'url_name' => 'nature',
            ]],
    ]);

    expect($result)
        ->toBe('/tags//3-nature');
});

test('makeSectionInUrl builds a search section', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->makeSectionInUrl([
        'section' => 'search',
        'search' => 'psk-20260101-abcdefghij',
    ]))
        ->toBe('/search/psk-20260101-abcdefghij');
});

test('makeSectionInUrl builds a list section from scalar ids only', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->makeSectionInUrl([
        'section' => 'list',
        'list' => [
            12,
            34,
            'not-scalar' => ['x'],
        ],
    ]))
        ->toBe('/list/12,34');
});

test('makeSectionInUrl infers the categories section from a bare category param when section is unset', function (): void {
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'category' => [
            'id' => 1,
            'name' => 'Test',
            'permalink' => 'test-perma',
        ],
    ]);

    expect($result)
        ->toBe('/category/test-perma');
});

test('makeSectionInUrl infers the tags section from a bare tags param when section is unset', function (): void {
    CurrentConfigTestFactory::get()->tagUrlStyle = 'id';
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'tags' => [[
            'id' => 3,
            'url_name' => 'nature',
        ]],
    ]);

    expect($result)
        ->toBe('/tags/3');
});

test('makeSectionInUrl infers the list section from a bare list param when section is unset', function (): void {
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'list' => [1, 2, 3],
    ]);

    expect($result)
        ->toBe('/list/1,2,3');
});

test('makeSectionInUrl infers the search section from a bare search param when section is unset', function (): void {
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'search' => 'hello',
    ]);

    expect($result)
        ->toBe('/search/hello');
});

test('makeSectionInUrl treats an explicit empty-string permalink the same as unset, falling back to the id', function (): void {
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'categories',
        'category' => [
            'id' => 10,
            'name' => 'Test',
            'permalink' => '',
        ],
    ]);

    expect($result)
        ->toBe('/category/10');
});

test('makeSectionInUrl falls back to an empty slugified name when the name key is absent in id-name style', function (): void {
    CurrentConfigTestFactory::get()->categoryUrlStyle = 'id-name';
    $service = UrlServiceTestFactory::build();

    // 'name' is absent (only 'permalink' is present, as null) -- triggers
    // the same "category name not set" E_USER_WARNING as the "non-array
    // category param" test above.
    set_error_handler(static fn (): bool => true);
    try {
        $result = $service->makeSectionInUrl([
            'section' => 'categories',
            'category' => [
                'id' => 7,
                'permalink' => null,
            ],
        ]);
    } finally {
        restore_error_handler();
    }

    expect($result)
        ->toBe('/category/7-');
});

test('makeSectionInUrl falls back to an empty permalink string when the category permalink is non-scalar', function (): void {
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'categories',
        'category' => [
            'id' => 1,
            'name' => 'X',
            'permalink' => ['a', 'b'],
        ],
    ]);

    expect($result)
        ->toBe('/category/');
});

test('makeSectionInUrl treats an explicit empty-string combined-category permalink like unset, using the id', function (): void {
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'categories',
        'category' => [
            'id' => 1,
            'name' => 'Main',
            'permalink' => 'main-perma',
        ],
        'combined_categories' => [
            [
                'id' => 21,
                'name' => 'Fourth',
                'permalink' => '',
            ],
        ],
    ]);

    expect($result)
        ->toBe('/category/main-perma/21');
});

test('makeSectionInUrl falls back to an empty combined-category permalink string when non-scalar', function (): void {
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'categories',
        'category' => [
            'id' => 1,
            'name' => 'Main',
            'permalink' => 'main-perma',
        ],
        'combined_categories' => [
            [
                'id' => 31,
                'name' => 'Fifth',
                'permalink' => ['x'],
            ],
        ],
    ]);

    expect($result)
        ->toBe('/category/main-perma/');
});

test('makeSectionInUrl appends an empty id segment for a tag missing its id, in "id" style', function (): void {
    CurrentConfigTestFactory::get()->tagUrlStyle = 'id';
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'tags',
        'tags' => [[]],
    ]);

    expect($result)
        ->toBe('/tags/');
});

test('makeSectionInUrl falls through the "tag" style to default when url_name is present but non-scalar', function (): void {
    CurrentConfigTestFactory::get()->tagUrlStyle = 'tag';
    $service = UrlServiceTestFactory::build();

    // url_name is set (non-null) but not scalar -- the "isset && is_scalar"
    // guard must require BOTH, not either: an OR would wrongly try to use
    // the array as the url_name segment instead of falling through.
    $result = $service->makeSectionInUrl([
        'section' => 'tags',
        'tags' => [[
            'id' => 99,
            'url_name' => ['nested'],
        ]],
    ]);

    expect($result)
        ->toBe('/tags/99');
});

test('makeSectionInUrl omits the tag name suffix in the default style when url_name is non-scalar', function (): void {
    CurrentConfigTestFactory::get()->tagUrlStyle = 'id-tag';
    $service = UrlServiceTestFactory::build();

    // Reaches the default arm directly (not via the "tag" style fallthrough)
    // -- isolates its own "isset && is_scalar" guard on the suffix.
    $result = $service->makeSectionInUrl([
        'section' => 'tags',
        'tags' => [[
            'id' => 55,
            'url_name' => ['nested'],
        ]],
    ]);

    expect($result)
        ->toBe('/tags/55');
});

test('makeSectionInUrl appends an empty search segment when no search param is present', function (): void {
    $service = UrlServiceTestFactory::build();

    $result = $service->makeSectionInUrl([
        'section' => 'search',
    ]);

    expect($result)
        ->toBe('/search/');
});

test('parseSectionUrl recognizes the favorites/most_visited/best_rated/recent_pics/recent_cats tokens', function (): void {
    $service = UrlServiceTestFactory::build();
    $redirect = new UrlServiceTestRedirectService();

    $expectedSectionFor = [
        'favorites' => Section::Favorites,
        'most_visited' => Section::MostVisited,
        'best_rated' => Section::BestRated,
        'recent_pics' => Section::RecentPics,
        'recent_cats' => Section::RecentCats,
    ];

    foreach ($expectedSectionFor as $token => $expectedSection) {
        $i = 0;
        $page = $service->parseSectionUrl([$token], $i, $redirect);

        expect($page['section'])->toBe($expectedSection)
            ->and($i)
            ->toBe(1);
    }
});

test('parseSectionUrl parses a valid psk-formatted search token', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $page = $service->parseSectionUrl(['search', 'psk-20260101-abcdefghij'], $i, new UrlServiceTestRedirectService());

    expect($page['section'])->toBe(Section::Search)
        ->and($page['search'])->toBe('psk-20260101-abcdefghij')
        ->and($i)
        ->toBe(2);
});

test('parseSectionUrl falls back to a plain numeric search identifier', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $page = $service->parseSectionUrl(['search', '42'], $i, new UrlServiceTestRedirectService());

    expect($page['search'])->toBe('42');
});

test('parseSectionUrl rejects a search token with no usable identifier', function (): void {
    $service = UrlServiceTestFactory::build(new UrlServiceTestHtmlRenderer());
    $i = 0;

    expect(fn (): array => $service->parseSectionUrl(['search', 'no-digits-here'], $i, new UrlServiceTestRedirectService()))
        ->toThrow(RuntimeException::class, 'badRequest: search identifier is missing');
});

test('parseSectionUrl defaults an empty list token to the dummy [-1] element', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $page = $service->parseSectionUrl(['list', ''], $i, new UrlServiceTestRedirectService());

    expect($page['section'])->toBe(Section::ListView)
        ->and($page['list'])->toBe([-1]);
});

test('parseSectionUrl parses a comma-separated list of image ids', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $page = $service->parseSectionUrl(['list', '12,34,56'], $i, new UrlServiceTestRedirectService());

    expect($page['list'])->toBe(['12', '34', '56']);
});

test('parseSectionUrl rejects a malformed list token', function (): void {
    $htmlRenderer = new UrlServiceTestHtmlRenderer();
    $service = UrlServiceTestFactory::build($htmlRenderer);
    $i = 0;

    expect(fn (): array => $service->parseSectionUrl(['list', 'not-a-list'], $i, new UrlServiceTestRedirectService()))
        ->toThrow(RuntimeException::class, 'badRequest: wrong format on list GET parameter');
});

test('parseWellKnownParamsUrl parses a chronology token with an explicit calendar view', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['created-monthly-calendar-2026-07'], $i);

    expect($result)
        ->toBe([
            'chronology_field' => 'created',
            'chronology_style' => 'monthly',
            'chronology_view' => 'calendar',
            'chronology_date' => ['2026', '07'],
        ]);
});

test('parseWellKnownParamsUrl parses a startcat token', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['startcat-15'], $i);

    expect($result)
        ->toBe([
            'startcat' => '15',
        ]);
});

test('parseWellKnownParamsUrl rejects an unrecognized chronology style', function (): void {
    $htmlRenderer = new UrlServiceTestHtmlRenderer();
    $service = UrlServiceTestFactory::build($htmlRenderer);
    $i = 0;

    expect(fn (): array => $service->parseWellKnownParamsUrl(['created-bogus'], $i))
        ->toThrow(RuntimeException::class, 'fatalError: bad chronology field (style)');
});

test('parseWellKnownParamsUrl rejects a non-numeric chronology date token', function (): void {
    $htmlRenderer = new UrlServiceTestHtmlRenderer();
    $service = UrlServiceTestFactory::build($htmlRenderer);
    $i = 0;

    expect(fn (): array => $service->parseWellKnownParamsUrl(['created-monthly-not-a-number'], $i))
        ->toThrow(RuntimeException::class, 'fatalError: bad chronology field (date)');
});

test('getElementUrl embellishes a non-remote path with the root URL', function (): void {
    urlServiceTestWithMountDepth(1, function (): void {
        $service = UrlServiceTestFactory::build();

        expect($service->getElementUrl([
            'path' => 'galleries/2026/photo.jpg',
        ]))
            ->toBe('../galleries/2026/photo.jpg');
    });
});

test('getElementUrl leaves a remote path unchanged', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->getElementUrl([
        'path' => 'https://cdn.example.test/photo.jpg',
    ]))
        ->toBe('https://cdn.example.test/photo.jpg');
});

test('getElementUrl coerces a non-string path to its string form', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->getElementUrl([
        'path' => 123,
    ]))->toBe('123');
});

test('embellishUrl leaves a /../ segment unresolved when there is no preceding segment to collapse against', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->embellishUrl('a/../b'))
        ->toBe('a/../b');
});

test('getUserFavorites returns an empty array for a guest', function (): void {
    // getUserFavorites() reaches AccessControl::current()->isAGuest()
    // (UrlService.php's own guard) -- unlike CurrentUserTestFactory::get(),
    // AccessControl::current() has no pre-boot memoized fallback, so a
    // real booted Kernel is required here. The user status must be seeded
    // INSIDE the callback, once the container exists (same pitfall
    // Translator/EventDispatcher hit too).
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 2,
                'status' => 'guest',
            ]));

            $service = UrlServiceTestFactory::build();

            expect($service->getUserFavorites())
                ->toBe([]);
        }
    );
});

test('parseSectionUrl enters the categories section for a token starting with "categor"', function (): void {
    // The categories branch unconditionally constructs a CategoryService
    // (needs a real Piwigo\Core\Lang constructor param) right after
    // entering, before the
    // while loop below it ever runs -- same "real booted Kernel + a real
    // Paths for Lang's own constructor" requirement as the
    // getUserFavorites() test above, even though this test's single,
    // followerless token never reaches the loop body itself.
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            $service = UrlServiceTestFactory::build();
            $i = 0;

            // A single token with nothing following it -- the while loop
            // inside the categories branch never executes (isset($tokens[1])
            // is false), so this exercises the str_starts_with() guard
            // itself without needing any category row to actually exist in
            // the DB.
            $page = $service->parseSectionUrl(['category'], $i, new UrlServiceTestRedirectService());

            expect($page)
                ->toBe([
                    'section' => Section::Categories,
                ])
                ->and($i)
                ->toBe(1);
        }
    );
});

test('parseSectionUrl collects a numeric category token as $category, and a second one as a combined category', function (): void {
    // Real gap, newly surfaced by adding real loop-body coverage above:
    // real category ids 1 ("Sample Album") and 2 ("Nested Sub Album")
    // are committed fixture rows in this shared test DB. This is the
    // ONLY way to distinguish Line 722's own `$category === null` check
    // -- an IfNegated/IdenticalToNotIdentical mutation there makes the
    // FIRST token also fall through to $combined_category_ids instead of
    // becoming $category, leaving $category null forever (so
    // $page['category'] never gets set at all) and stuffing BOTH ids
    // into combined_categories instead of just the second one.
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            $service = UrlServiceTestFactory::build();
            $i = 0;

            $page = $service->parseSectionUrl(['category', '1', '2'], $i, new UrlServiceTestRedirectService());

            $combinedCategories = $page['combined_categories'] ?? null;
            if (! is_array($combinedCategories)) {
                throw new RuntimeException('Expected $page[\'combined_categories\'] to be an array.');
            }

            expect($i)
                ->toBe(3)
                ->and(urlServiceTestCategoryId($page['category'] ?? null))->toBe(1)
                ->and($combinedCategories)
                ->toHaveCount(1)
                ->and(urlServiceTestCategoryId($combinedCategories[0] ?? null))->toBe(2);
        }
    );
});

test('parseSectionUrl captures the dashed suffix of a category token as cat_url_name, using the real id capture group', function (): void {
    // Real gap, newly surfaced by adding real loop-body coverage above:
    // '1', '2' above have no "-suffix", so $matches[0] (whole match) and
    // $matches[1] (id capture group) were identical either way -- a
    // real "-suffix" makes them genuinely diverge ($matches[0] would be
    // "1-sample-album", not the real id "1"), and $matches[2] being
    // genuinely SET here (unlike '1'/'2' above) is the only way to
    // distinguish Line 718's own isset($matches[2]) from an
    // IncrementInteger mutation checking $matches[3] instead.
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            $service = UrlServiceTestFactory::build();
            $i = 0;

            $page = $service->parseSectionUrl(['category', '1-sample-album'], $i, new UrlServiceTestRedirectService());

            expect($i)
                ->toBe(2)
                ->and(urlServiceTestCategoryId($page['category'] ?? null))->toBe(1)
                ->and($page['hit_by'] ?? null)->toBe([
                    'cat_url_name' => 'sample-album',
                ]);
        }
    );
});

test('parseSectionUrl stops the categories loop at a "created-" continuation token, without consuming it', function (): void {
    // Real gap, newly surfaced by adding real loop-body coverage above --
    // same reasoning as the tags-loop "created-"/"posted-" tests, for
    // the categories loop's own break condition (Line 708).
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            $service = UrlServiceTestFactory::build();
            $i = 0;

            $page = $service->parseSectionUrl(['category', '1', 'created-x'], $i, new UrlServiceTestRedirectService());

            expect($i)
                ->toBe(2)
                ->and(urlServiceTestCategoryId($page['category'] ?? null))->toBe(1);
        }
    );
});

test('parseSectionUrl stops the categories loop at a "posted-" continuation token, without consuming it', function (): void {
    // Real gap, newly surfaced -- same reasoning as the sibling
    // "created-" test above, for Line 709's own break check.
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            $service = UrlServiceTestFactory::build();
            $i = 0;

            $page = $service->parseSectionUrl(['category', '1', 'posted-x'], $i, new UrlServiceTestRedirectService());

            expect($i)
                ->toBe(2)
                ->and(urlServiceTestCategoryId($page['category'] ?? null))->toBe(1);
        }
    );
});

test('parseSectionUrl stops the categories loop at a "start-" continuation token, without consuming it', function (): void {
    // Real gap, newly surfaced by the SECOND verify rerun (Line 710's own
    // break check) -- same reasoning as the sibling "created-" test above.
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            $service = UrlServiceTestFactory::build();
            $i = 0;

            $page = $service->parseSectionUrl(['category', '1', 'start-5'], $i, new UrlServiceTestRedirectService());

            expect($i)
                ->toBe(2)
                ->and(urlServiceTestCategoryId($page['category'] ?? null))->toBe(1);
        }
    );
});

test('parseSectionUrl stops the categories loop at a "startcat-" continuation token, without consuming it', function (): void {
    // Real gap, newly surfaced -- same reasoning as the sibling
    // "created-" test above, for Line 711's own break check.
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            $service = UrlServiceTestFactory::build();
            $i = 0;

            $page = $service->parseSectionUrl(['category', '1', 'startcat-5'], $i, new UrlServiceTestRedirectService());

            expect($i)
                ->toBe(2)
                ->and(urlServiceTestCategoryId($page['category'] ?? null))->toBe(1);
        }
    );
});

test('parseSectionUrl rejects a bare tags token with no tag identifiers', function (): void {
    $service = UrlServiceTestFactory::build(new UrlServiceTestHtmlRenderer());
    $i = 0;

    // badRequest() throws before TagService is ever constructed, so this
    // never touches the DB either.
    expect(fn (): array => $service->parseSectionUrl(['tags'], $i, new UrlServiceTestRedirectService()))
        ->toThrow(RuntimeException::class, 'badRequest: at least one tag required');
});

test('parseSectionUrl collects every tag token via the while loop before advancing nextToken and hitting the DB', function (): void {
    // Real gap, found via mutation testing: `$i` is a by-ref parameter,
    // updated in place by the real loop regardless of what the real,
    // DB-backed findTags() lookup afterward finds. Tag id 3 ("family")
    // is a real, committed fixture row in this shared test DB -- using
    // it (rather than a made-up id) lets this assert on the loop's own
    // real behavior without needing to fake or skip the DB call: $i
    // ending at 3 proves the while loop genuinely iterated over BOTH
    // '3' and '5' tokens (collecting into $requested_tag_ids) before
    // $nextToken advanced past them -- a WhileAlwaysFalse mutation would
    // leave $i at its initial value (0) instead.
    //
    // Same "real booted Kernel + a real Paths" requirement as the
    // categories-branch tests above: the tags branch unconditionally
    // constructs a TagService (needing a real ImageService collaborator,
    // in turn needing a real Paths) once tag identifiers are found.
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            $service = UrlServiceTestFactory::build();
            $i = 0;

            $page = $service->parseSectionUrl(['tags', '3', '5'], $i, new UrlServiceTestRedirectService());

            expect($i)
                ->toBe(3)
                ->and($page['tags'])->toHaveCount(1)
                ->and(urlServiceTestFirstTagId($page['tags']))->toBe(3);
        }
    );
});

test('parseSectionUrl stops collecting tag tokens at a "start-" continuation token, without consuming it', function (): void {
    // Real gap, found via mutation testing: this is the ONLY way to
    // distinguish the loop's own str_starts_with('start-') break check
    // (Line 809) from a StrStartsWithToStrEndsWith mutation -- a real
    // "start-" token immediately after a real tag id, confirming the
    // loop breaks (leaving $i pointed AT the "start-" token, not past
    // it) rather than mistakenly consuming it as a third tag identifier.
    //
    // Same "real booted Kernel + a real Paths" requirement as the sibling
    // test above.
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            $service = UrlServiceTestFactory::build();
            $i = 0;

            $page = $service->parseSectionUrl(['tags', '3', 'start-5'], $i, new UrlServiceTestRedirectService());

            expect($i)
                ->toBe(2)
                ->and($page['tags'])->toHaveCount(1)
                ->and(urlServiceTestFirstTagId($page['tags']))->toBe(3);
        }
    );
});

test('parseSectionUrl stops collecting tag tokens at a "posted-" continuation token, without consuming it', function (): void {
    // Real gap, found via mutation testing: same reasoning as the
    // sibling "start-" test above, for Line 808's own
    // str_starts_with('posted-') check specifically -- neither that
    // test's own "start-" token nor the loop's real tag ids exercise
    // this particular branch.
    //
    // Same "real booted Kernel + a real Paths" requirement as the
    // sibling tests above.
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            $service = UrlServiceTestFactory::build();
            $i = 0;

            $page = $service->parseSectionUrl(['tags', '3', 'posted-5'], $i, new UrlServiceTestRedirectService());

            expect($i)
                ->toBe(2)
                ->and($page['tags'])->toHaveCount(1)
                ->and(urlServiceTestFirstTagId($page['tags']))->toBe(3);
        }
    );
});

test('parseSectionUrl advances nextToken past both the list token and its trailing increment', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $service->parseSectionUrl(['list', '12'], $i, new UrlServiceTestRedirectService());

    expect($i)
        ->toBe(2);
});

test('parseWellKnownParamsUrl parses a "posted-" chronology token', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['posted-monthly-2026-07'], $i);

    expect($result)
        ->toBe([
            'chronology_field' => 'posted',
            'chronology_style' => 'monthly',
            'chronology_date' => ['2026', '07'],
        ]);
});

test('parseWellKnownParamsUrl accepts a "weekly" chronology style', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['created-weekly-2026'], $i);

    expect($result)
        ->toBe([
            'chronology_field' => 'created',
            'chronology_style' => 'weekly',
            'chronology_date' => ['2026'],
        ]);
});

test('parseWellKnownParamsUrl leaves chronology_date unset when nothing remains after the style token', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['created-monthly'], $i);

    expect($result)
        ->toBe([
            'chronology_field' => 'created',
            'chronology_style' => 'monthly',
        ]);
});

test('parseWellKnownParamsUrl sets chronology_date from a single remaining token', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['created-monthly-2026'], $i);

    expect($result)
        ->toBe([
            'chronology_field' => 'created',
            'chronology_style' => 'monthly',
            'chronology_date' => ['2026'],
        ]);
});

test('parseWellKnownParamsUrl parses an explicit "list" chronology view', function (): void {
    $service = UrlServiceTestFactory::build();
    $i = 0;

    $result = $service->parseWellKnownParamsUrl(['created-monthly-list-2026-07'], $i);

    expect($result)
        ->toBe([
            'chronology_field' => 'created',
            'chronology_style' => 'monthly',
            'chronology_view' => 'list',
            'chronology_date' => ['2026', '07'],
        ]);
});

test('getActionUrl prefixes action.php with a non-empty root URL', function (): void {
    urlServiceTestWithMountDepth(1, function (): void {
        $service = UrlServiceTestFactory::build();

        expect($service->getActionUrl(42, 'e', false))
            ->toBe('../action.php?id=42&amp;part=e');
    });
});

test('getElementUrl returns an empty string for a non-scalar path', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->getElementUrl([
        'path' => ['not', 'scalar'],
    ]))->toBe('');
});

test('unsetMakeFullUrl pops the override pushed by setMakeFullUrl', function (): void {
    urlServiceTestWithMountDepth(1, function (): void {
        $_SERVER['HTTP_HOST'] = 'gallery.example.test';
        $service = UrlServiceTestFactory::build();

        // getAbsoluteRootUrl()'s own cookiePath() also reads RequestMountDepth
        // (it collapses a trailing '../' against the SCRIPT_NAME dirname), so
        // with mountDepth=1 this resolves to the bare host root, not
        // '.../piwigo/' -- what matters here is only that it's the absolute
        // (scheme+host) form, distinct from the '../' the mount-depth-relative
        // path produces once the override is popped below.
        $service->setMakeFullUrl();
        expect($service->getRootUrl())
            ->toBe('http://gallery.example.test/');

        $service->unsetMakeFullUrl();
        expect($service->getRootUrl())
            ->toBe('../');
    });
});

test('embellishUrl leaves a leading /../ unresolved (the offset skips a match at position 0)', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->embellishUrl('/../b'))
        ->toBe('/../b');
});

test('embellishUrl resolves a /../ immediately following a leading slash', function (): void {
    $service = UrlServiceTestFactory::build();

    expect($service->embellishUrl('//../y'))
        ->toBe('/y');
});

test('getGalleryHomeUrl falls back to makeIndexUrl for an empty-string gallery_url', function (): void {
    CurrentConfigTestFactory::get()->galleryUrl = '';
    $service = UrlServiceTestFactory::build();

    expect($service->getGalleryHomeUrl())
        ->toBe($service->makeIndexUrl());
});

test('getGalleryHomeUrl returns a root-relative gallery_url unchanged, ignoring a non-empty root URL', function (): void {
    urlServiceTestWithMountDepth(1, function (): void {
        CurrentConfigTestFactory::get()->galleryUrl = '/my-gallery';
        $service = UrlServiceTestFactory::build();

        expect($service->getGalleryHomeUrl())
            ->toBe('/my-gallery');
    });
});

test('getGalleryHomeUrl prefixes a relative gallery_url with a non-empty root URL', function (): void {
    urlServiceTestWithMountDepth(1, function (): void {
        CurrentConfigTestFactory::get()->galleryUrl = 'my-gallery/';
        $service = UrlServiceTestFactory::build();

        expect($service->getGalleryHomeUrl())
            ->toBe('../my-gallery/');
    });
});

test('getQueryStringDiff returns empty string for an explicitly empty QUERY_STRING', function (): void {
    $_SERVER['QUERY_STRING'] = '';
    $service = UrlServiceTestFactory::build();

    expect($service->getQueryStringDiff())
        ->toBe('');
});

test('getQueryStringDiff does not prefix a purely-numeric query key', function (): void {
    $_SERVER['QUERY_STRING'] = '0=foo&a=1';
    $service = UrlServiceTestFactory::build();

    expect($service->getQueryStringDiff())
        ->toBe('?0=foo&amp;a=1');
});

test('filterState() returns the container-shared instance once Kernel has booted', function (): void {
    $containerFilterState = Kernel::container()->get(FilterState::class);
    if (! $containerFilterState instanceof FilterState) {
        throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
    }

    $service = UrlServiceTestFactory::build();
    $method = new ReflectionMethod(UrlService::class, 'filterState');

    expect($method->invoke($service))
        ->toBe($containerFilterState);
});

test('filterState() throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(FilterState::class, function (): void {
        $service = UrlServiceTestFactory::build();
        $method = new ReflectionMethod(UrlService::class, 'filterState');

        expect(fn (): mixed => $method->invoke($service))
            ->toThrow(LogicException::class, 'Container returned an unexpected type for ' . FilterState::class);
    });
});

test('translator() throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(Translator::class, function (): void {
        $service = UrlServiceTestFactory::build();
        $method = new ReflectionMethod(UrlService::class, 'translator');

        expect(fn (): mixed => $method->invoke($service))
            ->toThrow(LogicException::class, 'Container returned an unexpected type for ' . Translator::class);
    });
});

test('currentLogger() throws when the container returns an unexpected type', function (): void {
    // Real gap, newly surfaced this pass: the tags/categories while-loop
    // tests above are the first tests in this file to ever construct a
    // real TagService/CategoryService, which is what first exercises
    // this private container-resolution guard at all.
    KernelContainerOverride::withWrongTypeFor(CurrentLogger::class, function (): void {
        $service = UrlServiceTestFactory::build();
        $method = new ReflectionMethod(UrlService::class, 'currentLogger');

        expect(fn (): mixed => $method->invoke($service))
            ->toThrow(LogicException::class, 'Container returned an unexpected type for ' . CurrentLogger::class);
    });
});

test('sessionService() throws when the container returns an unexpected type', function (): void {
    // Real gap, newly surfaced this pass -- same reasoning as
    // currentLogger() above.
    KernelContainerOverride::withWrongTypeFor(SessionService::class, function (): void {
        $service = UrlServiceTestFactory::build();
        $method = new ReflectionMethod(UrlService::class, 'sessionService');

        expect(fn (): mixed => $method->invoke($service))
            ->toThrow(LogicException::class, 'Container returned an unexpected type for ' . SessionService::class);
    });
});

test('getAbsoluteRootUrl defaults the auto-detected port to 80 when SERVER_PORT is set but not numeric', function (): void {
    CurrentConfigTestFactory::get()->urlPort = 'auto';
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $_SERVER['SERVER_PORT'] = 'not-numeric';
    $service = UrlServiceTestFactory::build();

    expect($service->getAbsoluteRootUrl())
        ->toBe('http://gallery.example.test/piwigo/');
});

test('parseSectionUrl advances nextToken past the tags token itself before scanning for tag identifiers', function (): void {
    // A chronology-prefixed token right after 'tags' stops the tag-scanning
    // loop on its very first check, leaving both requested-id arrays empty
    // -- badRequest() throws before TagService/the DB are ever touched,
    // same as the "rejects a bare tags token" test above. What this test
    // actually isolates is the by-ref $i value left behind: it must reflect
    // nextToken having advanced past the 'tags' token itself (to 1), not an
    // un-advanced or wrong-direction value.
    $service = UrlServiceTestFactory::build(new UrlServiceTestHtmlRenderer());
    $i = 0;

    try {
        $service->parseSectionUrl(['tags', 'created-monthly-2026'], $i, new UrlServiceTestRedirectService());
    } catch (RuntimeException) {
        // Expected -- see comment above.
    }

    expect($i)
        ->toBe(1);
});
