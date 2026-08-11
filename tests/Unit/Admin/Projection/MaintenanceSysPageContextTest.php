<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\MaintenanceSysPageContext;

test('toArray represents isWebmaster as an int 1 or 0', function (): void {
    expect(new MaintenanceSysPageContext(isWebmaster: true)->toArray())
        ->toBe([
            'isWebmaster' => 1,
        ])
        ->and(new MaintenanceSysPageContext(isWebmaster: false)->toArray())
        ->toBe([
            'isWebmaster' => 0,
        ]);
});
