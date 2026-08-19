<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `cat_list.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\CatListPageRenderer::render()}. No `$nbCats`,
 * `$sortOrders`, `$sortOrderChecked`, or `$parentEditUrl` field -- the
 * template's own body never references any of them (confirmed against
 * `cat_list.js` too, which reads none of them via `pwg_getPageData()`
 * either).
 */
#[Template('cat_list.latte')]
final readonly class CatListView implements View
{
    /**
     * @param list<array<string, mixed>> $categories
     */
    public function __construct(
        public string $categoriesNav,
        public string $formAction,
        public string $csrfToken,
        public array $categories,
    ) {}
}
