<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Closure;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\ProcessCache;

/**
 * Owns the theme parent/child chain walk and `theme.json` loading --
 * `Template` constructs one of these internally per instance (same `new
 * PageAssets(...)`/`new TemplateLocator()` shape already used in that
 * constructor), not a shared/injected collaborator (P41, docs/PLAN.md's
 * `TemplateLocator`/`ThemeChain` extraction).
 *
 * `$onStandardPagesThemeLoaded` is the one real side effect this class
 * can't compute purely: a `theme.json` literally named `standard_pages`
 * assigns 3 ambient `Template` vars (`STD_PGS_SELECTED_SKIN`/
 * `STD_PGS_SELECTED_LOGO`/`GALLERY_TITLE`) the moment it's loaded --
 * `Template::assign()` is private, so this is the one hardcoded core
 * exception threaded through as a constructor callback, fired only on a
 * genuine `ProcessCache` miss (matching `loadThemeconf()`'s own
 * cache-gated timing exactly, not once per call).
 */
final class ThemeChain
{
    public function __construct(
        private readonly ProcessCache $processCache,
        private readonly Closure $onStandardPagesThemeLoaded,
    ) {}

    /**
     * Walks `$theme`'s own parent chain (following `theme.json`'s own
     * `parent` field), returning the fully merged result in one shot --
     * `setTemplateDir()`/`assign()`'s old per-level side effects (still
     * visible in git history) are replaced by a single pre-computed
     * `ThemeChainResolution` `Template::setTheme()` applies directly.
     *
     * Directories come out child-first (matching `TemplateLocator`'s own
     * resolution order: a child theme's own file must win over its
     * parent's). `$themes` entries come out parent-first (each theme's
     * own combined-CSS load order: a parent's base stylesheet must load
     * before a child's override). `$themeconf` is one already-merged
     * array, child keys winning over parent keys -- exactly Smarty's own
     * `append(..., merge: true)` semantics this replaces, just computed
     * directly instead of via N recursive self-calls.
     */
    public function resolve(string $root, ThemeId $theme, string $path, CurrentConfig $currentConfig, bool $loadCss = true, bool $loadLocalHead = true, string $colorscheme = 'dark'): ThemeChainResolution
    {
        $dirs = [];
        $themes = [];
        $themeconfAcc = [];
        $this->walk($root, $theme, $path, $currentConfig, $loadCss, $loadLocalHead, $colorscheme, $dirs, $themes, $themeconfAcc);

        return new ThemeChainResolution($dirs, $themes, $themeconfAcc);
    }

    /**
     * @param list<string> $dirs
     * @param list<array<string, mixed>> $themes
     * @param array<string, mixed> $themeconfAcc
     */
    private function walk(string $root, ThemeId $theme, string $path, CurrentConfig $currentConfig, bool $loadCss, bool $loadLocalHead, string $colorscheme, array &$dirs, array &$themes, array &$themeconfAcc): void
    {
        // we need themeconf before std_pgs to see what themes use_standard_pages
        $themeconf = $this->loadThemeconf($root . '/' . $theme->value);

        // We loop over the theme and the parent theme, so if we exclude default,
        // standard pages can't get the header to load the html header
        if (
            $theme->value !== 'default'
            and in_array(PageFilterHelper::scriptBasename($currentConfig), ['identification', 'register', 'password', 'profile'], true)
            and ((bool) ($themeconf['use_standard_pages'] ?? false) or $currentConfig->useStandardPages)
        ) {
            $theme = ThemeId::from('standard_pages');
            $themeconf = $this->loadThemeconf($root . '/' . $theme->value);
        }

        $dirs[] = $root . '/' . $theme->value . '/' . $path;

        $parentTheme = isset($themeconf['parent']) ? ThemeId::tryFrom($themeconf['parent']) : null;
        if ($parentTheme instanceof ThemeId and $parentTheme->value !== $theme->value) {
            $loadParentCss = $themeconf['load_parent_css'] ?? $loadCss;
            $loadParentLocalHead = $themeconf['load_parent_local_head'] ?? $loadLocalHead;
            $this->walk(
                $root,
                $parentTheme,
                $path,
                $currentConfig,
                is_bool($loadParentCss) ? $loadParentCss : $loadCss,
                is_bool($loadParentLocalHead) ? $loadParentLocalHead : $loadLocalHead,
                $colorscheme,
                $dirs,
                $themes,
                $themeconfAcc,
            );
        }

        $tplVar = [
            'id' => $theme->value,
            'load_css' => $loadCss,
        ];
        if (! in_array($themeconf['local_head'] ?? null, [null, false, 0, '0', '', []], true) and $loadLocalHead and is_string($themeconf['local_head'])) {
            $tplVar['local_head'] = realpath($root . '/' . $theme->value . '/' . $themeconf['local_head']);
        }
        $themeconf['id'] = $theme->value;

        if (! isset($themeconf['colorscheme'])) {
            $themeconf['colorscheme'] = $colorscheme;
        }

        $themes[] = $tplVar;
        foreach ($themeconf as $key => $val) {
            $themeconfAcc[$key] = $val;
        }
    }

    /**
     * Returns `$dir`'s own theme parameters, cached per real directory
     * for the lifetime of `$this->processCache`. `Template::loadThemeconf()`
     * is a thin public delegate to this (kept for its own existing
     * direct test coverage) -- `setTheme()`'s own recursive walk above
     * is the one other real caller.
     *
     * @return array<string, mixed>
     */
    public function loadThemeconf(string $dir): array
    {
        $real_dir = realpath($dir);
        if ($real_dir === false) {
            // Theme directory doesn't actually exist on disk -- don't cache
            // under a coerced-to-0 array key (every broken $dir would
            // collide on the same cache slot).
            return [];
        }
        $dir = $real_dir;
        $cache_key = 'themeconf:' . $dir;
        if (! $this->processCache->has($cache_key)) {
            $themeconf = $this->loadThemeJson($dir);
            // Put themeconf in cache
            $this->processCache->set($cache_key, $themeconf);

            // Return the just-computed value directly rather than falling
            // through to the get() read below -- purely to skip a redundant
            // array lookup, not for correctness (unlike the old *Static()
            // shim, $this->processCache is a real, always-present
            // constructor property now, so there's no not-booted-fallback
            // edge case to worry about here anymore).
            return $themeconf;
        }

        /** @var array<string, mixed> $cached */
        $cached = $this->processCache->get($cache_key);

        return $cached;
    }

    /**
     * Reads `theme.json`, mapped onto the same `$themeconf` shape
     * `setTheme()` already reads (`use_standard_pages`/`parent`/
     * `load_parent_css`/`load_parent_local_head`/`local_head`/
     * `colorscheme`/`icon_dir`/`admin_icon_dir`/`img_dir`/`mime_icon_dir`) -- a plain file read
     * + `json_decode()`, not `PluginConfig\ThemeManifest`/`ThemeRegistry`:
     * those are the same L3Presentation layer as this class but pull in
     * DB/EntityManager dependencies this purely-file-based lookup has no
     * reason to need. A malformed/missing `theme.json` degrades to `[]`,
     * not a thrown exception.
     *
     * `icon_dir`/`admin_icon_dir`/`img_dir`/`mime_icon_dir`
     * (`ThemeManifest::$iconDir`/`$adminIconDir`/`$imgDir`/`$mimeIconDir`)
     * are real, live-read fields (Html\HtmlService reads `icon_dir` via
     * `themeConf()`, Image\SrcImage reads `mime_icon_dir`, admin's own
     * theme-management pages read the rest) -- kept even though nothing
     * in this class itself consumes them.
     *
     * @return array<string, mixed>
     */
    private function loadThemeJson(string $dir): array
    {
        if (! file_exists($dir . '/theme.json')) {
            return [];
        }

        $contents = file_get_contents($dir . '/theme.json');
        if ($contents === false) {
            return [];
        }

        $data = json_decode($contents, true);
        if (! is_array($data)) {
            return [];
        }

        $themeId = basename($dir);

        $themeconf = [
            'use_standard_pages' => is_bool($data['useStandardPages'] ?? null) ? $data['useStandardPages'] : false,
            'load_parent_css' => is_bool($data['loadParentCss'] ?? null) ? $data['loadParentCss'] : false,
        ];
        if (is_string($data['parent'] ?? null)) {
            $themeconf['parent'] = $data['parent'];
        }
        if (is_string($data['localHead'] ?? null)) {
            $themeconf['local_head'] = $data['localHead'];
        }
        if (is_string($data['colorscheme'] ?? null)) {
            $themeconf['colorscheme'] = $data['colorscheme'];
        }
        if (is_string($data['iconDir'] ?? null)) {
            $themeconf['icon_dir'] = $data['iconDir'];
        }
        if (is_string($data['adminIconDir'] ?? null)) {
            $themeconf['admin_icon_dir'] = $data['adminIconDir'];
        }
        if (is_string($data['imgDir'] ?? null)) {
            $themeconf['img_dir'] = $data['imgDir'];
        }
        if (is_string($data['mimeIconDir'] ?? null)) {
            $themeconf['mime_icon_dir'] = $data['mimeIconDir'];
        }
        if (is_bool($data['loadParentLocalHead'] ?? null)) {
            $themeconf['load_parent_local_head'] = $data['loadParentLocalHead'];
        }

        if ($themeId === 'standard_pages') {
            ($this->onStandardPagesThemeLoaded)();
        }

        return $themeconf;
    }
}
