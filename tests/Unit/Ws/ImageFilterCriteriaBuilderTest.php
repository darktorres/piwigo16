<?php

declare(strict_types=1);

use Piwigo\Image\ImageFilterCriteria;
use Piwigo\Ws\ImageFilterCriteriaBuilder;

/**
 * Piwigo\Ws\ImageFilterCriteriaBuilder -- split out of the former
 * WsHelper god-class (P25 Stage 1 step 6). No dedicated Integration/
 * Browser spec of its own --
 * `Piwigo\Tests\Contract\ImageFilterCriteriaBuilderTest.php` covers
 * stdImageSqlFilterCriteria()'s "invalid date field" branch (a
 * `WsErrorResponse` return, not an `exit()` -- see this class's own
 * docblock) through a real WS round-trip, which this file deliberately
 * does not attempt.
 */
test('stdImageSqlFilterCriteria builds the criteria from valid params, with no date fields set', function (): void {
    $builder = new ImageFilterCriteriaBuilder();
    $params = [
        'f_min_rate' => 1.5,
        'f_max_rate' => 5.0,
        'f_min_hit' => 0,
        'f_max_hit' => 100,
        'f_min_ratio' => null,
        'f_max_ratio' => null,
        'f_max_level' => null,
        'f_min_date_available' => null,
        'f_max_date_available' => null,
        'f_min_date_created' => null,
        'f_max_date_created' => null,
    ];

    $criteria = $builder->stdImageSqlFilterCriteria($params);

    expect($criteria)
        ->toBeInstanceOf(ImageFilterCriteria::class);
    if (! $criteria instanceof ImageFilterCriteria) {
        return;
    }

    expect($criteria->minRate)
        ->toBe(1.5)
        ->and($criteria->maxRate)
        ->toBe(5.0)
        ->and($criteria->minHit)
        ->toBe(0)
        ->and($criteria->maxHit)
        ->toBe(100)
        ->and($criteria->minDateAvailable)
        ->toBeNull();
});

test('stdImageSqlFilterCriteria accepts a valid MySQL datetime for a date field', function (): void {
    $builder = new ImageFilterCriteriaBuilder();
    $params = [
        'f_min_rate' => null,
        'f_max_rate' => null,
        'f_min_hit' => null,
        'f_max_hit' => null,
        'f_min_ratio' => null,
        'f_max_ratio' => null,
        'f_max_level' => null,
        'f_min_date_available' => '2026-01-01 00:00:00',
        'f_max_date_available' => null,
        'f_min_date_created' => null,
        'f_max_date_created' => null,
    ];

    $criteria = $builder->stdImageSqlFilterCriteria($params);

    expect($criteria)
        ->toBeInstanceOf(ImageFilterCriteria::class);
    if (! $criteria instanceof ImageFilterCriteria) {
        return;
    }

    expect($criteria->minDateAvailable)
        ->toBe('2026-01-01 00:00:00');
});
