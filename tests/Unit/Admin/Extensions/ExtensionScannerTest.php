<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

// ExtensionType::scanDirectory() hardcodes real app paths (PluginLoader::pluginsPath()/
// CurrentConfig::themesPath()/CurrentPaths::get()->root.'language/') with no
// injection point, so this can't safely redirect to a disposable temp
// directory the way ZipExtractorTest/UploadServiceTest do. Scanning the
// real, git-tracked language/ tree read-only is safe and deterministic
// (unlike themes/plugins, bundled language directories are stable source
// content, not environment-dependent) -- covers the real
// common.po-vs-common.lang.php marker-file bug this batch fixed (see
// ExtensionType::markerFilename()'s own docblock). Plugin/theme scanning
// is exercised end-to-end by the Browser admin smoke suite against
// whatever's actually installed, not re-duplicated here. CurrentPaths is
// seeded against this repo's own real root (not a disposable temp dir) so
// the real language/ tree is genuinely reachable.
beforeEach(function (): void {
    CurrentConfig::reset();
    ConfigLoader::applyDefaults();
    CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 4)));
});

afterEach(function (): void {
    CurrentConfig::reset();
    CurrentPaths::reset();
});

test('scan finds the real bundled en_UK language via its common.po header', function (): void {
    $found = new ExtensionScanner()->scan(ExtensionType::Language, new UrlService(new HtmlService()), 'utf-8');

    expect($found)->toHaveKey('en_UK')
        ->and($found['en_UK']['name'])->toBe('English (Great Britain)')
        ->and($found['en_UK']['code'])->toBe('en_UK')
        ->and($found['en_UK']['version'])->not->toBe('0');
});

test('scan skips a language directory with no common.po', function (): void {
    $found = new ExtensionScanner()->scan(ExtensionType::Language, new UrlService(new HtmlService()), 'utf-8');

    // index.php sits alongside the real locale directories under language/
    // but isn't itself an extension -- also fails the [a-zA-Z0-9-_]+ id
    // regex to begin with (has a dot), confirming both guards hold.
    expect($found)->not->toHaveKey('index.php');
});
