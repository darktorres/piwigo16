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
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $navbar
     * @param list<mixed> $category
     * @param array<array-key, string> $cacheKeys
     * @param list<int> $orderByOptionsSelected
     * @param array<string, string> $userOptions
     * @param list<mixed> $userOptionsSelected
     * @param list<string> $orderByOptions
     * @param list<array<string, mixed>> $images
     */
    public function __construct(
        public array $navbar,
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
            AssetContribution::script('jquery.selectize', 'https://cdn.jsdelivr.net/gh/selectize/selectize.js@v0.11.2/dist/js/standalone/selectize.min.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::css('themes/admin/default/css/pages/rating.css', id: 'rating'),
            // 'rating' imports scripts.ts directly now (docs/PLAN.md
            // P48) -- the separate
            // `core.scripts` registration this page used to carry is
            // dropped.
            AssetContribution::script('rating', 'themes/admin/default/js/rating.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.selectize']),
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
