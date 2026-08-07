<?php

declare(strict_types=1);

use Piwigo\Core\Lang;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Controller\Admin\IntroSubController;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * Piwigo\Controller\Admin\IntroSubController::getLatestNews() -- had zero
 * coverage (see /home/torres/.claude/plans/piped-enchanting-spark.md, Wave
 * 1). Private, so reached via ReflectionMethod; the live-fetch branch
 * (HttpClientService::fetch() against the real piwigo.org news endpoint) is
 * deliberately NOT exercised here -- an automated test making a real
 * outbound HTTP call to a third-party service would be flaky by
 * construction (network availability, endpoint uptime). Instead these
 * tests pre-seed the on-disk cache file at the exact path the method
 * itself computes (CurrentPathsTestFactory::get()->root . dataLocation() . 'cache/
 * piwigo_latest_news-' . langCode . '.cache.php'), which is what the
 * method reads whenever that cache is still fresh (< 24h old) --
 * deterministically exercising the real cache-hit + unserialize() path.
 */
function intronewsCachePath(): string
{
    $langCode = LangTestFactory::get()->langInfo()['code'] ?? null;
    $langCode = is_string($langCode) ? $langCode : '';

    return CurrentPathsTestFactory::get()->root . CurrentConfigTestFactory::get()->dataLocation()
        . 'cache/piwigo_latest_news-' . $langCode . '.cache.php';
}

function intronewsInvoke(): mixed
{
    $method = new ReflectionMethod(IntroSubController::class, 'getLatestNews');

    return $method->invoke(null, LangTestFactory::get(), CurrentConfigTestFactory::get(), CurrentPathsTestFactory::get());
}

beforeEach(function (): void {
    // CurrentPaths is a shared, process-wide static -- explicitly (re-)set
    // it here rather than relying on some earlier-run Integration test
    // file (e.g. IntegrationTestCase::setUp()) to have already done so.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3)));
});

afterEach(function (): void {
    // @ doesn't suppress this from PHPUnit's own warning collector -- most
    // tests here never create the cache file at all, so "already gone" is
    // the common case, not a bug.
    set_error_handler(static fn (): bool => true);
    try {
        unlink(intronewsCachePath());
    } finally {
        restore_error_handler();
    }
    // Without this, Kernel stays booted (with this file's own root) for
    // every later test in this shared process -- a real cross-file leak
    // found via composer test's own full-suite run.
    Kernel::reset();
});

test('getLatestNews reads a fresh cache file without hitting the network', function (): void {
    $path = intronewsCachePath();
    $cacheDir = dirname($path);
    if (! is_dir($cacheDir)) {
        mkdir($cacheDir, 0o777, true);
    }
    $news = [
        'id' => 123,
        'subject' => 'Integration Test News Subject',
        'posted_on' => time() - 3600,
        'posted' => 'a few seconds ago',
        'url' => 'https://example.test/news/123',
    ];
    file_put_contents($path, serialize($news));
    touch($path, time());

    $result = intronewsInvoke();

    if (! is_array($result)) {
        throw new RuntimeException('Expected getLatestNews() to return an array: ' . var_export($result, true));
    }
    expect($result['id'])->toBe(123);
    expect($result['subject'])->toBe('Integration Test News Subject');
    expect($result['url'])->toBe('https://example.test/news/123');
});

test('getLatestNews returns null when the fresh cache holds a serialized null (no news available)', function (): void {
    $path = intronewsCachePath();
    $cacheDir = dirname($path);
    if (! is_dir($cacheDir)) {
        mkdir($cacheDir, 0o777, true);
    }
    file_put_contents($path, serialize(null));
    touch($path, time());

    $result = intronewsInvoke();

    expect($result)->toBeNull();
});

test('getLatestNews attempts a live fetch and returns an empty array when the cache is stale and the upstream host is unreachable', function (): void {
    // No cache file at all (is_file() false) -- the simplest way to force
    // the "stale" branch without racing a real 24h mtime boundary.
    // AppInfo::URL points at 'upstream.example.invalid' (RFC 2606 --
    // guaranteed never to resolve, see AppInfo::DOMAIN's own docblock), so
    // HttpClientService::fetch() fails fast and deterministically here (a
    // DNS/transport failure, not a flaky real piwigo.org round trip) --
    // the same "fork-safe PEM domain never resolves" property this
    // project's own HttpClientServiceTest.php / ExtensionUpdateCheckerTest.php /
    // PiwigoInfosSenderTest.php already rely on for the identical class of
    // "talks to piwigo.org via the static, non-injectable
    // HttpClientService::fetch()" code. Real exercise of getLatestNews()'s
    // own `$content !== false` === false branch (the `else { return []; }`),
    // not a mock of HttpClientService or of IntroSubController itself.
    //
    // The *opposite* branch (a successful fetch: JSON decode, the $news
    // array build, and the on-disk cache write) has no reachable path in
    // this fork at all -- HttpClientService::fetch() is a bare static call
    // with no injectable client seam (confirmed via HttpClientServiceTest.php's
    // own "guardedFetch() ... deliberately NOT chased here" note: every
    // caller of fetch()/fetchToFile() always constructs `new self(...)`
    // internally against the hardcoded real defaultClient(), with no
    // parameter anywhere in the static call chain to substitute a
    // MockHttpClient), and the one real network target it can ever reach
    // from here (AppInfo::URL) is deliberately, permanently non-resolving.
    // Left uncovered rather than faked.
    $path = intronewsCachePath();
    // @ doesn't suppress this from PHPUnit's own warning collector -- the
    // cache file genuinely not existing yet is the expected, common case
    // here (this is defensive pre-test cleanup, not an assertion that a
    // prior run left one behind).
    set_error_handler(static fn (): bool => true);
    try {
        unlink($path);
    } finally {
        restore_error_handler();
    }

    $result = intronewsInvoke();

    expect($result)->toBe([]);
});
