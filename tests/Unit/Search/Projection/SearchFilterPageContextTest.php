<?php

declare(strict_types=1);

use Piwigo\Search\Projection\SearchFilterPageContext;

$makeContext = fn (array $overrides = []): SearchFilterPageContext => new SearchFilterPageContext(
    ...array_merge(
        [
            'displayFilter' => ['tags' => ['access' => true]],
            'tags' => null,
            'authors' => null,
            'addedBy' => null,
            'fullnameOf' => null,
            'filetypes' => null,
            'showFilterRatings' => true,
            'rating' => null,
            'filesize' => null,
            'ratios' => null,
            'height' => null,
            'width' => null,
            'gp' => '{"fields":[]}',
            'searchId' => '123-abc',
        ],
        $overrides
    )
);

test('toArray flattens every fixed property, and omits every optional key when null', function () use ($makeContext): void {
    $result = $makeContext()->toArray();

    expect($result)->not->toHaveKeys(['TAGS', 'AUTHORS', 'ADDED_BY', 'fullname_of', 'FILETYPES', 'RATING', 'FILESIZE', 'RATIOS', 'HEIGHT', 'WIDTH'])
        ->and($result['display_filter'])->toBe(['tags' => ['access' => true]])
        ->and($result['SHOW_FILTER_RATINGS'])->toBeTrue()
        ->and($result['GP'])->toBe('{"fields":[]}')
        ->and($result['SEARCH_ID'])->toBe('123-abc');
});

test('toArray includes every optional key when set', function () use ($makeContext): void {
    $result = $makeContext([
        'tags' => [['id' => 1, 'name' => 'sunset']],
        'authors' => [['author' => 'admin']],
        'addedBy' => [['added_by_id' => 2, 'added_by_name' => 'admin']],
        'fullnameOf' => '{"5":"Holidays"}',
        'filetypes' => ['jpg' => 3],
        'rating' => [0, 0, 1, 2, 3, 4],
        'filesize' => ['list' => '0.0,1.0', 'bounds' => ['min' => '0.0', 'max' => '1.0'], 'selected' => ['min' => '0.0', 'max' => '1.0']],
        'ratios' => ['Portrait' => 1, 'square' => 0, 'Landscape' => 2, 'Panorama' => 0],
        'height' => ['list' => '100,200', 'bounds' => ['min' => '100', 'max' => '200'], 'selected' => ['min' => '100', 'max' => '200']],
        'width' => ['list' => '100,200', 'bounds' => ['min' => '100', 'max' => '200'], 'selected' => ['min' => '100', 'max' => '200']],
    ])->toArray();

    expect($result['TAGS'])->toBe([['id' => 1, 'name' => 'sunset']])
        ->and($result['AUTHORS'])->toBe([['author' => 'admin']])
        ->and($result['ADDED_BY'])->toBe([['added_by_id' => 2, 'added_by_name' => 'admin']])
        ->and($result['fullname_of'])->toBe('{"5":"Holidays"}')
        ->and($result['FILETYPES'])->toBe(['jpg' => 3])
        ->and($result['RATING'])->toBe([0, 0, 1, 2, 3, 4])
        ->and($result['FILESIZE']['list'])->toBe('0.0,1.0')
        ->and($result['RATIOS']['Landscape'])->toBe(2)
        ->and($result['HEIGHT']['list'])->toBe('100,200')
        ->and($result['WIDTH']['list'])->toBe('100,200');
});

test('toArray preserves a false fullnameOf/gp value distinctly from null', function () use ($makeContext): void {
    $result = $makeContext(['fullnameOf' => false, 'gp' => false])->toArray();

    expect($result['fullname_of'])->toBeFalse()
        ->and($result['GP'])->toBeFalse();
});
