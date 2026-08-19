<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Piwigo\Category\Projection\CategorySelectOptions;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `permalinks.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\PermalinksSubController::handle()}.
 * `$categoriesOptions` is {@see \Piwigo\Category\CategoryService}'s own
 * `categories`/`categories_selected` pair.
 */
#[Template('permalinks.latte')]
final readonly class PermalinksView implements View
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
        public string $csrfToken,
        public array $deletedPermalinks,
        public CategorySelectOptions $categoriesOptions,
    ) {}
}
