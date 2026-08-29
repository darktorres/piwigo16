<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `cat_list.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\CatListPageRenderer::render()}. No `$nbCats`,
 * `$sortOrders`, `$sortOrderChecked`, or `$parentEditUrl` field -- the
 * template's own body never references any of them (confirmed against
 * `cat_list.js` too, which reads none of them via `pwg_getPageData()`
 * either).
 *
 * `$albumViewSelected` decides which of the three layout radios the
 * server paints checked. Null means none of them: the cookie holds a
 * value that is not one of the three. See
 * {@see \Piwigo\Admin\CatListPageRenderer::albumViewSelected()}.
 */
#[Template('cat_list.latte')]
final readonly class CatListView implements View, HasPageAssets
{
    /**
     * @param list<CategoryListRow> $categories
     */
    public function __construct(
        public string $categoriesNav,
        public string $formAction,
        public string $csrfToken,
        public array $categories,
        public ?string $albumViewSelected,
    ) {}

    /**
     * `cat_list.latte`'s own unconditional `{do combineScript(...)}`x3/
     * `{do combineCss(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('alternativeView', 'themes/admin/default/js/cat_list.ts', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.cookie', 'https://cdn.jsdelivr.net/npm/jquery.cookie@1.4.1/jquery.cookie.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/cat_list.css', id: 'cat_list'),
        ];
    }
}
