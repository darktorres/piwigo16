<?php

declare(strict_types=1);

use Piwigo\Activity\Projection\UserAgentBreakdown;

test('constructs with distinct values for every property', function (): void {
    $row = new UserAgentBreakdown('PiwigoRepoTestAgent/1.0', 2, '2026-07-10 00:00:00', '2026-07-11 00:00:00');

    expect($row->userAgent)->toBe('PiwigoRepoTestAgent/1.0')
        ->and($row->counter)->toBe(2)
        ->and($row->firstEncounter)->toBe('2026-07-10 00:00:00')
        ->and($row->lastEncounter)->toBe('2026-07-11 00:00:00');
});

test('accepts a null userAgent/firstEncounter/lastEncounter', function (): void {
    $row = new UserAgentBreakdown(null, 0, null, null);

    expect($row->userAgent)->toBeNull()
        ->and($row->firstEncounter)->toBeNull()
        ->and($row->lastEncounter)->toBeNull();
});
