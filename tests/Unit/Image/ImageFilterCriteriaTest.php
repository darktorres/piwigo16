<?php

declare(strict_types=1);

use Doctrine\DBAL\ParameterType;
use Piwigo\Image\ImageFilterCriteria;

/**
 * Piwigo\Image\ImageFilterCriteria -- typed replacement for
 * Ws\ImageFilterCriteriaBuilder::stdImageSqlFilterCriteria()'s own former
 * raw SqlCondition return. Pure logic, no I/O -- no dedicated
 * Integration/Browser spec of its own.
 */
test('isEmpty is true when every field is null', function (): void {
    $criteria = new ImageFilterCriteria();

    expect($criteria->isEmpty())
        ->toBeTrue();
});

test('isEmpty is false when any single field is set', function (): void {
    expect(new ImageFilterCriteria(minRate: 1.0)->isEmpty())
        ->toBeFalse()
        ->and(new ImageFilterCriteria(maxRate: 5.0)->isEmpty())
        ->toBeFalse()
        ->and(new ImageFilterCriteria(minHit: 0)->isEmpty())
        ->toBeFalse()
        ->and(new ImageFilterCriteria(maxHit: 100)->isEmpty())
        ->toBeFalse()
        ->and(new ImageFilterCriteria(minDateAvailable: '2026-01-01')->isEmpty())
        ->toBeFalse()
        ->and(new ImageFilterCriteria(maxDateAvailable: '2026-01-01')->isEmpty())
        ->toBeFalse()
        ->and(new ImageFilterCriteria(minDateCreated: '2026-01-01')->isEmpty())
        ->toBeFalse()
        ->and(new ImageFilterCriteria(maxDateCreated: '2026-01-01')->isEmpty())
        ->toBeFalse()
        ->and(new ImageFilterCriteria(minRatio: 1.0)->isEmpty())
        ->toBeFalse()
        ->and(new ImageFilterCriteria(maxRatio: 2.0)->isEmpty())
        ->toBeFalse()
        ->and(new ImageFilterCriteria(maxLevel: 4)->isEmpty())
        ->toBeFalse();
});

test('toSqlCondition returns an empty fragment when every field is null', function (): void {
    $condition = new ImageFilterCriteria()
        ->toSqlCondition();

    expect($condition->isEmpty())
        ->toBeTrue()
        ->and($condition->sql)
        ->toBe('')
        ->and($condition->parameters)
        ->toBe([])
        ->and($condition->types)
        ->toBe([]);
});

test('toSqlCondition builds the min/max rate clauses with float parameters', function (): void {
    $condition = new ImageFilterCriteria(minRate: 1.5, maxRate: 4.5)
        ->toSqlCondition();

    expect($condition->sql)
        ->toBe('(rating_score >= :imgFilterMinRate AND rating_score <= :imgFilterMaxRate)')
        ->and($condition->parameters)
        ->toBe([
            'imgFilterMinRate' => 1.5,
            'imgFilterMaxRate' => 4.5,
        ])
        ->and($condition->types)
        ->toBe([]);
});

test('toSqlCondition builds the min/max hit clauses with explicit INTEGER types', function (): void {
    $condition = new ImageFilterCriteria(minHit: 5, maxHit: 500)
        ->toSqlCondition();

    expect($condition->sql)
        ->toBe('(hit >= :imgFilterMinHit AND hit <= :imgFilterMaxHit)')
        ->and($condition->parameters)
        ->toBe([
            'imgFilterMinHit' => 5,
            'imgFilterMaxHit' => 500,
        ])
        ->and($condition->types)
        ->toBe([
            'imgFilterMinHit' => ParameterType::INTEGER,
            'imgFilterMaxHit' => ParameterType::INTEGER,
        ]);
});

test('toSqlCondition builds the date_available clauses with a strict upper bound', function (): void {
    $condition = new ImageFilterCriteria(minDateAvailable: '2026-01-01', maxDateAvailable: '2026-12-31')
        ->toSqlCondition();

    expect($condition->sql)
        ->toBe('(date_available >= :imgFilterMinDateAvailable AND date_available < :imgFilterMaxDateAvailable)');
});

test('toSqlCondition builds the date_creation clauses with a strict upper bound', function (): void {
    $condition = new ImageFilterCriteria(minDateCreated: '2026-01-01', maxDateCreated: '2026-12-31')
        ->toSqlCondition();

    expect($condition->sql)
        ->toBe('(date_creation >= :imgFilterMinDateCreated AND date_creation < :imgFilterMaxDateCreated)');
});

test('toSqlCondition builds the ratio clauses with the NULLIF-guarded decimal-context division', function (): void {
    $condition = new ImageFilterCriteria(minRatio: 1.0, maxRatio: 2.0)
        ->toSqlCondition();

    expect($condition->sql)
        ->toBe('(width*1.0/NULLIF(height, 0) >= :imgFilterMinRatio AND width*1.0/NULLIF(height, 0) <= :imgFilterMaxRatio)')
        ->and($condition->parameters)
        ->toBe([
            'imgFilterMinRatio' => 1.0,
            'imgFilterMaxRatio' => 2.0,
        ]);
});

test('toSqlCondition builds the maxLevel clause with an explicit INTEGER type', function (): void {
    $condition = new ImageFilterCriteria(maxLevel: 4)
        ->toSqlCondition();

    expect($condition->sql)
        ->toBe('(level <= :imgFilterMaxLevel)')
        ->and($condition->parameters)
        ->toBe([
            'imgFilterMaxLevel' => 4,
        ])
        ->and($condition->types)
        ->toBe([
            'imgFilterMaxLevel' => ParameterType::INTEGER,
        ]);
});

test('toSqlCondition prefixes every column reference with the given table prefix', function (): void {
    $condition = new ImageFilterCriteria(minRate: 1.0, minRatio: 1.0)
        ->toSqlCondition('i.');

    expect($condition->sql)
        ->toBe('(i.rating_score >= :imgFilterMinRate AND i.width*1.0/NULLIF(i.height, 0) >= :imgFilterMinRatio)');
});

test('toSqlCondition joins every set clause with AND, in field declaration order', function (): void {
    $condition = new ImageFilterCriteria(
        minRate: 1.0,
        maxHit: 100,
        maxLevel: 4,
    )->toSqlCondition();

    expect($condition->sql)
        ->toBe('(rating_score >= :imgFilterMinRate AND hit <= :imgFilterMaxHit AND level <= :imgFilterMaxLevel)');
});
