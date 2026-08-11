<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Permission\SqlCondition;

test('isEmpty is true only for an empty sql string', function (): void {
    expect((new SqlCondition(''))->isEmpty())
        ->toBeTrue()
        ->and((new SqlCondition('1 = 1'))->isEmpty())
        ->toBeFalse()
        ->and((new SqlCondition('x IN (:x)', [
            'x' => [1],
        ], [
            'x' => ArrayParameterType::INTEGER,
        ]))->isEmpty())->toBeFalse();
});

test('combine with zero non-empty conditions returns an empty condition', function (): void {
    $combined = SqlCondition::combine('AND', new SqlCondition(''), new SqlCondition(''));

    expect($combined->isEmpty())
        ->toBeTrue()
        ->and($combined->parameters)
        ->toBe([])
        ->and($combined->types)
        ->toBe([]);
});

test('combine with exactly one non-empty condition returns it unchanged, not re-wrapped', function (): void {
    $only = new SqlCondition('(x IN (:x))', [
        'x' => [1, 2],
    ], [
        'x' => ArrayParameterType::INTEGER,
    ]);

    $combined = SqlCondition::combine('AND', new SqlCondition(''), $only);

    expect($combined)
        ->toBe($only);
});

test('combine glues several non-empty conditions and merges their parameters/types', function (): void {
    $a = new SqlCondition('(x IN (:x))', [
        'x' => [1, 2],
    ], [
        'x' => ArrayParameterType::INTEGER,
    ]);
    $b = new SqlCondition('(y IN (:y))', [
        'y' => [3],
    ], [
        'y' => ArrayParameterType::INTEGER,
    ]);

    $combined = SqlCondition::combine('AND', $a, $b);

    expect($combined->sql)
        ->toBe('(x IN (:x)) AND (y IN (:y))')
        ->and($combined->parameters)
        ->toBe([
            'x' => [1, 2],
            'y' => [3],
        ])
        ->and($combined->types)
        ->toBe([
            'x' => ArrayParameterType::INTEGER,
            'y' => ArrayParameterType::INTEGER,
        ]);
});

test('combine drops empty fragments instead of gluing them in', function (): void {
    $a = new SqlCondition('(x IN (:x))', [
        'x' => [1],
    ], [
        'x' => ArrayParameterType::INTEGER,
    ]);

    $combined = SqlCondition::combine('AND', new SqlCondition(''), $a, new SqlCondition(''));

    expect($combined->sql)
        ->toBe('(x IN (:x))');
});

test('combine respects a different glue', function (): void {
    $a = new SqlCondition('a = 1');
    $b = new SqlCondition('b = 2');

    expect(SqlCondition::combine('OR', $a, $b)->sql)->toBe('a = 1 OR b = 2');
});
