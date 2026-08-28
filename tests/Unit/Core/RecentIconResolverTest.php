<?php

declare(strict_types=1);

use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\Projection\RecentIcon;
use Piwigo\Core\RecentIconResolver;
use Piwigo\Lang\Translator;
use Piwigo\Tests\Support\HtmlServiceTestFactory;

/**
 * Piwigo\Core\RecentIconResolver -- "recent" badge/icon computation. No
 * dedicated Integration/Browser spec of its own.
 *
 * Every real test here pre-seeds `ProcessCache`'s own `get_icon`
 * accumulator with a `title`/`sql_recent_date` pair, which skips both
 * the real `Lang::t()` call and the real `SqlDialectExecutor`/DB round
 * trip entirely (both of `getIcon()`'s own `! isset(...)` guards) --
 * proven by using deliberately unrealistic sentinel values a real
 * Lang::t()/DB call could never produce. A bare `Lang` (no Kernel boot)
 * is safe here since `->t()` is never actually reached.
 */
function recentIconResolverTestLang(): Lang
{
    $currentConfig = new CurrentConfig();

    return new Lang(new Translator($currentConfig, new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

test('getIcon returns null for an empty date string', function (): void {
    $result = RecentIconResolver::getIcon('', 7, new ProcessCache(), recentIconResolverTestLang());

    expect($result)
        ->toBeNull();
});

test('getIcon returns null for a "0" date string', function (): void {
    $result = RecentIconResolver::getIcon('0', 7, new ProcessCache(), recentIconResolverTestLang());

    expect($result)
        ->toBeNull();
});

test('getIcon returns the icon with the cached title when the date is already cached as recent', function (): void {
    $processCache = new ProcessCache();
    $processCache->set('get_icon', [
        'title' => 'SENTINEL-CACHED-TITLE',
        '2026-01-01 00:00:00' => true,
    ]);

    $result = RecentIconResolver::getIcon('2026-01-01 00:00:00', 7, $processCache, recentIconResolverTestLang(), isChildDate: true);

    // toEqual, not toBe: getIcon() returns a fresh RecentIcon per call, so
    // identity never matches. On the array this used to return, toBe
    // compared by value and the distinction did not arise.
    expect($result)
        ->toEqual(new RecentIcon(title: 'SENTINEL-CACHED-TITLE', isChildDate: true));
});

test('getIcon returns null when the date is already cached as not recent', function (): void {
    $processCache = new ProcessCache();
    $processCache->set('get_icon', [
        'title' => 'SENTINEL-CACHED-TITLE',
        '2020-01-01 00:00:00' => false,
    ]);

    $result = RecentIconResolver::getIcon('2020-01-01 00:00:00', 7, $processCache, recentIconResolverTestLang());

    expect($result)
        ->toBeNull();
});

test('getIcon computes and caches a fresh comparison against a cached sql_recent_date cutoff', function (): void {
    $processCache = new ProcessCache();
    $processCache->set('get_icon', [
        'title' => 'SENTINEL-CACHED-TITLE',
        'sql_recent_date' => '2025-06-01 00:00:00',
    ]);

    $recentResult = RecentIconResolver::getIcon('2025-12-01 00:00:00', 7, $processCache, recentIconResolverTestLang());
    $oldResult = RecentIconResolver::getIcon('2024-01-01 00:00:00', 7, $processCache, recentIconResolverTestLang());

    expect($recentResult)
        ->toEqual(new RecentIcon(title: 'SENTINEL-CACHED-TITLE', isChildDate: false))
        ->and($oldResult)
        ->toBeNull();

    $cached = $processCache->get('get_icon');
    expect($cached)
        ->toBeArray();
    if (is_array($cached)) {
        expect($cached['2025-12-01 00:00:00'])->toBeTrue()
            ->and($cached['2024-01-01 00:00:00'])->toBeFalse();
    }
});
