<?php

declare(strict_types=1);

use Piwigo\Comment\Projection\CommentSummaryCounts;

test('constructs with distinct values for every property', function (): void {
    $summary = new CommentSummaryCounts(5, 3, 2);

    expect($summary->allComments)
        ->toBe(5)
        ->and($summary->validated)
        ->toBe(3)
        ->and($summary->pending)
        ->toBe(2);
});
