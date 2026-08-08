<?php

declare(strict_types=1);

use Piwigo\History\Projection\GroupedCountSince;

test('constructs with distinct values for every property', function (): void {
    $row = new GroupedCountSince('2026-07-12', 3, 10, 20, 5);

    expect($row->date)->toBe('2026-07-12')
        ->and($row->hour)->toBe(3)
        ->and($row->minId)->toBe(10)
        ->and($row->maxId)->toBe(20)
        ->and($row->nbPages)->toBe(5);
});
