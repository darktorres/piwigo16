<?php

declare(strict_types=1);

namespace Piwigo\Template\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for
 * `themes/default/template/help/quick_search.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d, from 2
 * real call sites shared across the frontend and admin domains
 * (`search_filters.inc.latte`, `batch_manager_filter.inc.latte`), both
 * passing the one real variable this body reads.
 *
 * `pageAssets()` (docs/PLAN.md's P42-B) is merged in by
 * `SearchFiltersView`, the one real parent already migrated -- this
 * file's own `{do combineCss}` call stays live too, since the other
 * real parent (`batch_manager_filter.inc.latte`) isn't migrated yet;
 * both sources coexist correctly (`PageAssets::add()`'s own dedup
 * contract).
 */
#[TemplateAttr('help/quick_search.latte')]
final readonly class QuickSearchView implements View, HasPageAssets
{
    public function __construct(
        public bool $is_dark_mode,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/css/help/quick_search.css'),
        ];
    }
}
