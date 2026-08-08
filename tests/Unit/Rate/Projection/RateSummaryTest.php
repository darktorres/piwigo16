<?php

declare(strict_types=1);

use Piwigo\Rate\Projection\RateSummary;

test('constructs with the given rcount and rsum', function (): void {
    $summary = new RateSummary(2, 9.0);

    expect($summary->rcount)->toBe(2)
        ->and($summary->rsum)->toBe(9.0);
});
