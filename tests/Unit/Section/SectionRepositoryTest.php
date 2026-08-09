<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Permission\SqlCondition;
use Piwigo\Section\SectionRepository;

/**
 * Piwigo\Section\SectionRepository -- has its own dedicated
 * tests/Integration/SectionRepositoryTest.php; this ports its 8 tests
 * down to the Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php
 * pattern, plus a test of its own for findSectionImageIds() (the
 * raw-SQL, queryColumn()-backed path), which had no dedicated test in
 * either suite.
 *
 * Same fixture shape as that Integration test / SearchRepositoryTest:
 * images 1-5 (image_category assigns 1,2,3 to category 1 and 4,5 to
 * category 2, category 2's own uppercats is '1,2'); images 1-4 have
 * rating_score 4.50/3.00/5.00/2.00, image 5 has NULL; every image's hit
 * starts at 0.
 */
function sectionTestRepo(): SectionRepository
{
    return new SectionRepository(EntityManagerFactory::build(DbConnection::build()));
}

test('escapeToken() escapes a value without surrounding quotes', function (): void {
    $escaped = sectionTestRepo()->escapeToken("o'brien");

    // escapeToken() routes through Connection::quote() -- the real
    // per-driver quoting mechanism. Postgres doubles an embedded quote
    // (SQL-standard escaping); mysqli backslash-escapes it instead.
    $expected = getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? "o''brien" : "o\\'brien";
    expect($escaped)->toBe($expected)
        ->and($escaped)->not->toStartWith("'")
        ->and($escaped)->not->toEndWith("'");
});

test('escapeToken() leaves a plain value unchanged', function (): void {
    expect(sectionTestRepo()->escapeToken('plain-value'))->toBe('plain-value');
});

test('findVisibleSubcategoryIds() returns direct subcategories', function (): void {
    expect(sectionTestRepo()->findVisibleSubcategoryIds('1', new SqlCondition('')))->toBe(['2']);
});

test('findVisibleSubcategoryIds() returns empty for a leaf category', function (): void {
    expect(sectionTestRepo()->findVisibleSubcategoryIds('1,2', new SqlCondition('')))->toBe([]);
});

test('findTopRatedImageIds() returns ids ordered by rating desc', function (): void {
    expect(sectionTestRepo()->findTopRatedImageIds(new SqlCondition(''), 3))->toBe(['3', '1', '2']);
});

test('findTopRatedImageIds() respects the limit', function (): void {
    expect(sectionTestRepo()->findTopRatedImageIds(new SqlCondition(''), 1))->toBe(['3']);
});

test('findTopByHitsImageIds() returns ids ordered by hit desc', function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement('UPDATE ' . Tables::images() . ' SET hit = 5 WHERE id = 2');
    $conn->executeStatement('UPDATE ' . Tables::images() . ' SET hit = 10 WHERE id = 4');

    try {
        expect(sectionTestRepo()->findTopByHitsImageIds(new SqlCondition(''), 5))->toBe(['4', '2']);
    } finally {
        $conn->executeStatement('UPDATE ' . Tables::images() . ' SET hit = 0 WHERE id IN (2, 4)');
    }
});

test('findTopByHitsImageIds() returns empty when no image has a hit', function (): void {
    expect(sectionTestRepo()->findTopByHitsImageIds(new SqlCondition(''), 5))->toBe([]);
});

test('findSectionImageIds() returns image ids for a category, as real numeric strings via the raw-SQL/queryColumn() path', function (): void {
    $ids = sectionTestRepo()->findSectionImageIds(
        whereSql: 'category_id = :catId',
        forbiddenSql: '',
        orderBySql: 'ORDER BY id ASC',
        params: ['catId' => 1],
    );

    expect($ids)->toBe(['1', '2', '3']);
});

test('findSectionImageIds() returns empty for a category with no images', function (): void {
    $ids = sectionTestRepo()->findSectionImageIds(
        whereSql: 'category_id = :catId',
        forbiddenSql: '',
        orderBySql: 'ORDER BY id ASC',
        params: ['catId' => 999999],
    );

    expect($ids)->toBe([]);
});
