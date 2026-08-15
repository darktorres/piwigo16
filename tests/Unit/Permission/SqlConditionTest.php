<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\ImageEntity;
use Piwigo\Permission\SqlCondition;

test('isEmpty is true only for an empty sql string', function (): void {
    expect(new SqlCondition('')->isEmpty())
        ->toBeTrue()
        ->and(new SqlCondition('1 = 1')->isEmpty())
        ->toBeFalse()
        ->and(new SqlCondition('x IN (:x)', [
            'x' => [1],
        ], [
            'x' => ArrayParameterType::INTEGER,
        ])->isEmpty())->toBeFalse();
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

test('toWhereClause renders a complete clause, or nothing when empty', function (): void {
    expect(new SqlCondition('')->toWhereClause())
        ->toBe('')
        ->and(new SqlCondition('a = 1')->toWhereClause())
        ->toBe('WHERE a = 1');
});

test('applyTo binds sql, parameters and types onto a DBAL query builder', function (): void {
    $qb = DbConnection::build()
        ->createQueryBuilder()
        ->select('id')
        ->from('images');

    new SqlCondition('id IN (:ids) AND file = :file', [
        'ids' => [1, 2],
        'file' => 'fixture-photo-1.jpg',
    ], [
        'ids' => ArrayParameterType::INTEGER,
    ])->applyTo($qb);

    expect($qb->getSQL())
        ->toContain('WHERE id IN (:ids) AND file = :file');
    // The type map only covers 'ids'; 'file' falls back to STRING rather
    // than binding untyped.
    expect($qb->getParameter('ids'))
        ->toBe([1, 2])
        ->and($qb->getParameter('file'))
        ->toBe('fixture-photo-1.jpg')
        ->and($qb->getParameterType('ids'))
        ->toBe(ArrayParameterType::INTEGER)
        ->and($qb->getParameterType('file'))
        ->toBe(ParameterType::STRING);
});

test('applyTo binds onto an ORM query builder too', function (): void {
    $qb = EntityManagerFactory::build(DbConnection::build())
        ->createQueryBuilder()
        ->select('i.id')
        ->from(ImageEntity::class, 'i');

    new SqlCondition('i.id = :onlyId', [
        'onlyId' => 3,
    ])->applyTo($qb);

    expect($qb->getDQL())
        ->toContain('WHERE i.id = :onlyId');
    expect($qb->getParameter('onlyId')?->getValue())
        ->toBe(3);
});

test('applyTo is a no-op for an empty condition', function (): void {
    $qb = DbConnection::build()
        ->createQueryBuilder()
        ->select('id')
        ->from('images');
    $before = $qb->getSQL();

    new SqlCondition('')
        ->applyTo($qb);

    // No stray WHERE, and nothing bound -- this is what removes the need
    // for callers to substitute a `1=1` tautology.
    expect($qb->getSQL())
        ->toBe($before)
        ->and($qb->getSQL())
        ->not->toContain('WHERE')
        ->and($qb->getParameters())
        ->toBe([]);
});
