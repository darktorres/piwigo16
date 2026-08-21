<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Asset\PageAssets;
use Piwigo\Asset\ViteManifest;
use Piwigo\Core\Paths;

/**
 * No real Vite entry needed for most of these -- an empty manifest (a
 * fresh temp root with no dist/.vite/manifest.json) makes
 * PageAssets::resolvePath() fall through to the raw path every time,
 * which is what lets these tests assert on real, readable paths
 * instead of hashed ones.
 */
function pageAssetsTestManifest(): ViteManifest
{
    return new ViteManifest(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-page-assets-test-empty-' . bin2hex(random_bytes(8))));
}

/**
 * @param list<string> $haystack
 */
function pageAssetsTestIndexOf(array $haystack, string $needle): int
{
    $index = array_search($needle, $haystack, true);
    if ($index === false) {
        throw new RuntimeException("'{$needle}' not found in resolved asset list");
    }

    return $index;
}

test('resolveCss() sorts by order, real range found in templates (-999 to 100)', function (): void {
    $assets = new PageAssets(pageAssetsTestManifest());
    $assets->add(AssetContribution::css('themes/default/css/search.css', order: -100));
    $assets->add(AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10));
    $assets->add(AssetContribution::css('themes/default/js/ui/theme/jquery.ui.slider.css', order: -999));
    $assets->add(AssetContribution::css('themes/default/theme.css', order: 0));

    $paths = array_map(fn ($r) => $r->path, $assets->resolveCss());

    expect($paths)
        ->toBe([
            'themes/default/js/ui/theme/jquery.ui.slider.css',
            'themes/default/css/search.css',
            'themes/default/theme.css',
            'themes/admin/default/fontello/css/animation.css',
        ]);
});

test('resolveCss() preserves registration order for equal order values', function (): void {
    $assets = new PageAssets(pageAssetsTestManifest());
    $assets->add(AssetContribution::css('a.css', id: 'a'));
    $assets->add(AssetContribution::css('b.css', id: 'b'));
    $assets->add(AssetContribution::css('c.css', id: 'c'));

    $paths = array_map(fn ($r) => $r->path, $assets->resolveCss());

    expect($paths)
        ->toBe(['a.css', 'b.css', 'c.css']);
});

test('css dedupes by id, keeping the higher order', function (): void {
    $assets = new PageAssets(pageAssetsTestManifest());
    $assets->add(AssetContribution::css('v1.css', id: 'shared', order: 5));
    $assets->add(AssetContribution::css('v2.css', id: 'shared', order: 10));

    $paths = array_map(fn ($r) => $r->path, $assets->resolveCss());

    expect($paths)
        ->toBe(['v2.css']);
});

test('css dedupes by id, keeping the higher version on an order tie', function (): void {
    $assets = new PageAssets(pageAssetsTestManifest());
    $assets->add(AssetContribution::css('old.css', id: 'shared', version: '1.0'));
    $assets->add(AssetContribution::css('new.css', id: 'shared', version: '2.0'));

    $paths = array_map(fn ($r) => $r->path, $assets->resolveCss());

    expect($paths)
        ->toBe(['new.css']);
});

test('resolveScripts() partitions header before footer before async', function (): void {
    $assets = new PageAssets(pageAssetsTestManifest());
    $assets->add(AssetContribution::script('async-one', 'async.js', loadMode: LoadMode::Async));
    $assets->add(AssetContribution::script('footer-one', 'footer.js', loadMode: LoadMode::Footer));
    $assets->add(AssetContribution::script('header-one', 'header.js', loadMode: LoadMode::Header));

    $paths = array_map(fn ($r) => $r->path, $assets->resolveScripts());

    expect($paths)
        ->toBe(['header.js', 'footer.js', 'async.js']);
});

test('resolveScripts() orders a dependency before its dependent, real multi-level chain', function (): void {
    // Real chain found live in datepicker.inc.latte: timepicker-addon
    // requires datepicker and slider; datepicker is never registered
    // explicitly anywhere -- only ever reached via this require: chain.
    $assets = new PageAssets(pageAssetsTestManifest());
    $assets->add(AssetContribution::script(
        'jquery.ui.timepicker-addon',
        'themes/default/js/ui/jquery.ui.timepicker-addon.js',
        dependsOn: ['jquery.ui.datepicker', 'jquery.ui.slider'],
    ));

    $paths = array_map(fn ($r) => $r->path, $assets->resolveScripts());

    // jquery + jquery.ui load once (shared by both branches), followed by
    // jquery.ui.widget/position/mouse (jquery.ui.mouse's own real deps),
    // then datepicker and slider (siblings, either order), then the
    // dependent addon last -- 8 total: the 5 shared jQuery-UI core deps
    // + datepicker + slider + the addon itself.
    $addonIndex = pageAssetsTestIndexOf($paths, 'themes/default/js/ui/jquery.ui.timepicker-addon.js');

    expect($paths)
        ->toHaveCount(8)
        ->and(pageAssetsTestIndexOf($paths, 'themes/default/js/jquery.min.js'))
        ->toBe(0)
        ->and(pageAssetsTestIndexOf($paths, 'themes/default/js/ui/minified/jquery.ui.core.min.js'))
        ->toBeGreaterThan(0)
        ->and(pageAssetsTestIndexOf($paths, 'themes/default/js/ui/minified/jquery.ui.datepicker.min.js'))
        ->toBeLessThan($addonIndex)
        ->and(pageAssetsTestIndexOf($paths, 'themes/default/js/ui/minified/jquery.ui.slider.min.js'))
        ->toBeLessThan($addonIndex)
        ->and(end($paths))
        ->toBe('themes/default/js/ui/jquery.ui.timepicker-addon.js');
});

test('resolveScripts() resolves jquery.ui.tooltip from a zero-param registration, real rating_user.latte case', function (): void {
    $assets = new PageAssets(pageAssetsTestManifest());
    $assets->add(AssetContribution::script('jquery.ui.tooltip', ''));

    $paths = array_map(fn ($r) => $r->path, $assets->resolveScripts());

    expect($paths)
        ->toContain('themes/default/js/ui/minified/jquery.ui.tooltip.min.js')
        ->and($paths)
        ->toContain('themes/default/js/jquery.min.js')
        ->and($paths)
        ->toContain('themes/default/js/ui/minified/jquery.ui.core.min.js');
});

test('resolveScripts() resolves jquery.ui.effect-blind, real updates_ext.latte/plugins_new.latte case', function (): void {
    $assets = new PageAssets(pageAssetsTestManifest());
    $assets->add(AssetContribution::script('pluginsNew', 'themes/admin/default/js/plugins_new.js', dependsOn: ['jquery.ui.effect-blind', 'jquery.sort']));

    $paths = array_map(fn ($r) => $r->path, $assets->resolveScripts());

    expect($paths)
        ->toContain('themes/default/js/ui/minified/jquery.ui.effect-blind.min.js')
        ->and($paths)
        ->toContain('themes/default/js/ui/minified/jquery.ui.effect.min.js')
        ->and(pageAssetsTestIndexOf($paths, 'themes/default/js/ui/minified/jquery.ui.effect-blind.min.js'))
        ->toBeLessThan(pageAssetsTestIndexOf($paths, 'themes/admin/default/js/plugins_new.js'));
});

test('scripts dedupe by id, unioning dependsOn and promoting to the more eager load mode', function (): void {
    $assets = new PageAssets(pageAssetsTestManifest());
    $assets->add(AssetContribution::script('shared', 'shared.js', loadMode: LoadMode::Async, dependsOn: ['core.scripts']));
    $assets->add(AssetContribution::script('shared', 'shared.js', loadMode: LoadMode::Header, dependsOn: ['jquery']));

    $resolved = $assets->resolveScripts();

    expect($resolved)
        ->toHaveCount(3) // shared + its two now-merged deps
        ->and($resolved[0]->loadMode)->toBe(LoadMode::Header);
});

test('a dependency is promoted to its dependent\'s stricter load mode, real ScriptLoader::checkLoadDep() behavior', function (): void {
    $assets = new PageAssets(pageAssetsTestManifest());
    // Registered async first, exactly as ScriptLoader::add()'s own
    // "try to load undefined required script" path would encounter it.
    $assets->add(AssetContribution::script('dep', 'dep.js', loadMode: LoadMode::Async));
    $assets->add(AssetContribution::script('main', 'main.js', loadMode: LoadMode::Footer, dependsOn: ['dep']));

    $paths = array_map(fn ($r) => $r->path, $assets->resolveScripts());

    // Both must render in the SAME (footer) group, dep before main --
    // if dep were left async it would land in a separate, later group.
    expect($paths)
        ->toBe(['dep.js', 'main.js']);
});

test('an async dependency of an async dependent is promoted to footer, real ScriptLoader::checkLoadDep() behavior', function (): void {
    $assets = new PageAssets(pageAssetsTestManifest());
    // Both ends async -- the strict `>` check alone can't see this case
    // (2 > 2 is false), but two async tags have no guaranteed relative
    // execution order, so the dependency still has to be promoted.
    $assets->add(AssetContribution::script('dep', 'dep.js', loadMode: LoadMode::Async));
    $assets->add(AssetContribution::script('main', 'main.js', loadMode: LoadMode::Async, dependsOn: ['dep']));

    $resolved = $assets->resolveScripts();
    $paths = array_map(fn ($r) => $r->path, $resolved);

    // Only the dependency needs promoting (matching real
    // ScriptLoader::checkLoadDep() behavior, which only ever demoted
    // the precedent) -- the footer group's own synchronous scripts
    // always finish executing before the async-loader IIFE (itself
    // footer-positioned) even starts creating <script async> tags, so
    // 'main' can safely stay async once 'dep' is guaranteed synchronous.
    expect($resolved[0]->loadMode)->toBe(LoadMode::Footer)
        ->and($resolved[1]->loadMode)->toBe(LoadMode::Async)
        ->and($paths)
        ->toBe(['dep.js', 'main.js']);
});

test('circular script dependencies throw rather than looping forever', function (): void {
    $assets = new PageAssets(pageAssetsTestManifest());
    $assets->add(AssetContribution::script('a', 'a.js', dependsOn: ['b']));
    $assets->add(AssetContribution::script('b', 'b.js', dependsOn: ['a']));

    expect(fn () => $assets->resolveScripts())
        ->toThrow(LogicException::class);
});

test('resolvePath() uses the real ViteManifest entry when one exists, falls back to the raw path otherwise', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-page-assets-test-real-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root . 'dist/.vite', 0o777, true);
    file_put_contents($root . 'dist/.vite/manifest.json', json_encode([
        'build/vitals.ts' => [
            'file' => 'vitals.js',
            'isEntry' => true,
            'css' => ['assets/vitals-abc123.css'],
        ],
    ], JSON_THROW_ON_ERROR));

    $assets = new PageAssets(new ViteManifest(Paths::fromRoot($root)));
    $assets->add(AssetContribution::script('vitals', 'build/vitals.ts', loadMode: LoadMode::Async));
    $assets->add(AssetContribution::script('legacy', 'themes/default/js/scripts.js'));

    $scriptPaths = array_map(fn ($r) => $r->path, $assets->resolveScripts());
    $cssPaths = array_map(fn ($r) => $r->path, $assets->resolveCss());

    expect($scriptPaths)
        ->toContain('vitals.js')
        ->and($scriptPaths)
        ->toContain('themes/default/js/scripts.js')
        ->and($cssPaths)
        ->toBe(['assets/vitals-abc123.css']);
});
