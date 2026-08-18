<?php

declare(strict_types=1);

namespace Piwigo\Asset;

/**
 * One fully-ordered, `ViteManifest`-resolved asset ready to render as a
 * tag -- `PageAssets::resolveScripts()`/`resolveCss()`'s own output.
 * `$path` is relative (either `ViteManifestEntry::$file`, relative to
 * `dist/`, or the raw fallback path, relative to the app root) --
 * root-URL prefixing and version-query-string cache busting stay the
 * caller's job, since no real caller exists yet to decide those
 * (`Piwigo\Page\PageTailRenderer` builds its own URL around
 * `ViteManifest::resolve()` directly for the one real asset that
 * exists today, `vitals.js`, rather than going through this class at
 * all -- a single ungrouped asset has no ordering/dependency need for
 * the collector).
 */
final readonly class ResolvedAsset
{
    public function __construct(
        public string $path,
        public ?LoadMode $loadMode,
    ) {}
}
