<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Category\Projection\CategorySelectOptions;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\PermalinksSubController::handle()}.
 * `$categoriesOptions` is {@see \Piwigo\Category\CategoryService}'s own
 * `categories`/`categories_selected` pair.
 */
final readonly class PermalinksPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $permalinks
     * @param list<array<string, mixed>> $deletedPermalinks
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
        public string $pwgToken,
        public string $helpUrl,
        public array $deletedPermalinks,
        public string $adminPageTitle,
        public CategorySelectOptions $categoriesOptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'nb_cats' => $this->nbCats,
            'SORT_ID' => $this->sortId,
            'SORT_NAME' => $this->sortName,
            'SORT_PERMALINK' => $this->sortPermalink,
            'permalinks' => $this->permalinks,
            'SORT_OLD_CAT_ID' => $this->sortOldCatId,
            'SORT_OLD_PERMALINK' => $this->sortOldPermalink,
            'SORT_OLD_DATE_DELETED' => $this->sortOldDateDeleted,
            'SORT_OLD_LAST_HIT' => $this->sortOldLastHit,
            'SORT_OLD_HIT' => $this->sortOldHit,
            'PWG_TOKEN' => $this->pwgToken,
            'U_HELP' => $this->helpUrl,
            'deleted_permalinks' => $this->deletedPermalinks,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'categories' => $this->categoriesOptions->options,
            'categories_selected' => $this->categoriesOptions->selected,
        ];
    }
}
