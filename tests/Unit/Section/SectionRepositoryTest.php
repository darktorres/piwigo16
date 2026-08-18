<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\SqlCondition;
use Piwigo\Section\SectionRepository;

/**
 * Piwigo\Section\SectionRepository -- has its own dedicated
 * tests/Integration/SectionRepositoryTest.php; this ports its 6 tests
 * down to the Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php
 * pattern, plus tests of its own for findSectionImageIds()/
 * findRecentImageIds()/findImageIdsAmongList()'s DQL-first path (with a
 * raw-DBAL-fallback counterpart for each), which had no dedicated test in
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

test('findVisibleSubcategoryIds() returns direct subcategories', function (): void {
    expect(sectionTestRepo()->findVisibleSubcategoryIds('1', SqlCondition::fromRawSql('')))
        ->toBe(['2']);
});

test('findVisibleSubcategoryIds() returns empty for a leaf category', function (): void {
    expect(sectionTestRepo()->findVisibleSubcategoryIds('1,2', SqlCondition::fromRawSql('')))
        ->toBe([]);
});

test('findTopRatedImageIds() returns ids ordered by rating desc', function (): void {
    expect(sectionTestRepo()->findTopRatedImageIds(SqlCondition::fromRawSql(''), 3))
        ->toBe(['3', '1', '2']);
});

test('findTopRatedImageIds() respects the limit', function (): void {
    expect(sectionTestRepo()->findTopRatedImageIds(SqlCondition::fromRawSql(''), 1))
        ->toBe(['3']);
});

test('findTopByHitsImageIds() returns ids ordered by hit desc', function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement('UPDATE images SET hit = 5 WHERE id = 2');
    $conn->executeStatement('UPDATE images SET hit = 10 WHERE id = 4');

    try {
        expect(sectionTestRepo()->findTopByHitsImageIds(SqlCondition::fromRawSql(''), 5))
            ->toBe(['4', '2']);
    } finally {
        $conn->executeStatement('UPDATE images SET hit = 0 WHERE id IN (2, 4)');
    }
});

test('findTopByHitsImageIds() returns empty when no image has a hit', function (): void {
    expect(sectionTestRepo()->findTopByHitsImageIds(SqlCondition::fromRawSql(''), 5))
        ->toBe([]);
});

test('findSectionImageIds() runs the DQL path for a resolvable order and a single category (rank-eligible)', function (): void {
    $ids = sectionTestRepo()
        ->findSectionImageIds(
            scope: SqlCondition::fromRawSql('category_id = :catId', [
                'catId' => 1,
            ]),
            forbidden: SqlCondition::fromRawSql(''),
            orderBySql: 'ORDER BY id ASC',
            dqlScope: SqlCondition::fromRawSql('ic.category = :catId', [
                'catId' => 1,
            ]),
            dqlForbidden: SqlCondition::fromRawSql(''),
            dqlImageCategoryAlias: 'ic',
        );

    expect($ids)
        ->toBe(['1', '2', '3']);
});

test('findSectionImageIds() falls back to the raw-SQL/queryColumn() path for an unparseable order fragment', function (): void {
    // `comment` is a real images column but not one of the bounded
    // PhotoSortField tokens, so resolveDqlOrderBy() returns null -- same
    // trick already established for UserRepositoryTest's own
    // findVisibleFavoriteImageIds() sibling test.
    $ids = sectionTestRepo()
        ->findSectionImageIds(
            scope: SqlCondition::fromRawSql('category_id = :catId', [
                'catId' => 1,
            ]),
            forbidden: SqlCondition::fromRawSql(''),
            orderBySql: 'ORDER BY comment ASC',
            dqlScope: SqlCondition::fromRawSql('ic.category = :catId', [
                'catId' => 1,
            ]),
            dqlForbidden: SqlCondition::fromRawSql(''),
            dqlImageCategoryAlias: 'ic',
        );
    sort($ids);

    expect($ids)
        ->toBe(['1', '2', '3']);
});

test('findSectionImageIds() returns empty for a category with no images', function (): void {
    $ids = sectionTestRepo()
        ->findSectionImageIds(
            scope: SqlCondition::fromRawSql('category_id = :catId', [
                'catId' => 999999,
            ]),
            forbidden: SqlCondition::fromRawSql(''),
            orderBySql: 'ORDER BY id ASC',
            dqlScope: SqlCondition::fromRawSql('ic.category = :catId', [
                'catId' => 999999,
            ]),
            dqlForbidden: SqlCondition::fromRawSql(''),
            dqlImageCategoryAlias: 'ic',
        );

    expect($ids)
        ->toBe([]);
});

test('findRecentImageIds() runs the DQL path and matches on a caller-composed condition', function (): void {
    // No image-category alias -- recent_pics always spans every category,
    // same reasoning as CalendarRepository::findImageIds()'s own
    // permanent null alias.
    $noRestriction = SqlCondition::fromRawSql('');
    $ids = sectionTestRepo()
        ->findRecentImageIds($noRestriction, $noRestriction, 'ORDER BY id ASC', $noRestriction, $noRestriction);

    expect($ids)
        ->toBe(['1', '2', '3', '4', '5']);
});

test('findRecentImageIds() falls back to the raw-SQL path for an unparseable order fragment', function (): void {
    $noRestriction = SqlCondition::fromRawSql('');
    $ids = sectionTestRepo()
        ->findRecentImageIds($noRestriction, $noRestriction, 'ORDER BY comment ASC', $noRestriction, $noRestriction);
    sort($ids);

    expect($ids)
        ->toBe(['1', '2', '3', '4', '5']);
});

test('findImageIdsAmongList() runs the DQL path, restricted to the given id list', function (): void {
    $noRestriction = SqlCondition::fromRawSql('');
    $ids = sectionTestRepo()
        ->findImageIdsAmongList(['1', '3'], $noRestriction, 'ORDER BY id ASC', $noRestriction);

    expect($ids)
        ->toBe(['1', '3']);
});

test('findImageIdsAmongList() falls back to the raw-SQL path for an unparseable order fragment', function (): void {
    $noRestriction = SqlCondition::fromRawSql('');
    $ids = sectionTestRepo()
        ->findImageIdsAmongList(['1', '3'], $noRestriction, 'ORDER BY comment ASC', $noRestriction);
    sort($ids);

    expect($ids)
        ->toBe(['1', '3']);
});

test('findImageIdsAmongList() returns empty for an empty id list', function (): void {
    $noRestriction = SqlCondition::fromRawSql('');
    $ids = sectionTestRepo()
        ->findImageIdsAmongList([], $noRestriction, 'ORDER BY id ASC', $noRestriction);

    expect($ids)
        ->toBe([]);
});
