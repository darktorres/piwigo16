<?php

declare(strict_types=1);

use Piwigo\Section\SectionUrlParse;

test('constructor assigns every property as given', function (): void {
    $parse = new SectionUrlParse(
        rootPath: '../../',
        sectionUrl: '/category/12-foo/start-24',
        tokens: ['category', '12-foo', 'start-24'],
        nextToken: 1,
        imageId: null,
        imageFile: null,
        parsed: [
            'section' => 'categories',
            'category' => [
                'id' => 12,
            ],
        ],
    );

    expect($parse->rootPath)
        ->toBe('../../')
        ->and($parse->sectionUrl)
        ->toBe('/category/12-foo/start-24')
        ->and($parse->tokens)
        ->toBe(['category', '12-foo', 'start-24'])
        ->and($parse->nextToken)
        ->toBe(1)
        ->and($parse->imageId)
        ->toBeNull()
        ->and($parse->imageFile)
        ->toBeNull()
        ->and($parse->parsed)
        ->toBe([
            'section' => 'categories',
            'category' => [
                'id' => 12,
            ],
        ]);
});

test('constructor accepts a picture page image id and file slug', function (): void {
    $parse = new SectionUrlParse(
        rootPath: '../',
        sectionUrl: '/42-my-photo',
        tokens: ['42-my-photo'],
        nextToken: 1,
        imageId: '42',
        imageFile: 'my-photo',
        parsed: [],
    );

    expect($parse->imageId)
        ->toBe('42')
        ->and($parse->imageFile)
        ->toBe('my-photo');
});
