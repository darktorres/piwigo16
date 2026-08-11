<?php

declare(strict_types=1);

use Piwigo\Notification\Projection\RecentPostDate;

test('constructs with distinct values for every property', function (): void {
    $row = new RecentPostDate('2026-07-07 05:02:36', 2, 1);

    expect($row->dateAvailable)
        ->toBe('2026-07-07 05:02:36')
        ->and($row->nbElements)
        ->toBe(2)
        ->and($row->nbCats)
        ->toBe(1);
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new RecentPostDate('2026-07-07 05:02:36', 2, 1);

    expect($row->toArray())
        ->toBe([
            'date_available' => '2026-07-07 05:02:36',
            'nb_elements' => 2,
            'nb_cats' => 1,
        ]);
});

test('accepts a null dateAvailable', function (): void {
    $row = new RecentPostDate(null, 0, 0);

    expect($row->dateAvailable)
        ->toBeNull()
        ->and($row->toArray()['date_available'])->toBeNull();
});
