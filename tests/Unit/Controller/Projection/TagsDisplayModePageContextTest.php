<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\TagsDisplayModePageContext;

test('toArray flattens every property, and omits letters/tags when null', function (): void {
    $context = new TagsDisplayModePageContext(
        cloudUrl: '/tags.php',
        lettersUrl: '/tags.php?display_mode=letters',
        displayMode: 'cloud',
        letters: null,
        tags: null,
    );

    expect($context->toArray())
        ->toBe([
            'U_CLOUD' => '/tags.php',
            'U_LETTERS' => '/tags.php?display_mode=letters',
            'display_mode' => 'cloud',
        ]);
});

test('toArray includes tags when set (cloud mode)', function (): void {
    $context = new TagsDisplayModePageContext(
        cloudUrl: '/tags.php',
        lettersUrl: '/tags.php?display_mode=letters',
        displayMode: 'cloud',
        letters: null,
        tags: [[
            'name' => 'sunset',
            'URL' => '/index.php?/tags/1',
        ]],
    );

    expect($context->toArray()['tags'])->toBe([[
        'name' => 'sunset',
        'URL' => '/index.php?/tags/1',
    ]])
        ->and($context->toArray())
        ->not->toHaveKey('letters');
});

test('toArray includes letters when set (letters mode)', function (): void {
    $context = new TagsDisplayModePageContext(
        cloudUrl: '/tags.php',
        lettersUrl: '/tags.php?display_mode=letters',
        displayMode: 'letters',
        letters: [[
            'TITLE' => 'S',
            'tags' => [[
                'name' => 'sunset',
            ]],
        ]],
        tags: null,
    );

    expect($context->toArray()['letters'])->toBe([[
        'TITLE' => 'S',
        'tags' => [[
            'name' => 'sunset',
        ]],
    ]])
        ->and($context->toArray())
        ->not->toHaveKey('tags');
});
