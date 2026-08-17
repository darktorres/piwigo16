<?php

declare(strict_types=1);

use Piwigo\Config\CacheSizesSnapshot;

/**
 * Piwigo\Config\CacheSizesSnapshot -- pure decode logic, no DB/Kernel
 * dependency.
 */
test('fromArray() reads all 4 real fields by name, ignoring unknown rows', function (): void {
    $snapshot = CacheSizesSnapshot::fromArray([
        [
            'name' => 'unknown_future_field',
            'value' => 'irrelevant',
        ],
        [
            'name' => 'cache_size',
            'value' => 12345,
        ],
        [
            'name' => 'msizes',
            'value' => [
                'square' => 100,
                'thumb' => 50,
                'all' => 150,
            ],
        ],
        [
            'name' => 'tsizes',
            'value' => 6789,
        ],
        [
            'name' => 'last_date_calc',
            'value' => '2026-08-01 00:00:00',
        ],
    ]);

    expect($snapshot->cacheSize)
        ->toBe(12345)
        ->and($snapshot->msizes)
        ->toBe([
            'square' => 100,
            'thumb' => 50,
            'all' => 150,
        ])
        ->and($snapshot->tsizes)
        ->toBe(6789)
        ->and($snapshot->lastDateCalc)
        ->toBe('2026-08-01 00:00:00');
});

test('fromArray() defaults every field to its own empty/null value when all are missing', function (): void {
    $snapshot = CacheSizesSnapshot::fromArray([]);

    expect($snapshot->cacheSize)
        ->toBeNull()
        ->and($snapshot->msizes)
        ->toBe([])
        ->and($snapshot->tsizes)
        ->toBeNull()
        ->and($snapshot->lastDateCalc)
        ->toBe('');
});

test('fromArray() ignores a non-int cache_size value and a non-string last_date_calc value', function (): void {
    $snapshot = CacheSizesSnapshot::fromArray([
        [
            'name' => 'cache_size',
            'value' => 'not-an-int',
        ],
        [
            'name' => 'last_date_calc',
            'value' => 12345,
        ],
    ]);

    expect($snapshot->cacheSize)
        ->toBeNull()
        ->and($snapshot->lastDateCalc)
        ->toBe('');
});

test('fromArray() defaults msizes to an empty array and tsizes to null when their values are the wrong type', function (): void {
    $snapshot = CacheSizesSnapshot::fromArray([
        [
            'name' => 'msizes',
            'value' => 'not-an-array',
        ],
        [
            'name' => 'tsizes',
            'value' => 'not-an-int',
        ],
    ]);

    expect($snapshot->msizes)
        ->toBe([])
        ->and($snapshot->tsizes)
        ->toBeNull();
});

test('fromArray() drops any non-int entry from msizes, keeping the rest', function (): void {
    $snapshot = CacheSizesSnapshot::fromArray([
        [
            'name' => 'msizes',
            'value' => [
                'square' => 100,
                'custom' => 'not-an-int',
                'all' => 100,
            ],
        ],
    ]);

    expect($snapshot->msizes)
        ->toBe([
            'square' => 100,
            'all' => 100,
        ]);
});

test('fromArray() skips a row whose own name is missing or non-scalar', function (): void {
    $snapshot = CacheSizesSnapshot::fromArray([
        [
            'value' => 12345,
        ],
        'not-an-array-row',
        [
            'name' => 'cache_size',
            'value' => 999,
        ],
    ]);

    expect($snapshot->cacheSize)
        ->toBe(999);
});
