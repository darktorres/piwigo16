<?php

declare(strict_types=1);

use Piwigo\Picture\Projection\PictureRateSummaryPageContext;

test('toArray flattens the rate summary', function (): void {
    $summary = [
        'count' => 3,
        'score' => 4.2,
        'average' => 4.2,
    ];

    expect(new PictureRateSummaryPageContext($summary)->toArray())
        ->toBe([
            'rate_summary' => $summary,
        ]);
});
