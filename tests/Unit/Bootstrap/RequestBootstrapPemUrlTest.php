<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\CurrentConfigTestFactory;

/**
 * pemUrl() reads through self::currentConfig(), which resolves
 * CurrentConfig directly from Kernel::container() with no not-booted
 * fallback of its own -- unlike the CurrentConfigTestFactory::get() shim, so
 * each test below boots the Kernel first and writes onto that same
 * container-shared instance.
 */
afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

/**
 * pemUrl()'s 2 per-type overrides are read directly via getenv(), not through
 * CurrentConfig (see pemUrl()'s own docblock for why) -- putenv() to unset
 * must save+restore the real prior value, not just clear it, or a later
 * test/run in the same process silently inherits whatever this one left
 * behind.
 */
function pemUrlTestSetEnv(string $var, ?string $value): ?string
{
    $original = getenv($var);
    putenv($value === null ? $var : $var . '=' . $value);

    return $original === false ? null : $original;
}

function pemUrlTestRestoreEnv(string $var, ?string $original): void
{
    putenv($original === null ? $var : $var . '=' . $original);
}

test('pemUrl returns the alternative PEM URL when one is configured', function (): void {
    Kernel::boot();
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }
    $currentConfig->alternativePemUrl = 'https://pem.example.test/mirror';

    expect(RequestBootstrap::pemUrl())->toBe('https://pem.example.test/mirror');
});

test('pemUrl falls back to AppInfo::URL . "/ext" when no alternative is configured', function (): void {
    Kernel::boot();
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }
    $currentConfig->alternativePemUrl = '';

    expect(RequestBootstrap::pemUrl())->toBe(AppInfo::URL . '/ext');
});

test('pemUrl(Plugin) returns the per-type env override when set, ahead of the generic alternativePemUrl', function (): void {
    Kernel::boot();
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }
    $currentConfig->alternativePemUrl = 'https://pem.example.test/mirror';

    $original = pemUrlTestSetEnv('PIWIGO_ALT_PLUGINS_PEM_URL', 'http://127.0.0.1:9999/piwigo16-plugins');
    try {
        expect(RequestBootstrap::pemUrl(ExtensionType::Plugin))->toBe('http://127.0.0.1:9999/piwigo16-plugins')
            // A bare call (no $type) is unaffected -- still resolves the
            // single generic override, proving the 2 mechanisms don't
            // cross-contaminate.
            ->and(RequestBootstrap::pemUrl())->toBe('https://pem.example.test/mirror');
    } finally {
        pemUrlTestRestoreEnv('PIWIGO_ALT_PLUGINS_PEM_URL', $original);
    }
});

test('pemUrl(Theme) returns its own, independent per-type env override', function (): void {
    Kernel::boot();

    $originalPlugins = pemUrlTestSetEnv('PIWIGO_ALT_PLUGINS_PEM_URL', 'http://127.0.0.1:9998/piwigo16-plugins');
    $originalThemes = pemUrlTestSetEnv('PIWIGO_ALT_THEMES_PEM_URL', 'http://127.0.0.1:9997/piwigo16-themes');
    try {
        expect(RequestBootstrap::pemUrl(ExtensionType::Plugin))->toBe('http://127.0.0.1:9998/piwigo16-plugins')
            ->and(RequestBootstrap::pemUrl(ExtensionType::Theme))->toBe('http://127.0.0.1:9997/piwigo16-themes');
    } finally {
        pemUrlTestRestoreEnv('PIWIGO_ALT_PLUGINS_PEM_URL', $originalPlugins);
        pemUrlTestRestoreEnv('PIWIGO_ALT_THEMES_PEM_URL', $originalThemes);
    }
});

test('pemUrl(Plugin) falls through to the generic alternativePemUrl, then the real default, when its own env var is unset', function (): void {
    Kernel::boot();
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }

    $original = pemUrlTestSetEnv('PIWIGO_ALT_PLUGINS_PEM_URL', null);
    try {
        $currentConfig->alternativePemUrl = 'https://pem.example.test/mirror';
        expect(RequestBootstrap::pemUrl(ExtensionType::Plugin))->toBe('https://pem.example.test/mirror');

        $currentConfig->alternativePemUrl = '';
        expect(RequestBootstrap::pemUrl(ExtensionType::Plugin))->toBe(AppInfo::URL . '/ext');
    } finally {
        pemUrlTestRestoreEnv('PIWIGO_ALT_PLUGINS_PEM_URL', $original);
    }
});

test('pemUrl(Language) returns its own, independent per-type env override, unaffected by the Plugin/Theme ones', function (): void {
    Kernel::boot();

    $originalPlugins = pemUrlTestSetEnv('PIWIGO_ALT_PLUGINS_PEM_URL', 'http://127.0.0.1:9996/piwigo16-plugins');
    $originalThemes = pemUrlTestSetEnv('PIWIGO_ALT_THEMES_PEM_URL', 'http://127.0.0.1:9995/piwigo16-themes');
    $originalLanguages = pemUrlTestSetEnv('PIWIGO_ALT_LANGUAGES_PEM_URL', 'http://127.0.0.1:9994/piwigo16-languages');
    try {
        expect(RequestBootstrap::pemUrl(ExtensionType::Language))->toBe('http://127.0.0.1:9994/piwigo16-languages')
            ->and(RequestBootstrap::pemUrl(ExtensionType::Plugin))->toBe('http://127.0.0.1:9996/piwigo16-plugins')
            ->and(RequestBootstrap::pemUrl(ExtensionType::Theme))->toBe('http://127.0.0.1:9995/piwigo16-themes');
    } finally {
        pemUrlTestRestoreEnv('PIWIGO_ALT_PLUGINS_PEM_URL', $originalPlugins);
        pemUrlTestRestoreEnv('PIWIGO_ALT_THEMES_PEM_URL', $originalThemes);
        pemUrlTestRestoreEnv('PIWIGO_ALT_LANGUAGES_PEM_URL', $originalLanguages);
    }
});

test('pemUrl(Language) falls through to the generic alternativePemUrl, then the real default, when its own env var is unset', function (): void {
    Kernel::boot();
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }

    $original = pemUrlTestSetEnv('PIWIGO_ALT_LANGUAGES_PEM_URL', null);
    try {
        $currentConfig->alternativePemUrl = 'https://pem.example.test/mirror';
        expect(RequestBootstrap::pemUrl(ExtensionType::Language))->toBe('https://pem.example.test/mirror');

        $currentConfig->alternativePemUrl = '';
        expect(RequestBootstrap::pemUrl(ExtensionType::Language))->toBe(AppInfo::URL . '/ext');
    } finally {
        pemUrlTestRestoreEnv('PIWIGO_ALT_LANGUAGES_PEM_URL', $original);
    }
});
