<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\SearchFiltersView;

/**
 * @param array<string, mixed> $filesize
 * @param array<string, mixed> $height
 * @param array<string, mixed> $width
 */
function makeSearchFiltersView(
    ?string $fullnameOf = null,
    ?string $searchId = null,
    ?array $filesize = null,
    ?array $height = null,
    ?array $width = null,
    string $userRank = 'none',
    string $colorscheme = 'light',
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
    );
}

test('exposedPageData omits every nullable field when unset', function (): void {
    $view = makeSearchFiltersView();

    expect($view->exposedPageData())
        ->toBe([
            'global_params_json' => '{}',
            'user_rank' => 'none',
            'show_filter_ratings' => true,
        ]);
});

test('exposedPageData includes every nullable field once set', function (): void {
    $view = makeSearchFiltersView(
        fullnameOf: '{"1":"Album"}',
        searchId: 'abc123',
        filesize: [
            'min' => 0,
            'max' => 100,
        ],
        height: [
            'min' => 0,
            'max' => 200,
        ],
        width: [
            'min' => 0,
            'max' => 300,
        ],
        userRank: 'admin',
    );

    expect($view->exposedPageData())
        ->toBe([
            'global_params_json' => '{}',
            'user_rank' => 'admin',
            'show_filter_ratings' => true,
            'fullname_of_cat_json' => '{"1":"Album"}',
            'search_id' => 'abc123',
            'filesize' => [
                'min' => 0,
                'max' => 100,
            ],
            'height' => [
                'min' => 0,
                'max' => 200,
            ],
            'width' => [
                'min' => 0,
                'max' => 300,
            ],
        ]);
});

test('pageAssets resolves the colorscheme-specific search stylesheet path', function (): void {
    $view = makeSearchFiltersView(colorscheme: 'dark');

    $paths = array_map(static fn ($asset) => $asset->path, $view->pageAssets());

    expect($paths)
        ->toContain('themes/default/css/dark-search.css');
});
