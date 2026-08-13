<?php

declare(strict_types=1);

use Piwigo\Config\CacheSizesSnapshot;

/**
 * Piwigo\Config\CacheSizesSnapshot -- no dedicated test file at any
 * layer before this one; only mentioned in a comment inside
 * IntroSubControllerTest.php. Pure decode logic, no DB/Kernel
 * dependency.
 */
test('fromArray() reads cache_size and last_date_calc by name, ignoring other rows', function (): void {
    $snapshot = CacheSizesSnapshot::fromArray([
        [
            'name' => 'msizes',
            'value' => 'irrelevant',
        ],
        [
            'name' => 'cache_size',
            'value' => 12345,
        ],
        [
            'name' => 'tsizes',
            'value' => 'irrelevant',
        ],
        [
            'name' => 'last_date_calc',
            'value' => '2026-08-01 00:00:00',
        ],
    ]);

    expect($snapshot->cacheSize)
        ->toBe(12345)
        ->and($snapshot->lastDateCalc)
        ->toBe('2026-08-01 00:00:00');
});

test('fromArray() defaults cacheSize to null and lastDateCalc to an empty string when both are missing', function (): void {
    $snapshot = CacheSizesSnapshot::fromArray([]);

    expect($snapshot->cacheSize)
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
