<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

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
     * @param list<array{U_IMG: string, HTM_SIZE: string}> $croppedDerivatives
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
     * `{do combineScript(...)}`x2/`{do combineCss(...)}`x1
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/js/plugins/jquery.Jcrop.css'),
            AssetContribution::script('jquery.jcrop', 'themes/default/js/plugins/jquery.Jcrop.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('themes/admin/default/css/pages/picture_coi.css', id: 'picture_coi'),
            AssetContribution::script('picture_coi', 'themes/admin/default/js/picture_coi.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.jcrop', 'page-data']),
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
