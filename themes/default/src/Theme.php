<?php

declare(strict_types=1);

namespace Piwigo\Theme\DefaultTheme;

use Closure;
use Piwigo\PluginConfig\ExtensionContext;
use Piwigo\PluginConfig\ExtensionInterface;

/**
 * ExtensionInterface implementation for Piwigo's own built-in default
 * theme (themes/default/) -- distinct from the 3 externally-sourced
 * bundled themes (elegant/modus/smartpocket). Needed so
 * Admin\Install\InstallService::activateCoreThemes() (runs on every
 * fresh install) keeps working: ExtensionLifecycle::
 * performThemeAction()'s 'activate' case retargets onto
 * ThemeRegistry::activate(), which requires a real, schema-valid
 * theme.json for every theme it activates -- a bare themeconf.inc.php
 * header alone is not enough.
 */
final class Theme implements ExtensionInterface
{
    #[\Override]
    public function boot(ExtensionContext $context): void {}

    #[\Override]
    public function install(): void {}

    #[\Override]
    public function activate(): void {}

    #[\Override]
    public function deactivate(): void {}

    #[\Override]
    public function uninstall(): void {}

    #[\Override]
    public function update(string $oldVersion, string $newVersion): void {}

    /**
     * @return array<class-string, Closure|list<Closure>>
     */
    #[\Override]
    public function subscribedEvents(): array
    {
        return [];
    }
}
