<?php

declare(strict_types=1);

use Piwigo\Section\SectionContext;

test('constructor assigns every property as given', function (): void {
    $context = new SectionContext(
        rootPath: '../../',
        sectionUrl: '/category/12-foo/start-24',
        tokens: ['category', '12-foo', 'start-24'],
        nextToken: 1,
        imageId: null,
        imageFile: null,
        parsed: ['section' => 'categories', 'category' => ['id' => 12]],
    );

    expect($context->rootPath)->toBe('../../')
        ->and($context->sectionUrl)->toBe('/category/12-foo/start-24')
        ->and($context->tokens)->toBe(['category', '12-foo', 'start-24'])
        ->and($context->nextToken)->toBe(1)
        ->and($context->imageId)->toBeNull()
        ->and($context->imageFile)->toBeNull()
        ->and($context->parsed)->toBe(['section' => 'categories', 'category' => ['id' => 12]]);
});

test('constructor accepts a picture page image id and file slug', function (): void {
    $context = new SectionContext(
        rootPath: '../',
        sectionUrl: '/42-my-photo',
        tokens: ['42-my-photo'],
        nextToken: 1,
        imageId: '42',
        imageFile: 'my-photo',
        parsed: [],
    );

    expect($context->imageId)->toBe('42')
        ->and($context->imageFile)->toBe('my-photo');
});
