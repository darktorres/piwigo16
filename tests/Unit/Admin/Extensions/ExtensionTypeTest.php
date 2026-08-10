<?php

declare(strict_types=1);

use Piwigo\Admin\PluginLoader;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\ActivitySystem;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

beforeEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::boot(Paths::fromRoot('/tmp/piwigo-extension-type-test'));
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

test('table returns each type\'s own table', function (): void {
    expect(ExtensionType::Plugin->table())->toBe('plugins')
        ->and(ExtensionType::Theme->table())->toBe('themes')
        ->and(ExtensionType::Language->table())->toBe('languages');
});

test('pemCategoryId returns each type\'s own pem category id', function (): void {
    expect(ExtensionType::Plugin->pemCategoryId(CurrentConfigTestFactory::get()))->toBe(CurrentConfigTestFactory::get()->pemPluginsCategory)
        ->and(ExtensionType::Theme->pemCategoryId(CurrentConfigTestFactory::get()))->toBe(CurrentConfigTestFactory::get()->pemThemesCategory)
        ->and(ExtensionType::Language->pemCategoryId(CurrentConfigTestFactory::get()))->toBe(CurrentConfigTestFactory::get()->pemLanguagesCategory);
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
    // Piwigo\Admin\PluginLoader::pluginsPath() is the canonical value for
    // the Plugin type's scan directory.
    expect(ExtensionType::Plugin->scanDirectory(CurrentPathsTestFactory::get(), CurrentConfigTestFactory::get()))->toBe(PluginLoader::pluginsPath(CurrentPathsTestFactory::get()))
        ->and(ExtensionType::Theme->scanDirectory(CurrentPathsTestFactory::get(), CurrentConfigTestFactory::get()))->toBe(CurrentConfigTestFactory::get()->themesPath)
        ->and(ExtensionType::Language->scanDirectory(CurrentPathsTestFactory::get(), CurrentConfigTestFactory::get()))->toBe(CurrentPathsTestFactory::get()->root . 'language/');
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
