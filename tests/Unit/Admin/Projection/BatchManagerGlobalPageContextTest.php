<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\BatchManagerGlobalPageContext;
use Piwigo\Core\Projection\Navbar;

test('toArray flattens every fixed property, and omits associated_tags/navbar/thumb_params when null', function (): void {
    $context = new BatchManagerGlobalPageContext(
        inCaddie: false,
        associatedTags: null,
        dateCreation: '2026-08-08 00:00:00',
        levelOptions: [
            0 => 'Everybody',
        ],
        levelOptionsSelected: 0,
        usedMetadata: 'iptc, exif',
        delDerivativesTypes: [
            'sq' => 'Square',
        ],
        generateDerivativesTypes: [
            'sq' => 'Square',
        ],
        navbar: null,
        thumbParams: null,
        nbThumbsPage: 0,
        nbThumbsSet: 0,
        cacheKeys: [
            'tags' => 'x',
            'categories' => 'y',
        ],
        thumbnails: [],
    );

    expect($context->toArray())
        ->toBe([
            'IN_CADDIE' => false,
            'DATE_CREATION' => '2026-08-08 00:00:00',
            'level_options' => [
                0 => 'Everybody',
            ],
            'level_options_selected' => 0,
            'used_metadata' => 'iptc, exif',
            'del_derivatives_types' => [
                'sq' => 'Square',
            ],
            'generate_derivatives_types' => [
                'sq' => 'Square',
            ],
            'nb_thumbs_page' => 0,
            'nb_thumbs_set' => 0,
            'CACHE_KEYS' => [
                'tags' => 'x',
                'categories' => 'y',
            ],
            'thumbnails' => [],
        ]);
});

test('toArray includes associated_tags/navbar when set', function (): void {
    $context = new BatchManagerGlobalPageContext(
        inCaddie: true,
        associatedTags: [[
            'id' => 1,
            'name' => 'sunset',
            'url_name' => 'sunset',
            'lastmodified' => '2026-08-08',
            'counter' => 3,
        ]],
        dateCreation: '2026-08-08 00:00:00',
        levelOptions: [
            0 => 'Everybody',
        ],
        levelOptionsSelected: 0,
        usedMetadata: 'iptc, exif',
        delDerivativesTypes: [
            'sq' => 'Square',
        ],
        generateDerivativesTypes: [
            'sq' => 'Square',
        ],
        navbar: new Navbar(nbPage: 3),
        thumbParams: null,
        nbThumbsPage: 10,
        nbThumbsSet: 42,
        cacheKeys: [],
        thumbnails: [[
            'id' => 5,
            'TITLE' => 'Sunset',
        ]],
    );

    $result = $context->toArray();

    expect($result['IN_CADDIE'])->toBeTrue()
        ->and($result['associated_tags'])->toBe([[
            'id' => 1,
            'name' => 'sunset',
            'url_name' => 'sunset',
            'lastmodified' => '2026-08-08',
            'counter' => 3,
        ]])
        ->and($result['navbar'])->toBe([
            'NB_PAGE' => 3,
        ])
        ->and($result['thumbnails'])->toBe([[
            'id' => 5,
            'TITLE' => 'Sunset',
        ]])
        ->and($result)
        ->not->toHaveKey('thumb_params');
});
