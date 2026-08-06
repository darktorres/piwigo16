<?php

declare(strict_types=1);

use Piwigo\Admin\PluginLoader;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\Tables;

beforeEach(function (): void {
    CurrentConfig::current()->reset();
    Kernel::boot(Paths::fromRoot('/tmp/piwigo-extension-type-test'));
});

afterEach(function (): void {
    CurrentConfig::current()->reset();
    Kernel::reset();
});

test('table returns each type\'s own table', function (): void {
    expect(ExtensionType::Plugin->table())->toBe(Tables::plugins())
        ->and(ExtensionType::Theme->table())->toBe(Tables::themes())
        ->and(ExtensionType::Language->table())->toBe(Tables::languages());
});

test('pemCategoryId returns each type\'s own pem category id', function (): void {
    expect(ExtensionType::Plugin->pemCategoryId())->toBe(CurrentConfig::current()->pemPluginsCategory())
        ->and(ExtensionType::Theme->pemCategoryId())->toBe(CurrentConfig::current()->pemThemesCategory())
        ->and(ExtensionType::Language->pemCategoryId())->toBe(CurrentConfig::current()->pemLanguagesCategory());
});

test('fromPluralWsParam maps each type\'s plural pwg.extensions.ignoreUpdate wire value back to its enum case', function (): void {
    expect(ExtensionType::fromPluralWsParam('plugins'))->toBe(ExtensionType::Plugin)
        ->and(ExtensionType::fromPluralWsParam('themes'))->toBe(ExtensionType::Theme)
        ->and(ExtensionType::fromPluralWsParam('languages'))->toBe(ExtensionType::Language);
});

test('fromPluralWsParam returns null for an unrecognized string', function (): void {
    expect(ExtensionType::fromPluralWsParam('plugin'))->toBeNull()
        ->and(ExtensionType::fromPluralWsParam(''))->toBeNull()
        ->and(ExtensionType::fromPluralWsParam('not-a-real-type'))->toBeNull();
});

test('scanDirectory returns each type\'s own filesystem root', function (): void {
    // P23 batch 8f-4: the PHPWG_PLUGINS_PATH define is gone --
    // Piwigo\Admin\PluginLoader::pluginsPath() is the canonical value now.
    expect(ExtensionType::Plugin->scanDirectory(CurrentPaths::get()))->toBe(PluginLoader::pluginsPath(CurrentPaths::get()))
        ->and(ExtensionType::Theme->scanDirectory(CurrentPaths::get()))->toBe(CurrentConfig::current()->themesPath())
        ->and(ExtensionType::Language->scanDirectory(CurrentPaths::get()))->toBe(CurrentPaths::get()->root . 'language/');
});

test('markerFilename returns each type\'s own extension marker file', function (): void {
    // Language uses common.po, not common.lang.php -- this rewrite migrated
    // every locale to gettext .po format (see this enum's own docblock for
    // the real bug this fixed).
    expect(ExtensionType::Plugin->markerFilename())->toBe('main.inc.php')
        ->and(ExtensionType::Theme->markerFilename())->toBe('themeconf.inc.php')
        ->and(ExtensionType::Language->markerFilename())->toBe('common.po');
});

test('defaultIds lists the bundled extensions for plugin and theme, and is empty for language', function (): void {
    expect(ExtensionType::Plugin->defaultIds())->toBe(['LocalFilesEditor', 'language_switch', 'TakeATour', 'AdminTools'])
        ->and(ExtensionType::Theme->defaultIds())->toBe(['modus', 'elegant', 'smartpocket'])
        ->and(ExtensionType::Language->defaultIds())->toBe([]);
});

test('activityType is null for language, matching the legacy classes\' own asymmetry', function (): void {
    expect(ExtensionType::Plugin->activityType())->toBe(ActivitySystem::Plugin)
        ->and(ExtensionType::Theme->activityType())->toBe(ActivitySystem::Theme)
        ->and(ExtensionType::Language->activityType())->toBeNull();
});
