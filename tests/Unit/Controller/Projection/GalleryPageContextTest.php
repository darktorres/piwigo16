<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\GalleryPageContext;

$makeContext = fn (array $overrides = []): GalleryPageContext => new GalleryPageContext(
    ...array_merge(
        [
            'thumbNavbar' => ['NB_PAGE' => 3],
            'uCanonical' => '/index.php?/category/1',
            'useStandardPages' => true,
            'title' => 'Holidays',
            'nbItems' => 42,
            'uModeNormal' => null,
            'uModeFlat' => null,
            'uModeCreated' => null,
            'uModePosted' => null,
            'searchInSetButton' => null,
            'searchInSetAction' => null,
            'searchInSetUrl' => null,
            'selectRelatedTags' => null,
        ],
        $overrides
    )
);

test('toArray flattens every fixed property, and omits every optional key when null', function () use ($makeContext): void {
    $result = $makeContext()->toArray();

    expect($result)->not->toHaveKeys(['U_MODE_NORMAL', 'U_MODE_FLAT', 'U_MODE_CREATED', 'U_MODE_POSTED', 'SEARCH_IN_SET_BUTTON', 'SEARCH_IN_SET_ACTION', 'SEARCH_IN_SET_URL', 'SELECT_RELATED_TAGS'])
        ->and($result['thumb_navbar'])->toBe(['NB_PAGE' => 3])
        ->and($result['TITLE'])->toBe('Holidays')
        ->and($result['NB_ITEMS'])->toBe(42);
});

test('toArray includes U_MODE_CREATED and U_MODE_POSTED independently', function () use ($makeContext): void {
    $result = $makeContext(['uModeCreated' => '/index.php?chronology_field=created'])->toArray();

    expect($result['U_MODE_CREATED'])->toBe('/index.php?chronology_field=created')
        ->and($result)->not->toHaveKey('U_MODE_POSTED');

    $result2 = $makeContext(['uModePosted' => '/index.php?chronology_field=posted'])->toArray();

    expect($result2['U_MODE_POSTED'])->toBe('/index.php?chronology_field=posted')
        ->and($result2)->not->toHaveKey('U_MODE_CREATED');
});

test('toArray includes the search-in-set trio together', function () use ($makeContext): void {
    $result = $makeContext([
        'searchInSetButton' => true,
        'searchInSetAction' => 'action.php',
        'searchInSetUrl' => '/search.php?cat_id=5',
    ])->toArray();

    expect($result['SEARCH_IN_SET_BUTTON'])->toBeTrue()
        ->and($result['SEARCH_IN_SET_ACTION'])->toBe('action.php')
        ->and($result['SEARCH_IN_SET_URL'])->toBe('/search.php?cat_id=5');
});

test('toArray includes selectRelatedTags when set', function () use ($makeContext): void {
    $result = $makeContext(['selectRelatedTags' => ['sunset' => ['tag_name' => 'sunset']]])->toArray();

    expect($result['SELECT_RELATED_TAGS'])->toBe(['sunset' => ['tag_name' => 'sunset']]);
});
