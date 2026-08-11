<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryUppercatsCounter;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryUppercatsCounter(1, '1', 3);

    expect($row->id)
        ->toBe(1)
        ->and($row->uppercats)
        ->toBe('1')
        ->and($row->counter)
        ->toBe(3);
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new CategoryUppercatsCounter(1, '1', 3);

    expect($row->toArray())
        ->toBe([
            'id' => 1,
            'uppercats' => '1',
            'counter' => 3,
        ]);
});
