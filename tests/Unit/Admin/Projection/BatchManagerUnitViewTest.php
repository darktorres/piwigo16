<?php

declare(strict_types=1);

use Piwigo\Admin\BatchManager\Projection\BatchManagerUnitElement;
use Piwigo\Admin\BatchManager\Projection\DimensionBounds;
use Piwigo\Admin\BatchManager\Projection\DimensionFilterOptions;
use Piwigo\Admin\BatchManager\Projection\FilesizeBounds;
use Piwigo\Admin\BatchManager\Projection\FilesizeFilterOptions;
use Piwigo\Admin\BatchManager\Projection\RatioRange;
use Piwigo\Admin\Projection\BatchManagerUnitView;

/**
 * The two fields exposedPageData() reads; every other one is filled with a
 * placeholder, since nothing under test looks at them.
 */
function batchManagerUnitElement(int|string $id, string $relatedCategoryIds): BatchManagerUnitElement
{
    return new BatchManagerUnitElement(
        id: $id,
        tnSrc: '',
        fileSrc: '',
        uEdit: '',
        name: '',
        author: '',
        description: '',
        dateCreation: null,
        tags: [],
        dimensions: '',
        isWide: false,
        filesize: '',
        ext: '',
        postDate: '',
        age: '',
        addedBy: '',
        stats: '',
        file: '',
        relatedCategories: [],
        relatedCategoryIds: $relatedCategoryIds,
        uJumpto: null,
        uDownload: '',
        uHistory: '',
        uActivity: '',
        path: '',
        levelSelected: null,
    );
}

/**
 * @param list<BatchManagerUnitElement> $elements
 */
function makeBatchManagerUnitView(array $elements): BatchManagerUnitView
{
    return new BatchManagerUnitView(
        uElementsPage: '',
        levelOptions: [],
        csrfToken: '',
        activePlugins: [],
        pluginElementSubtemplates: [],
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
        batchManagerUnitElement('1', '[2,3]'),
        batchManagerUnitElement('2', '[4]'),
    ]);

    expect($view->exposedPageData()['all_related_categories_ids'])
        ->toBe([
            '1' => [2, 3],
            '2' => [4],
        ]);
});

// The sibling test that fed a non-scalar ID is gone: BatchManagerUnitElement
// declares `int|string $id`, so the element exposedPageData() used to skip
// can no longer be built. That skip is the type's job now.
test('exposedPageData decodes an empty related_category_ids as null', function (): void {
    // '' is what the renderer emits when json_encode() fails -- the one way
    // this field is not real JSON.
    $view = makeBatchManagerUnitView([
        batchManagerUnitElement('1', ''),
    ]);

    expect($view->exposedPageData()['all_related_categories_ids'])
        ->toBe([
            '1' => null,
        ]);
});
