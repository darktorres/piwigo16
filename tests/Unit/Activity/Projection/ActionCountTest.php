<?php

declare(strict_types=1);

use Piwigo\Activity\Projection\ActionCount;

test('constructs with distinct values for every property', function (): void {
    $row = new ActionCount('tag', 'add', 3);

    expect($row->object)->toBe('tag')
        ->and($row->action)->toBe('add')
        ->and($row->counter)->toBe(3);
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new ActionCount('tag', 'add', 3);

    expect($row->toArray())->toBe([
        'object' => 'tag',
        'action' => 'add',
        'counter' => 3,
    ]);
});
