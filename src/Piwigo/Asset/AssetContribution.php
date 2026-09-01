<?php

declare(strict_types=1);

namespace Piwigo\Asset;

/**
 * One asset a page needs -- the typed replacement for a template-side
 * `{do combineScript(...)}`/`combineCss(...)`/`{do footerScript(...)}`
 * call. `$path` is either a raw static file path (today's norm --
 * resolved as-is when `ViteManifest` has no matching entry) or a real
 * Vite entry source key (e.g. `build/vitals.ts`) once something is
 * actually bundled.
 *
 * Two independent ordering axes, matching `ScriptLoader`/`CssLoader`'s
 * real, currently-live behavior (see `PageAssets`'s own docblock for
 * why both are still needed): `$loadMode`/`$dependsOn` for scripts
 * (head/footer/async partition + dependency-respecting topological
 * sort), `$order` for CSS (plain integer sort, real range found in
 * templates: -999 to 100). Each is meaningless for the other kind, so
 * construction is split into named factories rather than exposing one
 * constructor where half the parameters never apply.
 *
 * `AssetKind::InlineScript` (`inlineScript()` below) is
 * `ScriptLoader::addInline()`'s own real replacement -- raw JS code
 * registered without a file path, always footer-positioned (matching
 * `getCombinedScripts()`'s existing exclusive-to-footer rendering of
 * inline scripts today), `$code` holding the literal content instead
 * of `$path`.
 */
final readonly class AssetContribution
{
    /**
     * @param list<string> $dependsOn ids of scripts this must load
     *   after -- ignored for `AssetKind::Css`
     */
    private function __construct(
        public string $id,
        public AssetKind $kind,
        public string $path,
        public string|false $version,
        public ?LoadMode $loadMode,
        public int $order,
        public array $dependsOn,
        public ?string $code = null,
    ) {}

    /**
     * @param list<string> $dependsOn
     * @param string|false $version false disables version-based cache
     *   busting, matching `Combinable::$version`'s established contract
     */
    public static function script(
        string $id,
        string $path,
        LoadMode $loadMode = LoadMode::Header,
        array $dependsOn = [],
        string|false $version = '0',
    ): self {
        return new self($id, AssetKind::Script, $path, $version, $loadMode, 0, $dependsOn);
    }

    /**
     * `$id` defaults to `md5($path)`, matching `Template::combineCss()`'s
     * existing default.
     *
     * @param string|false $version false disables version-based cache busting
     */
    public static function css(
        string $path,
        ?string $id = null,
        int $order = 0,
        string|false $version = '0',
    ): self {
        return new self($id ?? md5($path), AssetKind::Css, $path, $version, null, $order, []);
    }

    /**
     * @param list<string> $dependsOn ids of already-registered scripts
     *   this inline code depends on -- a dependency currently async
     *   gets promoted to footer-sync, matching
     *   `ScriptLoader::addInline()`'s own real behavior (execution
     *   order across separate `<script async>` tags isn't guaranteed,
     *   inline code isn't). Unlike `ScriptLoader::addInline()`, an id
     *   that isn't registered at all is silently ignored rather than
     *   fatal-erroring (`PageAssets::promoteLoadModes()`'s own
     *   `$this->scripts[$id] ?? null` null-check) -- confirmed via grep
     *   that none of the 6 real `{do footerScript(...)}` call sites ever
     *   pass `require` at all, so there was no real behavior to preserve
     *   there. Used to also cover a known-by-naming-convention id
     *   (`jquery`/`jquery.ui`) auto-registering itself here -- gone with
     *   that whole mechanism (P49-C).
     */
    public static function inlineScript(string $code, array $dependsOn = []): self
    {
        return new self('', AssetKind::InlineScript, '', false, LoadMode::Footer, 0, $dependsOn, $code);
    }
}
