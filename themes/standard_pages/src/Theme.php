<?php

declare(strict_types=1);

namespace Piwigo\Theme\StandardPages;

use Closure;
use Piwigo\PluginConfig\ExtensionContext;
use Piwigo\PluginConfig\ExtensionInterface;

/**
 * ExtensionInterface implementation for Piwigo's own built-in
 * standard_pages companion theme (themes/standard_pages/) -- never
 * separately activated via the admin UI (Admin\ThemesInstalledPageRenderer::
 * render() hardcodes excluding both 'default' and 'standard_pages' from
 * its listing; Template::setTheme() substitutes it in automatically for
 * identification/register/password/profile pages). Exists only so
 * ThemeRegistry::load()'s manifest scan -- and this theme's own
 * dependents, like Admin\Extensions\ExtensionScanner -- see
 * a real, schema-valid theme.json for it, same as every other theme in
 * this codebase: no theme directory keeps a legacy
 * themeconf.inc.php, none gets a special-cased exemption from
 * the contract either.
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
