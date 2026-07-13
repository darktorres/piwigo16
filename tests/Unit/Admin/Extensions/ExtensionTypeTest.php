<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\Config;
use Piwigo\Core\ActivitySystem;
use Piwigo\Db\Tables;

beforeEach(function (): void {
    Config::reset();

    // scanDirectory()'s Plugin branch reads PHPWG_PLUGINS_PATH, normally
    // defined by include/functions_plugins.inc.php during full app
    // bootstrap -- this Unit test doesn't load that, so define it
    // defensively (guarded, same precedent as ExtensionLifecycleTest's
    // identical stub, since define() is process-wide and Pest test files
    // can share a process).
    if (! defined('PHPWG_PLUGINS_PATH')) {
        define('PHPWG_PLUGINS_PATH', PHPWG_ROOT_PATH . 'plugins/');
    }
});

afterEach(function (): void {
    Config::reset();
});

test('table returns each type\'s own table', function (): void {
    expect(ExtensionType::Plugin->table())->toBe(Tables::plugins())
        ->and(ExtensionType::Theme->table())->toBe(Tables::themes())
        ->and(ExtensionType::Language->table())->toBe(Tables::languages());
});

test('configCategoryKey returns each type\'s own pem category conf key', function (): void {
    expect(ExtensionType::Plugin->configCategoryKey())->toBe('pem_plugins_category')
        ->and(ExtensionType::Theme->configCategoryKey())->toBe('pem_themes_category')
        ->and(ExtensionType::Language->configCategoryKey())->toBe('pem_languages_category');
});

test('scanDirectory returns each type\'s own filesystem root', function (): void {
    expect(ExtensionType::Plugin->scanDirectory())->toBe(PHPWG_PLUGINS_PATH)
        ->and(ExtensionType::Theme->scanDirectory())->toBe(Config::themesPath())
        ->and(ExtensionType::Language->scanDirectory())->toBe(PHPWG_ROOT_PATH . 'language/');
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
