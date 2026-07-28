<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\IntroSubController;
use Piwigo\Core\CurrentPaths;
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
 * itself computes (CurrentPaths::get()->root . dataLocation() . 'cache/
 * piwigo_latest_news-' . langCode . '.cache.php'), which is what the
 * method reads whenever that cache is still fresh (< 24h old) --
 * deterministically exercising the real cache-hit + unserialize() path.
 */
function intronewsCachePath(): string
{
    $langCode = \Piwigo\Core\Lang::langInfo()['code'] ?? null;
    $langCode = is_string($langCode) ? $langCode : '';

    return CurrentPaths::get()->root . \Piwigo\Config\CurrentConfig::dataLocation()
        . 'cache/piwigo_latest_news-' . $langCode . '.cache.php';
}

function intronewsInvoke(): mixed
{
    $method = new ReflectionMethod(IntroSubController::class, 'getLatestNews');

    return $method->invoke(null);
}

beforeEach(function (): void {
    // CurrentPaths is a shared, process-wide static -- explicitly (re-)set
    // it here rather than relying on some earlier-run Integration test
    // file (e.g. IntegrationTestCase::setUp()) to have already done so.
    CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 3)));
});

afterEach(function (): void {
    @unlink(intronewsCachePath());
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
