<?php

declare(strict_types=1);

use Piwigo\Search\Projection\SearchFilterPageContext;

/**
 * @param list<array<array-key, mixed>>|null $tags
 * @param list<array<array-key, mixed>>|null $authors
 * @param list<array<array-key, mixed>>|null $addedBy
 * @param array<array-key, mixed>|null $filetypes
 * @param array<array-key, mixed>|null $rating
 * @param array<string, mixed>|null $filesize
 * @param array<array-key, mixed>|null $ratios
 * @param array<string, mixed>|null $height
 * @param array<string, mixed>|null $width
 */
function makeSearchFilterPageContextForTest(
    ?array $tags = null,
    ?array $authors = null,
    ?array $addedBy = null,
    string|false|null $fullnameOf = null,
    ?array $filetypes = null,
    ?array $rating = null,
    ?array $filesize = null,
    ?array $ratios = null,
    ?array $height = null,
    ?array $width = null,
    string|false $gp = '{"fields":[]}',
): SearchFilterPageContext {
    return new SearchFilterPageContext(
        displayFilter: [
            'tags' => [
                'access' => true,
            ],
        ],
        tags: $tags,
        authors: $authors,
        addedBy: $addedBy,
        fullnameOf: $fullnameOf,
        filetypes: $filetypes,
        showFilterRatings: true,
        rating: $rating,
        filesize: $filesize,
        ratios: $ratios,
        height: $height,
        width: $width,
        gp: $gp,
        searchId: '123-abc',
    );
}

test('toArray flattens every fixed property, and omits every optional key when null', function (): void {
    $result = makeSearchFilterPageContextForTest()
        ->toArray();

    expect($result)
        ->not->toHaveKeys(['TAGS', 'AUTHORS', 'ADDED_BY', 'fullname_of', 'FILETYPES', 'RATING', 'FILESIZE', 'RATIOS', 'HEIGHT', 'WIDTH'])
        ->and($result['display_filter'])->toBe([
            'tags' => [
                'access' => true,
            ],
        ])
        ->and($result['SHOW_FILTER_RATINGS'])->toBeTrue()
        ->and($result['GP'])->toBe('{"fields":[]}')
        ->and($result['SEARCH_ID'])->toBe('123-abc');
});

test('toArray includes every optional key when set', function (): void {
    $result = makeSearchFilterPageContextForTest(
        tags: [[
            'id' => 1,
            'name' => 'sunset',
        ]],
        authors: [[
            'author' => 'admin',
        ]],
        addedBy: [[
            'added_by_id' => 2,
            'added_by_name' => 'admin',
        ]],
        fullnameOf: '{"5":"Holidays"}',
        filetypes: [
            'jpg' => 3,
        ],
        rating: [0, 0, 1, 2, 3, 4],
        filesize: [
            'list' => '0.0,1.0',
            'bounds' => [
                'min' => '0.0',
                'max' => '1.0',
            ],
            'selected' => [
                'min' => '0.0',
                'max' => '1.0',
            ],
        ],
        ratios: [
            'Portrait' => 1,
            'square' => 0,
            'Landscape' => 2,
            'Panorama' => 0,
        ],
        height: [
            'list' => '100,200',
            'bounds' => [
                'min' => '100',
                'max' => '200',
            ],
            'selected' => [
                'min' => '100',
                'max' => '200',
            ],
        ],
        width: [
            'list' => '100,200',
            'bounds' => [
                'min' => '100',
                'max' => '200',
            ],
            'selected' => [
                'min' => '100',
                'max' => '200',
            ],
        ],
    )->toArray();

    expect($result['TAGS'])->toBe([[
        'id' => 1,
        'name' => 'sunset',
    ]])
        ->and($result['AUTHORS'])->toBe([[
            'author' => 'admin',
        ]])
        ->and($result['ADDED_BY'])->toBe([[
            'added_by_id' => 2,
            'added_by_name' => 'admin',
        ]])
        ->and($result['fullname_of'])->toBe('{"5":"Holidays"}')
        ->and($result['FILETYPES'])->toBe([
            'jpg' => 3,
        ])
        ->and($result['RATING'])->toBe([0, 0, 1, 2, 3, 4])
        ->and($result['FILESIZE'])->toBe([
            'list' => '0.0,1.0',
            'bounds' => [
                'min' => '0.0',
                'max' => '1.0',
            ],
            'selected' => [
                'min' => '0.0',
                'max' => '1.0',
            ],
        ])
        ->and($result['RATIOS'])->toBe([
            'Portrait' => 1,
            'square' => 0,
            'Landscape' => 2,
            'Panorama' => 0,
        ])
        ->and($result['HEIGHT'])->toBe([
            'list' => '100,200',
            'bounds' => [
                'min' => '100',
                'max' => '200',
            ],
            'selected' => [
                'min' => '100',
                'max' => '200',
            ],
        ])
        ->and($result['WIDTH'])->toBe([
            'list' => '100,200',
            'bounds' => [
                'min' => '100',
                'max' => '200',
            ],
            'selected' => [
                'min' => '100',
                'max' => '200',
            ],
        ]);
});

test('toArray preserves a false fullnameOf/gp value distinctly from null', function (): void {
    $result = makeSearchFilterPageContextForTest(fullnameOf: false, gp: false)
        ->toArray();

    expect($result['fullname_of'])->toBeFalse()
        ->and($result['GP'])->toBeFalse();
});
