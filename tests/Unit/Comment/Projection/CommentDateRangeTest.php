<?php

declare(strict_types=1);

use Piwigo\Comment\Projection\CommentDateRange;

test('constructs with the given started_at and ended_at', function (): void {
    $range = new CommentDateRange('2026-07-01 00:00:00', '2026-08-01 00:00:00');

    expect($range->startedAt)->toBe('2026-07-01 00:00:00')
        ->and($range->endedAt)->toBe('2026-08-01 00:00:00');
});

test('constructs with null values', function (): void {
    $range = new CommentDateRange(null, null);

    expect($range->startedAt)->toBeNull()
        ->and($range->endedAt)->toBeNull();
});
