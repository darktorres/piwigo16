<?php

declare(strict_types=1);

use Piwigo\Admin\BatchManager\Projection\DimensionFilter;

test('fromArray narrows every numeric bound to its real type', function (): void {
    // Every bound passed as a numeric string -- proves each (int)/(float)
    // cast itself does the narrowing, not just that the value round-trips
    // (an already-int/float literal would pass even if its cast were
    // silently removed).
    $filter = DimensionFilter::fromArray([
        'min_width' => '100',
        'max_width' => '200',
        'min_height' => '50',
        'max_height' => '75',
        'min_ratio' => '1.5',
        'max_ratio' => '2.5',
    ]);

    expect($filter->minWidth)
        ->toBe(100)
        ->and($filter->maxWidth)
        ->toBe(200)
        ->and($filter->minHeight)
        ->toBe(50)
        ->and($filter->maxHeight)
        ->toBe(75)
        ->and($filter->minRatio)
        ->toBe(1.5)
        ->and($filter->maxRatio)
        ->toBe(2.5);
});

test('fromArray defaults every bound to null when absent or non-numeric', function (): void {
    $filter = DimensionFilter::fromArray([
        'min_width' => 'not-a-number',
    ]);

    expect($filter->minWidth)
        ->toBeNull()
        ->and($filter->maxWidth)
        ->toBeNull()
        ->and($filter->minHeight)
        ->toBeNull()
        ->and($filter->maxHeight)
        ->toBeNull()
        ->and($filter->minRatio)
        ->toBeNull()
        ->and($filter->maxRatio)
        ->toBeNull();
});

test('isEmpty is true only when every bound is null', function (): void {
    expect(new DimensionFilter()->isEmpty())
        ->toBeTrue();
    expect(new DimensionFilter(minWidth: 100)->isEmpty())
        ->toBeFalse();
    expect(new DimensionFilter(maxRatio: 2.5)->isEmpty())
        ->toBeFalse();
});
