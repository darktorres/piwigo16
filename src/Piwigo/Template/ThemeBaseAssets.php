<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;

/**
 * The plain, unconditional `combineCss`/`combineScript` registrations
 * each real `layout.latte` used to make imperatively -- the first of the
 * 4 theme-base pieces (docs/PLAN.md's P42-A theme-base section).
 * Constructed fresh from `Template::setTheme()`, the same shape
 * `PageAssets`/`ThemeChain`/`TemplateLocator` already use there.
 *
 * The 3 real layout families genuinely differ, not just cosmetically --
 * confirmed by reading all 3 `layout.latte` files in full: `admin` loads
 * 2 extra unconditional stylesheets (`fontello.css`/`components/general.css`,
 * both with a literally hardcoded `admin/default/` path regardless of
 * which admin sub-theme is active -- preserved exactly, not "fixed"),
 * registers `jquery` with an explicit `path:` and no `load:` (defaults to
 * header), and never calls `localCssRules()` (a separate, real
 * asymmetry -- see that method's own docblock). `default`/`standard_pages`
 * share the theme-chain CSS loop's shape exactly, but differ in their
 * own `utilities.css` path and `jquery`'s `load:` timing (`footer` vs
 * `header`). One class with 3 named methods, not a false single shared
 * shape forced across all 3 -- matches this campaign's own "don't bundle
 * genuinely independent concerns" reasoning elsewhere.
 */
final readonly class ThemeBaseAssets
{
    /**
     * `str_ends_with($root, '/admin')`-style detection isn't done here --
     * `Template::setTheme()` (the one real caller) already knows which of
     * the 3 real layout families it's building for from its own `$root`
     * argument, so it calls the matching method directly instead of this
     * class re-deriving family identity from a string.
     *
     * @param list<array<string, mixed>> $themes `ThemeChainResolution::$themes`'s
     *   own shape -- parent-first, each entry's `id`/`load_css` keys.
     * @return list<AssetContribution>
     */
    public static function forAdminLayout(array $themes): array
    {
        $assets = [
            AssetContribution::css('themes/admin/default/fontello/css/fontello.css', order: -10),
            AssetContribution::css('themes/admin/default/css/utilities.css', order: -5),
        ];

        foreach ($themes as $theme) {
            if (($theme['load_css'] ?? false) !== true || ! is_string($theme['id'] ?? null)) {
                continue;
            }

            $id = $theme['id'];
            $assets[] = AssetContribution::css('themes/admin/' . $id . '/theme.css', order: -10);
            $assets[] = AssetContribution::css('themes/admin/' . $id . '/css/components/general.css', order: -9);
        }

        $assets[] = AssetContribution::script('jquery', 'themes/default/js/jquery.min.js');

        return $assets;
    }

    /**
     * @param list<array<string, mixed>> $themes
     * @return list<AssetContribution>
     */
    public static function forDefaultLayout(array $themes): array
    {
        $assets = self::themeChainCss($themes);
        $assets[] = AssetContribution::css('themes/default/css/utilities.css', order: -5);
        $assets[] = AssetContribution::script('jquery', path: '', loadMode: LoadMode::Footer);

        return $assets;
    }

    /**
     * @param list<array<string, mixed>> $themes
     * @return list<AssetContribution>
     */
    public static function forStandardPagesLayout(array $themes): array
    {
        $assets = self::themeChainCss($themes);
        $assets[] = AssetContribution::css('themes/standard_pages/css/utilities.css', order: -5);
        $assets[] = AssetContribution::script('jquery', path: '', loadMode: LoadMode::Header);

        return $assets;
    }

    /**
     * The `default`/`standard_pages` layouts' shared theme-chain CSS loop
     * -- `admin`'s own loop additionally registers `components/general.css`
     * per theme, so it isn't factored in here.
     *
     * @param list<array<string, mixed>> $themes
     * @return list<AssetContribution>
     */
    private static function themeChainCss(array $themes): array
    {
        $assets = [];
        foreach ($themes as $theme) {
            if (($theme['load_css'] ?? false) !== true || ! is_string($theme['id'] ?? null)) {
                continue;
            }

            $assets[] = AssetContribution::css('themes/' . $theme['id'] . '/theme.css', order: -10);
        }

        return $assets;
    }
}
