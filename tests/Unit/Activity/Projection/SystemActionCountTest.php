<?php

declare(strict_types=1);

use Piwigo\Activity\Projection\SystemActionCount;

test('constructs with distinct values for every property', function (): void {
    // The second argument is an ActivitySystem constant (Theme = 3), not a
    // row id -- the overload Version20260804122302 split out of object_id.
    $row = new SystemActionCount('system', 3, 'activate', 1);

    expect($row->object)
        ->toBe('system')
        ->and($row->systemScope)
        ->toBe(3)
        ->and($row->action)
        ->toBe('activate')
        ->and($row->counter)
        ->toBe(1);
});
