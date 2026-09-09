<?php

declare(strict_types=1);

namespace Piwigo\Page\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `no_photo_yet.latte`'s own typed view, constructed by {@see
 * \Piwigo\Page\NoPhotoYetRenderer::render()} for both its admin and
 * guest branches. `$step` alone (`1` vs. anything else) picks the
 * template's own branch, so `$loginUrl` is only ever real for the
 * guest (`step === 1`) branch and `$intro`/`$nextStepUrl` only for the
 * admin one.
 */
#[Template('no_photo_yet.latte')]
final readonly class NoPhotoYetView implements View, HasPageAssets
{
    public function __construct(
        public int $step,
        public ?string $loginUrl,
        public ?string $intro,
        public ?string $nextStepUrl,
        public string $deactivateUrl,
    ) {}

    /**
     * `no_photo_yet.latte`'s own unconditional `{do combineCss(...)}`
     * (docs/PLAN.md's P42-B) -- found missing entirely (P52-E/F Step 3)
     * while auditing `default`'s real asset-registration sites: this
     * page is a genuinely standalone document (own `<!DOCTYPE`, no
     * `layout.latte`), rendered via `NoPhotoYetRenderer::render()`'s own
     * direct `Renderer::render()` + `Template::finalizeHtml()` call
     * *before* routing reaches any real controller -- but that Template
     * instance still runs the normal `applyThemeBaseAssets()` path
     * (`$path` defaults to `'template'`, `$applyThemeBase` to `true`),
     * so `reset.css`/`tokens.css`/`base.css`/`theme.css`/`utilities.css`
     * were already being resolved into `pageAssets` correctly the whole
     * time -- the template just never printed `{=getCombinedCss()}` to
     * consume them, so the resolved list was silently discarded and 2
     * hand-written `<link>` tags (unversioned, bypassing this pipeline
     * entirely) stood in for all of it instead.
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/css/pages/no_photo_yet.css', id: 'no_photo_yet'),
        ];
    }
}
