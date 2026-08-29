<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Admin\BatchManager\Projection\DimensionFilterOptions;
use Piwigo\Admin\BatchManager\Projection\FilesizeFilterOptions;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for
 * `themes/admin/default/template/include/batch_manager_filter.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d.
 *
 * Every property here is really assigned by one of several *ambient*
 * `TemplatePageContext` classes upstream (`FilterPanelPageContext`,
 * `Controller\Admin\Projection\BatchManagerFilterOptionsPageContext`,
 * `Controller\Admin\Projection\BatchManagerNoSearchResultsPageContext`,
 * `AdminShellFramePageContext`), not passed via this template's own
 * `{include}` call -- `{templateType}` doesn't care about provenance,
 * only which variables the template body actually reads, confirmed by
 * reading the full real body (past the old shotgun header) rather than
 * assumed from the 2 real call sites' own explicit args. Those 2 real
 * call sites (`batch_manager_unit.latte`, `batch_manager_global.latte`)
 * both pass `title`/`searchPlaceholder` explicitly, but the body never
 * reads either -- confirmed dead params, deliberately not declared here
 * since this file's own job is the real read-contract, not the
 * call sites' own (separate, pre-existing) waste.
 *
 * All-caps property names (`$CSRF_TOKEN`/`$NB_NO_MD5SUM`/`$NB_ORPHANS`)
 * match the real template variable names exactly, mirroring the
 * upstream `TemplatePageContext::toArray()` keys they're assigned
 * from -- `{templateType}`'s reflection lookup is name-exact.
 *
 * No `HasPageAssets`/`ExposesPageData` here (docs/PLAN.md's P42-B):
 * this file's own former `combineCss`/`combineScript`/`exposeData`/
 * `exposeString` calls moved directly onto its 2 real parents'
 * (`BatchManagerUnitView`/`BatchManagerGlobalView`) own methods instead
 * of a constructed instance of this class -- every one of the 4 real
 * values those calls need (`$dimensions`/`$filesize`/
 * `$filter_category_selected`, plus the ambient `$themeconf['colorscheme']`
 * already on each parent) is real and ambient-readable, but this
 * class's own constructor carries the other 9 real template properties
 * too, which would need real (not dummy) values just to build an
 * instance whose only use is calling 3 methods that never touch them.
 */
#[TemplateAttr('include/batch_manager_filter.inc.latte')]
final readonly class BatchManagerFilterView implements View
{
    /**
     * @param array<string, mixed> $filter
     * @param array<int, string> $filter_level_options
     * @param array<int, array{name: mixed, id: string}> $filter_tags
     * @param list<array<mixed>> $prefilters
     * @param ?list<string> $no_search_results
     */
    public function __construct(
        public array $filter,
        public ?int $filter_category_selected,
        public ?string $filter_search_query,
        public string $filter_category_selected_name,
        public array $filter_level_options,
        public int $filter_level_options_selected,
        public array $filter_tags,
        public int|string $NB_NO_MD5SUM,
        public string $CSRF_TOKEN,
        public array $prefilters,
        public int $NB_ORPHANS,
        public DimensionFilterOptions $dimensions,
        public FilesizeFilterOptions $filesize,
        public ?array $no_search_results = null,
    ) {}
}
