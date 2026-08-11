<?php

declare(strict_types=1);

use Piwigo\Admin\BatchManager\Projection\FilesizeFilter;

test('fromArray narrows both bounds to float', function (): void {
    // Both bounds passed as numeric strings -- proves the (float) cast
    // itself does the narrowing, not just that the value round-trips
    // (a value that's already a float literal would pass this
    // assertion even if the cast were silently removed).
    $filter = FilesizeFilter::fromArray([
        'min' => '10',
        'max' => '500.5',
    ]);

    expect($filter->min)
        ->toBe(10.0)
        ->and($filter->max)
        ->toBe(500.5);
});

test('fromArray defaults both bounds to null when absent or non-numeric', function (): void {
    $filter = FilesizeFilter::fromArray([
        'min' => 'not-a-number',
    ]);

    expect($filter->min)
        ->toBeNull()
        ->and($filter->max)
        ->toBeNull();
});

test('isEmpty is true only when both bounds are null', function (): void {
    expect(new FilesizeFilter()->isEmpty())
        ->toBeTrue();
    expect(new FilesizeFilter(min: 10.0)->isEmpty())
        ->toBeFalse();
    expect(new FilesizeFilter(max: 500.0)->isEmpty())
        ->toBeFalse();
});
