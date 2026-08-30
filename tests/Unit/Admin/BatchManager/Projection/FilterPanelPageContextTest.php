<?php

declare(strict_types=1);

use Piwigo\Admin\BatchManager\Projection\BulkManagerFilter;
use Piwigo\Admin\BatchManager\Projection\FilterPanelPageContext;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    // The filter reaches the template as the VO, not the raw session bag:
    // the array's key presence was only how it could say "the user enabled
    // this filter", and BulkManagerFilter names that directly (P58-B3).
    $filter = BulkManagerFilter::fromArray([
        'level' => 2,
    ]);

    $context = new FilterPanelPageContext(
        confChecksumComputeBlocksize: 50,
        prefilters: [[
            'ID' => 'caddie',
            'NAME' => 'Caddie',
        ]],
        filter: $filter,
        selection: [1, 2, 3],
        allElements: [1, 2, 3],
        start: 0,
        pwgToken: 'abc123',
        uDisplay: '/admin.php?page=batch_manager',
        fAction: '/admin.php?page=batch_manager',
        adminPageTitle: 'Batch Manager',
        nbNoMd5sum: '',
        filterLevelOptions: [
            0 => 'Everybody',
            2 => 'Level 2',
        ],
        filterLevelOptionsSelected: 2,
        filterTags: [
            1 => [
                'name' => 'sunset',
                'id' => '1',
            ],
        ],
        filterCategorySelectedName: 'Holidays',
        filterCategorySelected: 5,
        filterSearchQuery: 'sunset',
        associatedCategories: [
            5 => 5,
        ],
    );

    expect($context->toArray())
        ->toBe([
            'conf_checksum_compute_blocksize' => 50,
            'prefilters' => [[
                'ID' => 'caddie',
                'NAME' => 'Caddie',
            ]],
            'filter' => $filter,
            'selection' => [1, 2, 3],
            'all_elements' => [1, 2, 3],
            'START' => 0,
            'CSRF_TOKEN' => 'abc123',
            'U_DISPLAY' => '/admin.php?page=batch_manager',
            'F_ACTION' => '/admin.php?page=batch_manager',
            'ADMIN_PAGE_TITLE' => 'Batch Manager',
            'NB_NO_MD5SUM' => '',
            'filter_level_options' => [
                0 => 'Everybody',
                2 => 'Level 2',
            ],
            'filter_level_options_selected' => 2,
            'filter_tags' => [
                1 => [
                    'name' => 'sunset',
                    'id' => '1',
                ],
            ],
            'filter_category_selected_name' => 'Holidays',
            'filter_category_selected' => 5,
            'filter_search_query' => 'sunset',
            'associated_categories' => [
                5 => 5,
            ],
        ]);
});
