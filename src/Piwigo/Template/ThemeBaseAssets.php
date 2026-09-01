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
 * and never calls `localCssRules()` (a separate, real asymmetry -- see
 * that method's own docblock). `default`/`standard_pages` share the
 * theme-chain CSS loop's shape exactly, but differ in their own
 * `utilities.css` path. One class with 3 named methods, not a false
 * single shared shape forced across all 3 -- matches this campaign's
 * own "don't bundle genuinely independent concerns" reasoning
 * elsewhere.
 *
 * None of the 3 register `jquery` any more (P49-C): with plupload's
 * native port (`vendor/uploadQueue.ts`) landing, every real first-party
 * and vendored jQuery/jQuery-UI/datatables.net consumer in the whole
 * app is gone -- confirmed via a repo-wide grep for `jQuery(`/bare
 * `$(`/`.dataTable(`/`.pluploadQueue(`/`.size(`/`.enableShiftClick(`,
 * every one either absent or comment-only. jQuery itself was loaded
 * completely unconditionally here on every single page regardless of
 * whether that page's own scripts needed it, so removing it doesn't
 * need any per-page opt-out -- it just stops.
 *
 * The `page-data` script registration (P42-B) is identical, static,
 * and unconditional across all 3 real `layout.latte` files, but is
 * deliberately NOT folded in here -- unlike every other entry, its
 * original imperative call sat at the very tail of each
 * `layout.latte` (right before `{=getPageDataScript()}`), executing
 * only after every nested partial (menubar, thumbnails, ...) already
 * registered its own same-priority scripts. Registering it here, at
 * theme-init time, would insert it *first* among same-priority ties
 * instead of last, reordering `PageAssets::resolveScripts()`'s stable
 * tie-break (`docs/PLAN.md`'s P42 "real ordering risk" section) and
 * breaking golden-html byte-identity. `Template::finalizeHtml()`
 * registers `page-data` itself, immediately before the one real
 * `resolveScripts()` call, which is the correct last-insertion
 * point -- see that method's own comment. `admin`'s own `footer`
 * registration sat at that identical tail position too, so it's
 * pulled into `lateAdminScripts()` below instead of
 * `forAdminLayout()` -- same reasoning, same fix, not folded in
 * eagerly. Its 2 `exposeData()` calls
 * (`whats_new_major_version`/`show_whats_new`) sat there as well
 * (right alongside the old `jquery.tipTip` registration, removed in
 * P49-B group 2 once `footer.ts` started importing its tooltip
 * conversion directly rather than depending on a runtime script
 * load) -- also moved to a late call,
 * from `AdminShell::runDispatch()` itself (after
 * `Renderer::render()` returns, before `finalizeHtml()`), since
 * they're genuinely per-request data with no page-level View to
 * attach `ExposesPageData` to (the shell layout itself is never a
 * `Renderer::render()` target) and only `AdminShell` has the real
 * values to expose.
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
     * @param list<ThemeChainEntry> $themes `ThemeChainResolution::$themes`,
     *   parent-first.
     * @return list<AssetContribution>
     */
    public static function forAdminLayout(array $themes): array
    {
        $assets = [
            AssetContribution::css('themes/admin/default/fontello/css/fontello.css', order: -10),
            AssetContribution::css('themes/admin/default/css/utilities.css', order: -5),
        ];

        foreach ($themes as $theme) {
            if (! $theme->loadCss) {
                continue;
            }

            $assets[] = AssetContribution::css('themes/admin/' . $theme->id . '/theme.css', order: -10);
            $assets[] = AssetContribution::css('themes/admin/' . $theme->id . '/css/components/general.css', order: -9);
        }

        return $assets;
    }

    /**
     * `footer` -- admin-only, and, like `page-data` above, deliberately
     * excluded from `forAdminLayout()`'s own eager theme-init
     * registration for the identical reason: it sat at the very tail of
     * `layout.latte` originally, after every page-specific script
     * already registered. `Template::finalizeHtml()` calls this
     * alongside its own `page-data` registration, for admin layouts
     * only, at that same last-insertion point.
     *
     * `footer` (`themes/admin/default/js/footer.ts`) stays a real
     * standalone script tag here, not folded into any per-page bundle
     * (docs/PLAN.md's P48, footer.ts's own catalog line -- see that
     * file's own leading comment for the full reasoning): this method's
     * whole point is a page-agnostic, centrally-ordered late injection,
     * which a per-page import would have to re-derive per View
     * instead, for a file with zero real exports to gain from module
     * conversion. Its own `dependsOn: ['jquery.tipTip']` was dropped in
     * P49-B group 2: `footer.ts` now imports the ported tooltip
     * (`themes/default/js/vendor/tiptip.ts`) directly, a real ES-module
     * dependency the bundler already orders correctly, rather than a
     * runtime `<script>`-tag load order this registration had to spell
     * out.
     *
     * @return list<AssetContribution>
     */
    public static function lateAdminScripts(): array
    {
        return [
            AssetContribution::script('footer', 'themes/admin/default/js/footer.ts', loadMode: LoadMode::Footer),
        ];
    }

    /**
     * @param list<ThemeChainEntry> $themes
     * @return list<AssetContribution>
     */
    public static function forDefaultLayout(array $themes): array
    {
        $assets = self::themeChainCss($themes);
        $assets[] = AssetContribution::css('themes/default/css/utilities.css', order: -5);

        return $assets;
    }

    /**
     * @param list<ThemeChainEntry> $themes
     * @return list<AssetContribution>
     */
    public static function forStandardPagesLayout(array $themes): array
    {
        $assets = self::themeChainCss($themes);
        $assets[] = AssetContribution::css('themes/standard_pages/css/utilities.css', order: -5);

        return $assets;
    }

    /**
     * The `default`/`standard_pages` layouts' shared theme-chain CSS loop
     * -- `admin`'s own loop additionally registers `components/general.css`
     * per theme, so it isn't factored in here.
     *
     * @param list<ThemeChainEntry> $themes
     * @return list<AssetContribution>
     */
    private static function themeChainCss(array $themes): array
    {
        $assets = [];
        foreach ($themes as $theme) {
            if (! $theme->loadCss) {
                continue;
            }

            $assets[] = AssetContribution::css('themes/' . $theme->id . '/theme.css', order: -10);
        }

        return $assets;
    }
}
