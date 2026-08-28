<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\SearchFiltersView;
use Piwigo\Search\Projection\RangeBounds;
use Piwigo\Search\Projection\RangeFilterOptions;

function makeSearchFiltersView(
    ?string $fullnameOf = null,
    ?string $searchId = null,
    ?RangeFilterOptions $filesize = null,
    ?RangeFilterOptions $height = null,
    ?RangeFilterOptions $width = null,
    string $userRank = 'none',
    string $colorscheme = 'light',
    string $csrfToken = 'token',
): SearchFiltersView {
    return new SearchFiltersView(
        displayFilter: [],
        showFilterRatings: true,
        gp: '{}',
        searchId: $searchId,
        tags: null,
        authors: null,
        addedBy: null,
        fullnameOf: $fullnameOf,
        filetypes: null,
        rating: null,
        filesize: $filesize,
        ratios: null,
        height: $height,
        width: $width,
        albumsFound: null,
        tagsFound: null,
        listDatePosted: null,
        datePosted: null,
        listDateCreated: null,
        dateCreated: null,
        colorscheme: $colorscheme,
        userRank: $userRank,
        csrfToken: $csrfToken,
    );
}

test('exposedPageData omits every nullable field when unset', function (): void {
    $view = makeSearchFiltersView();

    expect($view->exposedPageData())
        ->toBe([
            'global_params_json' => '{}',
            'user_rank' => 'none',
            'show_filter_ratings' => true,
            'csrf_token' => 'token',
        ]);
});

test('exposedPageData includes every nullable field once set', function (): void {
    // Real RangeFilterOptions values, not an arbitrary ['min','max'] pair:
    // the previous version of this test passed a shape the renderer never
    // produces, so it only ever proved the key reached the payload.
    $view = makeSearchFiltersView(
        fullnameOf: '{"1":"Album"}',
        searchId: 'abc123',
        filesize: new RangeFilterOptions(
            list: '0.0,2.0,4.0',
            selected: new RangeBounds('2.0', '4.0'),
        ),
        height: new RangeFilterOptions(
            list: '150,300',
            selected: new RangeBounds('150', '300'),
        ),
        width: new RangeFilterOptions(
            list: '200,400',
            // An empty option set is the one case that produces null ends:
            // `$values[0] ?? null` and `end([])`'s own false, both
            // normalized to null by RangeBounds::value().
            selected: new RangeBounds(null, null),
        ),
        userRank: 'admin',
    );

    expect($view->exposedPageData())
        ->toBe([
            'global_params_json' => '{}',
            'user_rank' => 'admin',
            'show_filter_ratings' => true,
            'csrf_token' => 'token',
            'fullname_of_cat_json' => '{"1":"Album"}',
            'search_id' => 'abc123',
            'filesize' => [
                'list' => '0.0,2.0,4.0',
                'selected' => [
                    'min' => '2.0',
                    'max' => '4.0',
                ],
            ],
            'height' => [
                'list' => '150,300',
                'selected' => [
                    'min' => '150',
                    'max' => '300',
                ],
            ],
            'width' => [
                'list' => '200,400',
                'selected' => [
                    'min' => null,
                    'max' => null,
                ],
            ],
        ]);
});

test('pageAssets resolves the colorscheme-specific search stylesheet path', function (): void {
    $view = makeSearchFiltersView(colorscheme: 'dark');

    $paths = array_map(static fn ($asset) => $asset->path, $view->pageAssets());

    expect($paths)
        ->toContain('themes/default/css/dark-search.css');
});
