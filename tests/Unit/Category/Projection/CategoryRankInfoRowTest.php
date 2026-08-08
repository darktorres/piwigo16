<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryRankInfoRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryRankInfoRow(2, 1, 1);

    expect($row->id)->toBe(2)
        ->and($row->idUppercat)->toBe(1)
        ->and($row->rank)->toBe(1);
});

test('accepts a null idUppercat/rank', function (): void {
    $row = new CategoryRankInfoRow(1, null, null);

    expect($row->idUppercat)->toBeNull()
        ->and($row->rank)->toBeNull();
});
