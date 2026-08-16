<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Piwigo\Common\ValueObject\PhotoSortField;
use Piwigo\Common\ValueObject\PhotoSortOrder;
use Piwigo\Db\DbConnection;
use Piwigo\Db\OrderByClause;
use Piwigo\Db\SortRenderer;
use Piwigo\Db\SqlDialect;

/**
 * Piwigo\Db\SortRenderer -- the half of the former Piwigo\Sort namespace
 * that needs a database.
 *
 * The platform-dependent spellings (RAND() vs RANDOM(), backticked vs
 * double-quoted `rank`) are asserted against the driver this suite actually
 * runs on rather than hardcoded, so the file is meaningful under either --
 * and, unlike the fused enum it replaces, they come from the real
 * connection's platform rather than from an env read.
 */
function sortRendererTestRenderer(): SortRenderer
{
    return new SortRenderer(DbConnection::build());
}

function sortRendererTestIsPostgres(): bool
{
    return DbConnection::build()->getDatabasePlatform() instanceof PostgreSQLPlatform;
}

test('toSql() renders a complete clause and toSqlBody() the same without the keyword', function (): void {
    // `hit` is NOT NULL, so it gets no discriminant; `name` is nullable, so
    // it does -- see the dedicated NULL-ordering tests below for why.
    $order = PhotoSortOrder::fromConfigFragment('ORDER BY name ASC, hit DESC');
    $expectedBody = 'CASE WHEN name IS NULL THEN 0 ELSE 1 END ASC, name ASC, hit DESC';

    expect(sortRendererTestRenderer()->toSql($order))
        ->toBe('ORDER BY ' . $expectedBody)
        ->and(sortRendererTestRenderer()->toSqlBody($order))
        ->toBe($expectedBody);
});

test('toSql() is empty for an order with no entries', function (): void {
    expect(sortRendererTestRenderer()->toSql(PhotoSortOrder::none()))->toBe('');
});

test('toSql() prefixes real columns with a table alias when one is given', function (): void {
    // `id` is NOT NULL, so it gets no discriminant; `name` is nullable, so
    // its discriminant is prefixed too.
    expect(sortRendererTestRenderer()->toSql(PhotoSortOrder::fromConfigFragment('ORDER BY name ASC, id DESC'), 'i'))
        ->toBe('ORDER BY CASE WHEN i.name IS NULL THEN 0 ELSE 1 END ASC, i.name ASC, i.id DESC');
});

test('the random expression takes no table prefix and no direction', function (): void {
    // A function call, not a column -- the same carve-out stdImageSqlOrder()
    // always had.
    $order = PhotoSortOrder::fromConfigFragment('ORDER BY RAND()');
    $expected = 'ORDER BY ' . SqlDialect::randomFunction();

    expect(sortRendererTestRenderer()->toSql($order))
        ->toBe($expected)
        ->and(sortRendererTestRenderer()->toSql($order, 'i'))
        ->toBe($expected);
});

test('randomExpression() agrees with SqlDialect but derives the platform from the connection', function (): void {
    // Same seeding policy, different platform source: SqlDialect reads
    // DbCredentials::fromEnv(), this reads the real connection.
    expect(sortRendererTestRenderer()->randomExpression())
        ->toBe(SqlDialect::randomFunction());
});

test('rank is quoted by the connected platform', function (): void {
    // `rank` is nullable (image_category.rank), so its own discriminant is
    // quoted the same way as the real column.
    $quoted = sortRendererTestIsPostgres() ? '"rank"' : '`rank`';

    expect(sortRendererTestRenderer()->rankColumn())
        ->toBe($quoted)
        ->and(sortRendererTestRenderer()->toSql(PhotoSortOrder::fromConfigFragment('ORDER BY `rank` ASC')))
        ->toBe('ORDER BY CASE WHEN ' . $quoted . ' IS NULL THEN 0 ELSE 1 END ASC, ' . $quoted . ' ASC');
});

test('column() returns the real column or expression for every field', function (): void {
    $renderer = sortRendererTestRenderer();
    $quoted = sortRendererTestIsPostgres() ? '"rank"' : '`rank`';

    expect($renderer->column(PhotoSortField::Id))->toBe('id')
        ->and($renderer->column(PhotoSortField::File))->toBe('file')
        ->and($renderer->column(PhotoSortField::Name))->toBe('name')
        ->and($renderer->column(PhotoSortField::Hit))->toBe('hit')
        ->and($renderer->column(PhotoSortField::RatingScore))->toBe('rating_score')
        ->and($renderer->column(PhotoSortField::DateCreation))->toBe('date_creation')
        ->and($renderer->column(PhotoSortField::DateAvailable))->toBe('date_available')
        ->and($renderer->column(PhotoSortField::Random))->toBe(SqlDialect::randomFunction())
        ->and($renderer->column(PhotoSortField::Rank))->toBe($quoted);
});

test('toDql() maps every entry to a DQL expression', function (): void {
    // `date_available` is nullable and gets a leading discriminant clause;
    // `file`/`id` are NOT NULL and get none.
    expect(sortRendererTestRenderer()->toDql(PhotoSortOrder::fromConfigFragment('ORDER BY date_available DESC, file ASC, id ASC'), 'i'))
        ->toEqual([
            new OrderByClause('CASE WHEN i.dateAvailable IS NULL THEN 0 ELSE 1 END', 'DESC'),
            new OrderByClause('i.dateAvailable', 'DESC'),
            new OrderByClause('i.file', 'ASC'),
            new OrderByClause('i.id', 'ASC'),
        ]);
});

test('toDql() maps a random order to the registered custom DQL function', function (): void {
    // Doctrine accepts a FunctionDeclaration as an ORDER BY item, and RAND
    // is registered in EntityManagerFactory -- so a random order resolves
    // rather than pushing its caller onto raw DBAL. Unprefixed and
    // platform-free: RandFunction dispatches at SQL-walk time.
    expect(sortRendererTestRenderer()->toDql(PhotoSortOrder::fromConfigFragment('ORDER BY RAND()'), 'i'))
        ->toEqual([new OrderByClause('RAND()', 'ASC')]);
});

test('toDql() returns null only for rank with no image_category alias', function (): void {
    // null means "fall back to raw SQL", never "no order". This is the one
    // remaining way to get there. `rank` is nullable, so the resolved case
    // carries its own leading discriminant clause too.
    $renderer = sortRendererTestRenderer();

    expect($renderer->toDql(PhotoSortOrder::fromConfigFragment('ORDER BY `rank` ASC'), 'i'))
        ->toBeNull()
        ->and($renderer->toDql(PhotoSortOrder::fromConfigFragment('ORDER BY `rank` ASC'), 'i', 'ic'))
        ->toEqual([
            new OrderByClause('CASE WHEN ic.rank IS NULL THEN 0 ELSE 1 END', 'ASC'),
            new OrderByClause('ic.rank', 'ASC'),
        ]);
});

test('toDql() returns null for an empty order rather than an empty clause list', function (): void {
    expect(sortRendererTestRenderer()->toDql(PhotoSortOrder::none(), 'i'))->toBeNull();
});

test('resolveDqlOrderBy() parses and maps a composed fragment in one step', function (): void {
    expect(sortRendererTestRenderer()->resolveDqlOrderBy('ORDER BY date_available DESC, file ASC, id ASC', 'i'))
        ->toEqual([
            new OrderByClause('CASE WHEN i.dateAvailable IS NULL THEN 0 ELSE 1 END', 'DESC'),
            new OrderByClause('i.dateAvailable', 'DESC'),
            new OrderByClause('i.file', 'ASC'),
            new OrderByClause('i.id', 'ASC'),
        ]);
});

test('resolveDqlOrderBy() returns null for text outside the vocabulary rather than defaulting', function (): void {
    // The distinction from PhotoSortOrder::fromConfigFragment(): a composed
    // fragment must not silently become the default order. `comment` is a
    // real images column but not a $sort_fields token.
    expect(sortRendererTestRenderer()->resolveDqlOrderBy('ORDER BY comment ASC', 'i'))
        ->toBeNull();
});

test('resolveDqlOrderBy() honours the same rank rule as toDql()', function (): void {
    $renderer = sortRendererTestRenderer();

    expect($renderer->resolveDqlOrderBy('ORDER BY `rank` ASC', 'i'))
        ->toBeNull()
        ->and($renderer->resolveDqlOrderBy('ORDER BY `rank` ASC', 'i', 'ic'))
        ->toEqual([
            new OrderByClause('CASE WHEN ic.rank IS NULL THEN 0 ELSE 1 END', 'ASC'),
            new OrderByClause('ic.rank', 'ASC'),
        ]);
});
