<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Category\Projection\CategorySelectOptions;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `permalinks.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\PermalinksSubController::handle()}.
 * `$categoriesOptions` is {@see \Piwigo\Category\CategoryService}'s own
 * `categories`/`categories_selected` pair.
 */
#[Template('permalinks.latte')]
final readonly class PermalinksView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<PermalinkListRow> $permalinks
     * @param list<DeletedPermalinkRow> $deletedPermalinks
     */
    public function __construct(
        public int $nbCats,
        public string $sortId,
        public string $sortName,
        public string $sortPermalink,
        public array $permalinks,
        public string $sortOldCatId,
        public string $sortOldPermalink,
        public string $sortOldDateDeleted,
        public string $sortOldLastHit,
        public string $sortOldHit,
        public string $csrfToken,
        public array $deletedPermalinks,
        public CategorySelectOptions $categoriesOptions,
    ) {}

    /**
     * `permalinks.latte`'s own unconditional `{do combineScript(...)}`/
     * `{do combineCss(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('permalinks', 'themes/admin/default/js/permalinks.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/permalinks.css', id: 'permalinks'),
        ];
    }

    /**
     * `permalinks.latte`'s own unconditional `{do exposeData('nb_cats', ...)}`
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [
            'nb_cats' => $this->nbCats,
        ];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
