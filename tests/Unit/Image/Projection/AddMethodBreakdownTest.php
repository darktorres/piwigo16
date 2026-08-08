<?php

declare(strict_types=1);

use Piwigo\Image\Projection\AddMethodBreakdown;

test('constructs with distinct values for every property', function (): void {
    $row = new AddMethodBreakdown('web', '2026-08-01 12:00:00', 4);

    expect($row->addMethod)->toBe('web')
        ->and($row->lastAddedOn)->toBe('2026-08-01 12:00:00')
        ->and($row->nbFiles)->toBe(4);
});

test('toArray round-trips to the snake_case shape', function (): void {
    $row = new AddMethodBreakdown('web', '2026-08-01 12:00:00', 4);

    expect($row->toArray())->toBe([
        'add_method' => 'web',
        'last_added_on' => '2026-08-01 12:00:00',
        'nb_files' => 4,
    ]);
});

test('accepts a null lastAddedOn', function (): void {
    $row = new AddMethodBreakdown('ftp', null, 0);

    expect($row->lastAddedOn)->toBeNull()
        ->and($row->toArray()['last_added_on'])->toBeNull();
});
