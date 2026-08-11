<?php

declare(strict_types=1);

use Piwigo\Notification\Projection\RecentCategoryForDate;

test('constructs with distinct values for every property', function (): void {
    $row = new RecentCategoryForDate('1,2', 3);

    expect($row->uppercats)
        ->toBe('1,2')
        ->and($row->imgCount)
        ->toBe(3);
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new RecentCategoryForDate('1,2', 3);

    expect($row->toArray())
        ->toBe([
            'uppercats' => '1,2',
            'img_count' => 3,
        ]);
});
