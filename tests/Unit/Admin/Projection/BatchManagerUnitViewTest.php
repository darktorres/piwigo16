<?php

declare(strict_types=1);

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
        rootPath: '',
        jqueryCode: '',
        colorscheme: 'light',
        rootUrl: '',
        associatedCategories: [],
        filterDimensions: [],
        filterFilesize: [],
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
