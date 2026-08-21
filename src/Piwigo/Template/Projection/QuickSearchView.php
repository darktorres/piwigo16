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
 * `pageAssets()` (docs/PLAN.md's P42-B) is merged in by both real
 * parents' own Views (`SearchFiltersView` directly;
 * `BatchManagerUnitView`/`BatchManagerGlobalView`, since
 * `batch_manager_filter.inc.latte` itself is the other real caller) --
 * this file's own former `{do combineCss}` call was removed once both
 * were confirmed covered. Its own real markup (the quick-search-syntax
 * popin) still needs a plain `{include}` at each real call site.
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
