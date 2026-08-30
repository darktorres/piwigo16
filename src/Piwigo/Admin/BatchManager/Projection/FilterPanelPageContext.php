<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\BatchManager\FilterPanelRenderer::render()}.
 */
final readonly class FilterPanelPageContext implements TemplatePageContext
{
    /**
     * @param list<\Piwigo\Admin\BatchManager\Projection\BatchManagerPrefilter> $prefilters
     * @param array<mixed> $selection
     * @param array<array-key, int|string|float|bool> $allElements
     * @param array<int, string> $filterLevelOptions
     * @param array<int, array{name: mixed, id: string}> $filterTags
     * @param array<array-key, mixed> $associatedCategories
     */
    public function __construct(
        public int $confChecksumComputeBlocksize,
        public array $prefilters,
        public BulkManagerFilter $filter,
        public array $selection,
        public array $allElements,
        public int $start,
        public string $pwgToken,
        public string $uDisplay,
        public string $fAction,
        public string $adminPageTitle,
        public int|string $nbNoMd5sum,
        public array $filterLevelOptions,
        public int $filterLevelOptionsSelected,
        public array $filterTags,
        public string $filterCategorySelectedName,
        public ?int $filterCategorySelected,
        public ?string $filterSearchQuery,
        public array $associatedCategories,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'conf_checksum_compute_blocksize' => $this->confChecksumComputeBlocksize,
            'prefilters' => $this->prefilters,
            'filter' => $this->filter,
            'selection' => $this->selection,
            'all_elements' => $this->allElements,
            'START' => $this->start,
            'CSRF_TOKEN' => $this->pwgToken,
            'U_DISPLAY' => $this->uDisplay,
            'F_ACTION' => $this->fAction,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'NB_NO_MD5SUM' => $this->nbNoMd5sum,
            'filter_level_options' => $this->filterLevelOptions,
            'filter_level_options_selected' => $this->filterLevelOptionsSelected,
            'filter_tags' => $this->filterTags,
            'filter_category_selected_name' => $this->filterCategorySelectedName,
            'filter_category_selected' => $this->filterCategorySelected,
            'filter_search_query' => $this->filterSearchQuery,
            'associated_categories' => $this->associatedCategories,
        ];
    }
}
