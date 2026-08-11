<?php

declare(strict_types=1);

use Piwigo\Admin\Request\RatingUserFilterRequest;

test('fromArray defaults minRates to 2 and consensusTopNumber to the given default when absent', function (): void {
    $request = RatingUserFilterRequest::fromArray([], 5);

    expect($request->minRates)
        ->toBe(2)
        ->and($request->consensusTopNumber)
        ->toBe(5)
        ->and($request->orderBy)
        ->toBeNull();
});

test('fromArray parses numeric values when present', function (): void {
    $request = RatingUserFilterRequest::fromArray([
        'f_min_rates' => '10',
        'consensus_top_number' => '20',
        'order_by' => '3',
    ], 5);

    expect($request->minRates)
        ->toBe(10)
        ->and($request->consensusTopNumber)
        ->toBe(20)
        ->and($request->orderBy)
        ->toBe(3);
});

test('fromArray ignores non-numeric order_by', function (): void {
    $request = RatingUserFilterRequest::fromArray([
        'order_by' => 'abc',
    ], 5);

    expect($request->orderBy)
        ->toBeNull();
});

test('fromArray returns an out-of-range orderBy verbatim, leaving bounds-checking to the caller', function (): void {
    $request = RatingUserFilterRequest::fromArray([
        'order_by' => '99',
    ], 5);

    expect($request->orderBy)
        ->toBe(99);
});
