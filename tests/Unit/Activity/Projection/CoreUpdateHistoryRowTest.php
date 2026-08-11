<?php

declare(strict_types=1);

use Piwigo\Activity\Projection\CoreUpdateHistoryRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CoreUpdateHistoryRow('update', '2026-07-10 00:00:00', '{"from_version":"16.0.0","to_version":"17.0.0"}');

    expect($row->action)
        ->toBe('update')
        ->and($row->occuredOn)
        ->toBe('2026-07-10 00:00:00')
        ->and($row->details)
        ->toBe('{"from_version":"16.0.0","to_version":"17.0.0"}');
});

test('accepts a null occuredOn/details', function (): void {
    $row = new CoreUpdateHistoryRow('update', null, null);

    expect($row->occuredOn)
        ->toBeNull()
        ->and($row->details)
        ->toBeNull();
});
