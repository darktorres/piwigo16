<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\MaintenanceSysPageContext;

test('toArray represents isWebmaster as an int 1 or 0', function (): void {
    expect(new MaintenanceSysPageContext(isWebmaster: true, activityLogEntries: [])->toArray())
        ->toBe([
            'isWebmaster' => 1,
            'ACTIVITY_LOG_ENTRIES' => [],
        ])
        ->and(new MaintenanceSysPageContext(isWebmaster: false, activityLogEntries: [])->toArray())
        ->toBe([
            'isWebmaster' => 0,
            'ACTIVITY_LOG_ENTRIES' => [],
        ]);
});

test('toArray passes activityLogEntries through unchanged', function (): void {
    $entries = [[
        'id' => 1,
        'object' => 'Core',
    ]];

    expect(new MaintenanceSysPageContext(isWebmaster: true, activityLogEntries: $entries)->toArray())
        ->toBe([
            'isWebmaster' => 1,
            'ACTIVITY_LOG_ENTRIES' => $entries,
        ]);
});
