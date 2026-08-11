<?php

declare(strict_types=1);

use Piwigo\Activity\Projection\DailyActionCount;

test('constructs with distinct values for every property', function (): void {
    $row = new DailyActionCount('2026-08-01', 'tag', 'add', 3);

    expect($row->activityDay)
        ->toBe('2026-08-01')
        ->and($row->object)
        ->toBe('tag')
        ->and($row->action)
        ->toBe('add')
        ->and($row->counter)
        ->toBe(3);
});
