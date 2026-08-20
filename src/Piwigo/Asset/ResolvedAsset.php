<?php

declare(strict_types=1);

namespace Piwigo\Asset;

/**
 * One fully-ordered, `ViteManifest`-resolved asset ready to render as a
 * tag -- `PageAssets::resolveScripts()`/`resolveCss()`'s own output.
 * `$path` is relative (either `ViteManifestEntry::$file`, relative to
 * `dist/`, or the raw fallback path, relative to the app root) --
 * root-URL prefixing stays the caller's job (`Template`'s own
 * asset-tag-rendering step, P41-G, docs/PLAN.md), since it also needs
 * to dispatch `Template\Event\CombinedScript` per script, a step this
 * value object has no business knowing about.
 *
 * `$version` carries `AssetContribution::$version` through unchanged
 * (`false` disables cache-busting, matching that class's own
 * contract) -- meaningless for an inline script (`$inlineCode !==
 * null`), which has no URL to cache-bust.
 *
 * `inline()` is `AssetKind::InlineScript`'s own resolved shape: no
 * path/URL at all, just the literal code ready to print inside the
 * footer's one wrapped `<script>` block.
 */
final readonly class ResolvedAsset
{
    private function __construct(
        public string $path,
        public ?LoadMode $loadMode,
        public string|false $version,
        public ?string $inlineCode,
    ) {}

    public static function file(string $path, ?LoadMode $loadMode, string|false $version): self
    {
        return new self($path, $loadMode, $version, null);
    }

    public static function inline(string $code): self
    {
        return new self('', LoadMode::Footer, false, $code);
    }
}
