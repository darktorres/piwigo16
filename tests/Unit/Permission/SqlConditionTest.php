<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Query\Expr\Andx;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\ImageEntity;
use Piwigo\Permission\SqlCondition;

test('isEmpty is true only for an empty sql string', function (): void {
    expect(SqlCondition::fromRawSql('')->isEmpty())
        ->toBeTrue()
        ->and(SqlCondition::fromRawSql('1 = 1')->isEmpty())
        ->toBeFalse()
        ->and(SqlCondition::fromRawSql('x IN (:x)', [
            'x' => [1],
        ], [
            'x' => ArrayParameterType::INTEGER,
        ])->isEmpty())->toBeFalse();
});

test('combine with zero non-empty conditions returns an empty condition', function (): void {
    $combined = SqlCondition::combine('AND', SqlCondition::fromRawSql(''), SqlCondition::fromRawSql(''));

    expect($combined->isEmpty())
        ->toBeTrue()
        ->and($combined->parameters)
        ->toBe([])
        ->and($combined->types)
        ->toBe([]);
});

test('combine with exactly one non-empty condition returns it unchanged, not re-wrapped', function (): void {
    $only = SqlCondition::fromRawSql('(x IN (:x))', [
        'x' => [1, 2],
    ], [
        'x' => ArrayParameterType::INTEGER,
    ]);

    $combined = SqlCondition::combine('AND', SqlCondition::fromRawSql(''), $only);

    expect($combined)
        ->toBe($only);
});

test('combine glues several non-empty conditions and merges their parameters/types', function (): void {
    $a = SqlCondition::fromRawSql('(x IN (:x))', [
        'x' => [1, 2],
    ], [
        'x' => ArrayParameterType::INTEGER,
    ]);
    $b = SqlCondition::fromRawSql('(y IN (:y))', [
        'y' => [3],
    ], [
        'y' => ArrayParameterType::INTEGER,
    ]);

    $combined = SqlCondition::combine('AND', $a, $b);

    expect((string) $combined->expr)
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
    $a = SqlCondition::fromRawSql('(x IN (:x))', [
        'x' => [1],
    ], [
        'x' => ArrayParameterType::INTEGER,
    ]);

    $combined = SqlCondition::combine('AND', SqlCondition::fromRawSql(''), $a, SqlCondition::fromRawSql(''));

    expect((string) $combined->expr)
        ->toBe('(x IN (:x))');
});

test('combine respects a different glue', function (): void {
    $a = SqlCondition::fromRawSql('a = 1');
    $b = SqlCondition::fromRawSql('b = 2');

    expect((string) SqlCondition::combine('OR', $a, $b)->expr)->toBe('a = 1 OR b = 2');
});

test('combine parenthesizes a compound fragment nested into another combine(), construction-enforced', function (): void {
    // The real value this class's Expr\Base conversion adds over naive
    // string concatenation: a fragment that already carries its own
    // top-level AND/OR gets wrapped automatically when glued into a
    // wider condition, instead of relying on a call site's own
    // "already parenthesized, don't wrap again" comment convention
    // (see e.g. the former TagRepository:311).
    $compound = SqlCondition::fromRawSql('a = 1 AND b = 2');
    $single = SqlCondition::fromRawSql('c = 3');

    expect((string) SqlCondition::combine('AND', $compound, $single)->expr)
        ->toBe('(a = 1 AND b = 2) AND c = 3');
});

test('fromRawSql wraps sql in a single-part Expr\Andx that stringifies back to the bare text', function (): void {
    $condition = SqlCondition::fromRawSql('a = 1');

    expect($condition->expr)
        ->toBeInstanceOf(Andx::class)
        ->and($condition->expr->count())
        ->toBe(1)
        ->and((string) $condition->expr)
        ->toBe('a = 1');
});

test('toWhereClause renders a complete clause, or nothing when empty', function (): void {
    expect(SqlCondition::fromRawSql('')->toWhereClause())
        ->toBe('')
        ->and(SqlCondition::fromRawSql('a = 1')->toWhereClause())
        ->toBe('WHERE a = 1');
});

test('applyTo binds sql, parameters and types onto a DBAL query builder', function (): void {
    $qb = DbConnection::build()
        ->createQueryBuilder()
        ->select('id')
        ->from('images');

    SqlCondition::fromRawSql('id IN (:ids) AND file = :file', [
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

    SqlCondition::fromRawSql('i.id = :onlyId', [
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

    SqlCondition::fromRawSql('')
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
