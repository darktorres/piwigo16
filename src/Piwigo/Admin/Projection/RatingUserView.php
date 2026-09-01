<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Rate\Projection\ImageThumbUrl;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `rating_user.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\RatingUserPageRenderer::render()}. `$ratings` is
 * keyed by the display name the table's first column renders, which
 * is why the row itself carries no name. `$orderByOptions` is always included --
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
     * @param array<string, UserRatingRow> $ratings
     * @param array<int, ImageThumbUrl> $imageUrls
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
        public bool $geoIpAvailable,
    ) {}

    /**
     * `rating_user.latte`'s own unconditional `{do combineScript(...)}`x7/
     * `{do combineCss(...)}`x2 (docs/PLAN.md's P42-B). No jQuery-UI theme
     * CSS was ever registered on this page (tooltip styling didn't need
     * it) -- unaffected by the native `dataTable()`/`tooltip()` port
     * (docs/PLAN.md P49-C), which drops the `jquery.dataTables`/
     * `jquery.ui` script registrations outright: `rating_user.ts` itself
     * has zero real jQuery/jQuery-UI/datatables.net calls left.
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/admin/default/css/pages/rating_user.css', id: 'rating_user'),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            // 'rating_user' folds scripts.ts's own code in via a real
            // direct import now (docs/PLAN.md P48) -- the separate
            // `core.scripts` registration this page used to carry is
            // dropped.
            AssetContribution::script('rating_user', 'themes/admin/default/js/rating_user.ts', loadMode: LoadMode::Footer),
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
