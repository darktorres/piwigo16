<?php

declare(strict_types=1);

namespace Piwigo\Theme\GoldenHtmlTest;

use Closure;
use Piwigo\PluginConfig\ExtensionContext;
use Piwigo\PluginConfig\ExtensionInterface;

/**
 * ExtensionInterface implementation for the golden_html_test fixture
 * theme (themes/golden_html_test/) -- not a real theme, never installed/
 * activated via the admin UI or DB. Exists only so
 * tests/Browser/GoldenHtmlSnapshotTest.php's standard-pages captures have
 * a real, schema-valid theme.json for Template::setTheme()'s
 * standard_pages fallback (and ThemeRegistry::bootCurrent(), which runs
 * for any resolved CurrentUser theme, this fixture included) to load
 * before it swaps to the real themes/standard_pages directory.
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
