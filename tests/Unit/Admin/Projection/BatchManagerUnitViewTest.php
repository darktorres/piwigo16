<?php

declare(strict_types=1);

use Piwigo\Admin\BatchManager\Projection\DimensionBounds;
use Piwigo\Admin\BatchManager\Projection\DimensionFilterOptions;
use Piwigo\Admin\BatchManager\Projection\FilesizeBounds;
use Piwigo\Admin\BatchManager\Projection\FilesizeFilterOptions;
use Piwigo\Admin\BatchManager\Projection\RatioRange;
use Piwigo\Admin\Projection\BatchManagerUnitView;

/**
 * @param list<array<string, mixed>> $elements
 */
function makeBatchManagerUnitView(array $elements): BatchManagerUnitView
{
    return new BatchManagerUnitView(
        uElementsPage: '',
        levelOptions: [],
        csrfToken: '',
        activePlugins: [],
        perPage: 5,
        navbar: null,
        elementIds: null,
        cacheKeys: [
            'tags' => 'tags-key',
            'categories' => 'categories-key',
            '_hash' => 'hash-key',
        ],
        elements: $elements,
        jqueryCode: '',
        colorscheme: 'light',
        rootUrl: '',
        associatedCategories: [],
        filterDimensions: new DimensionFilterOptions(
            widths: '600',
            heights: '480',
            ratios: '1.25',
            bounds: new DimensionBounds(600, 600, 480, 480, 1.25, 1.25),
            ratioPortrait: null,
            ratioSquare: null,
            ratioLandscape: new RatioRange(min: 1.25, max: 1.25),
            ratioPanorama: null,
            selected: new DimensionBounds(600, 600, 480, 480, 1.25, 1.25),
        ),
        filterFilesize: new FilesizeFilterOptions(
            list: '0.0',
            bounds: new FilesizeBounds(min: '0.0', max: '0.0'),
            selected: new FilesizeBounds(min: '0.0', max: '0.0'),
        ),
        filterCategorySelected: null,
    );
}

test('exposedPageData builds all_related_categories_ids by decoding each element JSON', function (): void {
    $view = makeBatchManagerUnitView([
        [
            'ID' => '1',
            'related_category_ids' => '[2,3]',
        ],
        [
            'ID' => '2',
            'related_category_ids' => '[4]',
        ],
    ]);

    expect($view->exposedPageData()['all_related_categories_ids'])
        ->toBe([
            '1' => [2, 3],
            '2' => [4],
        ]);
});

test('exposedPageData skips an element with a non-scalar ID', function (): void {
    $view = makeBatchManagerUnitView([
        [
            'ID' => ['nested'],
            'related_category_ids' => '[1]',
        ],
        [
            'ID' => '5',
            'related_category_ids' => '[9]',
        ],
    ]);

    expect($view->exposedPageData()['all_related_categories_ids'])
        ->toBe([
            '5' => [9],
        ]);
});

test('exposedPageData decodes a missing related_category_ids as null', function (): void {
    $view = makeBatchManagerUnitView([
        [
            'ID' => '1',
        ],
    ]);

    expect($view->exposedPageData()['all_related_categories_ids'])
        ->toBe([
            '1' => null,
        ]);
});
