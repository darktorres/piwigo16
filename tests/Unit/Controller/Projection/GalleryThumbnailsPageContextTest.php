<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\GalleryThumbnailsPageContext;

$makeContext = fn (array $overrides = []): GalleryThumbnailsPageContext => new GalleryThumbnailsPageContext(
    ...array_merge(
        [
            'searchInSetButton' => null,
            'searchInSetAction' => null,
            'searchInSetUrl' => null,
            'combinableTags' => null,
            'uEdit' => null,
            'uCaddie' => null,
            'categorySearchResults' => null,
            'noSearchResults' => null,
            'imageOrders' => null,
            'contentDescription' => null,
            'uSlideshow' => null,
            'relatedTagsAction' => null,
            'relatedTags' => null,
        ],
        $overrides
    )
);

test('toArray omits every optional key when null', function () use ($makeContext): void {
    $result = $makeContext()->toArray();

    expect($result)->toBe([]);
});

test('toArray includes the search-in-set-for-tags quartet together', function () use ($makeContext): void {
    $result = $makeContext([
        'searchInSetButton' => true,
        'searchInSetAction' => 'action.php',
        'searchInSetUrl' => '/search.php?tag_id=1,2',
        'combinableTags' => [['id' => 1, 'name' => 'sunset']],
    ])->toArray();

    expect($result['SEARCH_IN_SET_BUTTON'])->toBeTrue()
        ->and($result['SEARCH_IN_SET_ACTION'])->toBe('action.php')
        ->and($result['SEARCH_IN_SET_URL'])->toBe('/search.php?tag_id=1,2')
        ->and($result['COMBINABLE_TAGS'])->toBe([['id' => 1, 'name' => 'sunset']]);
});

test('toArray includes every other optional key when set', function () use ($makeContext): void {
    $result = $makeContext([
        'uEdit' => '/admin.php?page=album-1',
        'uCaddie' => '/index.php?caddie=1',
        'categorySearchResults' => ['Holidays'],
        'noSearchResults' => ['balloon'],
        'imageOrders' => [0 => ['DISPLAY' => 'Default', 'URL' => '/index.php?image_order=0', 'SELECTED' => true]],
        'contentDescription' => 'A gallery of holidays',
        'uSlideshow' => '/index.php?slideshow',
    ])->toArray();

    expect($result['U_EDIT'])->toBe('/admin.php?page=album-1')
        ->and($result['U_CADDIE'])->toBe('/index.php?caddie=1')
        ->and($result['category_search_results'])->toBe(['Holidays'])
        ->and($result['no_search_results'])->toBe(['balloon'])
        ->and($result['image_orders'])->toBe([0 => ['DISPLAY' => 'Default', 'URL' => '/index.php?image_order=0', 'SELECTED' => true]])
        ->and($result['CONTENT_DESCRIPTION'])->toBe('A gallery of holidays')
        ->and($result['U_SLIDESHOW'])->toBe('/index.php?slideshow');
});

test('toArray includes RELATED_TAGS_ACTION and RELATED_TAGS together, even when the action is false', function () use ($makeContext): void {
    $result = $makeContext([
        'relatedTagsAction' => false,
        'relatedTags' => [],
    ])->toArray();

    expect($result['RELATED_TAGS_ACTION'])->toBeFalse()
        ->and($result['RELATED_TAGS'])->toBe([]);
});
