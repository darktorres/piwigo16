<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `picture_coi.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PictureCoiPageRenderer::render()}. No `$title` field --
 * `TITLE` has zero real references in `picture_coi.latte`'s own body.
 * `$coi` is genuinely optional: only a real, non-empty crop-of-interest
 * string on the image row produces one. `$croppedDerivatives` is
 * always included -- `picture_coi.latte`'s own `{foreach}` has no
 * guard around it.
 */
#[Template('picture_coi.latte')]
final readonly class PictureCoiView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array{l: float, t: float, r: float, b: float}|null $coi
     * @param list<array{U_IMG: string, HTM_SIZE: Html}> $croppedDerivatives
     */
    public function __construct(
        public string $alt,
        public string $imgUrl,
        public ?array $coi,
        public array $croppedDerivatives,
    ) {}

    /**
     * `picture_coi.latte`'s own unconditional `{do htmlHead(...)}`
     * (a plain static CSS stylesheet link -- a `combineCss()` call that
     * happened to go through the wrong function, per docs/PLAN.md's own
     * "htmlHead() -- fully migrated, not an exception" design note) plus
     * `{do combineScript(...)}`x1/`{do combineCss(...)}`x1
     * (docs/PLAN.md's P42-B). Jcrop's own CDN script registration is
     * gone (P49-B group 6, `vendor/widgets/jcrop.ts`) -- its CSS stays, kept for
     * its real `.jcrop-*` class names this module's own DOM still uses.
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('https://cdn.jsdelivr.net/gh/tapmodo/Jcrop@v0.9.12/css/jquery.Jcrop.css'),
            AssetContribution::css('themes/admin/default/css/pages/picture_coi.css', id: 'picture_coi'),
            AssetContribution::script('picture_coi', 'themes/admin/default/js/pictureCoi.ts', loadMode: LoadMode::Footer),
        ];
    }

    /**
     * `picture_coi.latte`'s own `{if isset($coi)}{do exposeData('coi',
     * $coi)}{/if}` (docs/PLAN.md's P42-B) -- a real conditional, not a
     * fixed literal, since `$coi` is genuinely optional.
     */
    #[Override]
    public function exposedPageData(): array
    {
        return $this->coi === null ? [] : [
            'coi' => $this->coi,
        ];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
