<?php

declare(strict_types=1);

use Piwigo\Activity\Projection\SystemActionCount;

test('constructs with distinct values for every property', function (): void {
    $row = new SystemActionCount('system', 3, 'activate', 1);

    expect($row->object)->toBe('system')
        ->and($row->objectId)->toBe(3)
        ->and($row->action)->toBe('activate')
        ->and($row->counter)->toBe(1);
});
