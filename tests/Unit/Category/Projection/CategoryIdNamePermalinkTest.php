<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryIdNamePermalink;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryIdNamePermalink(1, 'Sample Album', 'sample-album');

    expect($row->id)
        ->toBe(1)
        ->and($row->name)
        ->toBe('Sample Album')
        ->and($row->permalink)
        ->toBe('sample-album');
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new CategoryIdNamePermalink(1, 'Sample Album', 'sample-album');

    expect($row->toArray())
        ->toBe([
            'id' => 1,
            'name' => 'Sample Album',
            'permalink' => 'sample-album',
        ]);
});

test('accepts a null permalink', function (): void {
    $row = new CategoryIdNamePermalink(1, 'Sample Album', null);

    expect($row->permalink)
        ->toBeNull()
        ->and($row->toArray()['permalink'])->toBeNull();
});
