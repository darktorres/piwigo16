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
 */
#[Template('cat_list.latte')]
final readonly class CatListView implements View, HasPageAssets
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

    /**
     * `cat_list.latte`'s own unconditional `{do combineScript(...)}`x3/
     * `{do combineCss(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('common', 'themes/admin/default/js/common.ts', loadMode: LoadMode::Footer),
            AssetContribution::script('alternativeView', 'themes/admin/default/js/cat_list.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.cookie', 'https://cdn.jsdelivr.net/npm/jquery.cookie@1.4.1/jquery.cookie.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/cat_list.css', id: 'cat_list'),
        ];
    }
}
