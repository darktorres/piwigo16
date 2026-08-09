<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\GalleryThumbnailsPageContext;

/**
 * @param list<array<string, mixed>>|null $combinableTags
 * @param list<string>|null $categorySearchResults
 * @param list<string>|null $noSearchResults
 * @param array<int, array<string, mixed>>|null $imageOrders
 * @param list<array<string, mixed>>|null $relatedTags
 * @param list<array<string, mixed>> $tagSearchResults
 * @param list<array{DISPLAY: string, URL: string, SELECTED: bool}> $imageDerivatives
 */
function makeGalleryThumbnailsPageContextForTest(
    ?bool $searchInSetButton = null,
    ?string $searchInSetAction = null,
    ?string $searchInSetUrl = null,
    ?array $combinableTags = null,
    ?string $uEdit = null,
    ?string $uCaddie = null,
    ?array $categorySearchResults = null,
    ?array $noSearchResults = null,
    ?array $imageOrders = null,
    ?string $contentDescription = null,
    ?string $uSlideshow = null,
    ?bool $relatedTagsAction = null,
    ?array $relatedTags = null,
    array $tagSearchResults = [],
    array $imageDerivatives = [],
): GalleryThumbnailsPageContext {
    return new GalleryThumbnailsPageContext(
        searchInSetButton: $searchInSetButton,
        searchInSetAction: $searchInSetAction,
        searchInSetUrl: $searchInSetUrl,
        combinableTags: $combinableTags,
        uEdit: $uEdit,
        uCaddie: $uCaddie,
        categorySearchResults: $categorySearchResults,
        noSearchResults: $noSearchResults,
        imageOrders: $imageOrders,
        contentDescription: $contentDescription,
        uSlideshow: $uSlideshow,
        relatedTagsAction: $relatedTagsAction,
        relatedTags: $relatedTags,
        tagSearchResults: $tagSearchResults,
        imageDerivatives: $imageDerivatives,
    );
}

test('toArray omits every optional key when null, but always includes tag_search_results/image_derivatives', function (): void {
    $result = makeGalleryThumbnailsPageContextForTest()->toArray();

    expect($result)->toBe(['tag_search_results' => [], 'image_derivatives' => []]);
});

test('toArray includes real tag_search_results/image_derivatives when set', function (): void {
    $result = makeGalleryThumbnailsPageContextForTest(
        tagSearchResults: [['id' => 1, 'name' => 'sunset', 'URL' => '/index.php?/tags/1']],
        imageDerivatives: [['DISPLAY' => 'Small', 'URL' => '/index.php?display=SM', 'SELECTED' => true]],
    )->toArray();

    expect($result['tag_search_results'])->toBe([['id' => 1, 'name' => 'sunset', 'URL' => '/index.php?/tags/1']])
        ->and($result['image_derivatives'])->toBe([['DISPLAY' => 'Small', 'URL' => '/index.php?display=SM', 'SELECTED' => true]]);
});

test('toArray includes the search-in-set-for-tags quartet together', function (): void {
    $result = makeGalleryThumbnailsPageContextForTest(
        searchInSetButton: true,
        searchInSetAction: 'action.php',
        searchInSetUrl: '/search.php?tag_id=1,2',
        combinableTags: [['id' => 1, 'name' => 'sunset']],
    )->toArray();

    expect($result['SEARCH_IN_SET_BUTTON'])->toBeTrue()
        ->and($result['SEARCH_IN_SET_ACTION'])->toBe('action.php')
        ->and($result['SEARCH_IN_SET_URL'])->toBe('/search.php?tag_id=1,2')
        ->and($result['COMBINABLE_TAGS'])->toBe([['id' => 1, 'name' => 'sunset']]);
});

test('toArray includes every other optional key when set', function (): void {
    $result = makeGalleryThumbnailsPageContextForTest(
        uEdit: '/admin.php?page=album-1',
        uCaddie: '/index.php?caddie=1',
        categorySearchResults: ['Holidays'],
        noSearchResults: ['balloon'],
        imageOrders: [0 => ['DISPLAY' => 'Default', 'URL' => '/index.php?image_order=0', 'SELECTED' => true]],
        contentDescription: 'A gallery of holidays',
        uSlideshow: '/index.php?slideshow',
    )->toArray();

    expect($result['U_EDIT'])->toBe('/admin.php?page=album-1')
        ->and($result['U_CADDIE'])->toBe('/index.php?caddie=1')
        ->and($result['category_search_results'])->toBe(['Holidays'])
        ->and($result['no_search_results'])->toBe(['balloon'])
        ->and($result['image_orders'])->toBe([0 => ['DISPLAY' => 'Default', 'URL' => '/index.php?image_order=0', 'SELECTED' => true]])
        ->and($result['CONTENT_DESCRIPTION'])->toBe('A gallery of holidays')
        ->and($result['U_SLIDESHOW'])->toBe('/index.php?slideshow');
});

test('toArray includes RELATED_TAGS_ACTION and RELATED_TAGS together, even when the action is false', function (): void {
    $result = makeGalleryThumbnailsPageContextForTest(
        relatedTagsAction: false,
        relatedTags: [],
    )->toArray();

    expect($result['RELATED_TAGS_ACTION'])->toBeFalse()
        ->and($result['RELATED_TAGS'])->toBe([]);
});
