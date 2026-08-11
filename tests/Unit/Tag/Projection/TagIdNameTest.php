<?php

declare(strict_types=1);

use Piwigo\Tag\Projection\TagIdName;

test('constructs with the given id and name', function (): void {
    $tag = new TagIdName(3, 'nature');

    expect($tag->id)
        ->toBe(3)
        ->and($tag->name)
        ->toBe('nature');
});

test('toArray round-trips the id and name', function (): void {
    $tag = new TagIdName(3, 'nature');

    expect($tag->toArray())
        ->toBe([
            'id' => 3,
            'name' => 'nature',
        ]);
});
