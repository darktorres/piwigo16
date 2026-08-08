<?php

declare(strict_types=1);

use Piwigo\Rate\Projection\RateSummaryForElement;

test('constructs with the given count and average', function (): void {
    $summary = new RateSummaryForElement(2, 4.5);

    expect($summary->count)->toBe(2)
        ->and($summary->average)->toBe(4.5);
});

test('constructs with a null average', function (): void {
    $summary = new RateSummaryForElement(0, null);

    expect($summary->average)->toBeNull();
});
