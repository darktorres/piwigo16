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
 * `rating_user.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\RatingUserPageRenderer::render()}. `$ratings`/
 * `$imageUrls` stay loose row shapes: `$ratings` is the same genuinely
 * dynamic, incrementally-accumulated-across-3-mutation-points shape
 * documented on `RatingUserPageRenderer`'s own `avgCompare()`/
 * `countCompare()`/etc. comparators, not a fixed structural shape worth
 * minting its own DTO for here. `$orderByOptions` is always included --
 * the template reads it via an unguarded `{foreach}`, matching
 * the original code's own unconditional loop. `$rootUrl` is the ambient
 * `$ROOT_URL` the template's own `exposeData` call reads -- the
 * controller resolves it the same way `Template` itself would, via
 * `$urlService->getRootUrl()`.
 */
#[Template('rating_user.latte')]
final readonly class RatingUserView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<int> $orderByOptionsSelected
     * @param list<int> $availableRates
     * @param array<string, array<string, mixed>> $ratings
     * @param array<int, array{tn: string, page: string}> $imageUrls
     * @param list<string> $orderByOptions
     */
    public function __construct(
        public array $orderByOptionsSelected,
        public string $formAction,
        public int $minRates,
        public int $consensusTopNumber,
        public array $availableRates,
        public array $ratings,
        public array $imageUrls,
        public int $tnWidth,
        public int $nbElements,
        public array $orderByOptions,
        public string $csrfToken,
        public string $rootUrl,
    ) {}

    /**
     * `rating_user.latte`'s own unconditional `{do combineScript(...)}`x7/
     * `{do combineCss(...)}`x2 (docs/PLAN.md's P42-B). `jquery.ui.tooltip`
     * carries no `path:` in the original call either -- it's one of
     * `PageAssets`'s own well-known ids, resolved by naming convention
     * (`PageAssets::fillKnownScript()`'s own docblock cites this exact
     * call as its precedent), so an empty path here matches the
     * original behavior exactly.
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('jquery.dataTables', 'themes/default/js/plugins/jquery.dataTables.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/rating_user.css', id: 'rating_user'),
            AssetContribution::script('common', 'themes/admin/default/js/common.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.confirm', 'themes/default/js/plugins/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('themes/default/js/plugins/jquery-confirm.min.css'),
            AssetContribution::script('core.scripts', 'themes/default/js/scripts.js', loadMode: LoadMode::Async),
            AssetContribution::script('jquery.geoip', 'themes/admin/default/js/jquery.geoip.js', loadMode: LoadMode::Async),
            AssetContribution::script('jquery.ui.tooltip', '', loadMode: LoadMode::Footer),
            AssetContribution::script('rating_user', 'themes/admin/default/js/rating_user.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.dataTables', 'jquery.ui.tooltip', 'page-data']),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [
            'nb_elements' => $this->nbElements,
            'csrf_token' => $this->csrfToken,
            'root_url' => $this->rootUrl,
        ];
    }

    /**
     * `rating_user.latte`'s own unconditional `{do exposeString(...)}`x3
     * (docs/PLAN.md's P42-B) -- `'Yes, I am sure'`/`'No, I have changed
     * my mind'` are dropped outright, not ported here: 2 of the 3
     * theme-base confirm-dialog strings `ThemeBaseAssets` already
     * registers unconditionally for every page.
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Are you sure you want to delete the ratings of the user "%s"?',
        ];
    }
}
