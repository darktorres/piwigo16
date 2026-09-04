<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\Projection\Navbar;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `rating.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\RatingPageRenderer::render()}. `$orderByOptions` and
 * `$images` are both always included -- the template reads
 * `orderByOptions` via an unguarded `{foreach}` and `images`
 * via an unguarded `{foreach}`. `$colorscheme`/`$rootUrl` are the
 * ambient `$themeconf['colorscheme']`/`$ROOT_URL` the template's own
 * `combineCss`/`exposeData` calls read -- the controller resolves both
 * the same way `Template` itself would, via
 * `$template->themeConf('colorscheme')`/`$urlService->getRootUrl()`.
 */
#[Template('rating.latte')]
final readonly class RatingView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<mixed> $category
     * @param array<array-key, string> $cacheKeys
     * @param list<int> $orderByOptionsSelected
     * @param array<string, string> $userOptions
     * @param list<string> $userOptionsSelected
     * @param list<string> $orderByOptions
     * @param list<RatingReportImageRow> $images
     */
    public function __construct(
        public Navbar $navbar,
        public string $fAction,
        public int $display,
        public int $nbElements,
        public array $category,
        public array $cacheKeys,
        public array $orderByOptionsSelected,
        public array $userOptions,
        public array $userOptionsSelected,
        public array $orderByOptions,
        public array $images,
        public string $csrfToken,
        public string $colorscheme,
        public string $rootUrl,
    ) {}

    /**
     * `rating.latte`'s own unconditional `{do combineScript(...)}`x4/
     * `{do combineCss(...)}`x2 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::css('themes/admin/default/css/pages/rating.css', id: 'rating'),
            // 'rating_photo' imports scripts.ts directly now (docs/PLAN.md
            // P48) -- the separate
            // `core.scripts` registration this page used to carry is
            // dropped. Renamed from rating.ts (docs/PLAN.md P51-I item 3)
            // to disambiguate from ratings/user.ts -- both delete ratings
            // (of one photo vs. by one user), but only this name says
            // which axis.
            AssetContribution::script('rating_photo', 'themes/admin/default/js/ratings/photo.ts', loadMode: LoadMode::Footer),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [
            'cache_key_categories' => $this->cacheKeys['categories'] ?? '',
            'cache_key_hash' => $this->cacheKeys['_hash'] ?? '',
            'root_url' => $this->rootUrl,
            'nb_elements' => $this->nbElements,
            'csrf_token' => $this->csrfToken,
        ];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
