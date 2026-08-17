<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Calendar\CalendarQueryScope;
use Piwigo\Calendar\CalendarRepository;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\SqlCondition;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * Piwigo\Calendar\CalendarRepository -- has its own dedicated
 * tests/Integration/CalendarRepositoryTest.php, covering only
 * findImageIds() (its only method taking a caller-composed $orderBySql
 * that can still miss the DQL path, per the class's own docblock);
 * this ports that same spec down to the Unit suite
 * via the real-DB-no-HTTP ImageRepositoryTest.php pattern. The other 6
 * methods are all real DQL against the same fixture, exercised
 * transitively via tests/Integration/CalendarMonthlyTest.php and
 * CalendarWeeklyTest.php (B5's own "Calendar subsystem" bucket, not
 * yet ported to Unit).
 *
 * Same fixture shape as SectionRepositoryTest/SearchRepositoryTest:
 * images 1,2,3 in category 1, images 4,5 in category 2; every image's
 * date_available is the uniform 2026-08-01 00:00:00 (matching
 * PIWIGO_TEST_NOW).
 *
 * 10 confirmed-equivalent mutations, not individually tested: the DQL
 * path's own `is_numeric($id) ? (int) $id : 0`-shaped cast/filter after
 * `getSingleColumnResult()` on a VO-typed field (lines 106-108) and the
 * raw-DBAL path's identical shape after `fetchFirstColumn()` (line
 * 128) are both unreachable in practice on this driver -- confirmed
 * live (a `var_dump()` of each call's own raw return value shows real
 * PHP ints already, not the VO instance or a numeric string one might
 * expect); `str_ireplace('RAND()', SqlDialect::randomFunction() .
 * '()', ...)` (line 119) is a same-value no-op under this environment's
 * real driver -- `SqlDialect::randomFunction()` returns `'RAND'` for
 * every non-pgsql driver, so replacing `'RAND()'` with `'RAND()'`
 * changes nothing observable; the merged `[...$fromWhere->types,
 * ...$dateWhere->types]` array (line 125) is also unreachable --
 * confirmed live (sed-mutate-and-rerun: replacing it with a bare `[]`
 * still passes this file's own bound-parameter test), DBAL's own type
 * inference binds a native int/numeric string correctly on this driver
 * with or without an explicit hint, same root cause as this project's
 * other already-documented `ParameterType::INTEGER`-hint findings.
 */
function calendarTestRepo(): CalendarRepository
{
    return new CalendarRepository(EntityManagerFactory::build(DbConnection::build()));
}

test('findImageIds() returns matching ids in order', function (): void {
    $ids = calendarTestRepo()
        ->findImageIds(
            SqlCondition::fromRawSql(' FROM images WHERE id IN (3, 1, 2)'),
            SqlCondition::fromRawSql(''),
            'ORDER BY id ASC'
        );

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findImageIds() returns empty for no match', function (): void {
    $ids = calendarTestRepo()
        ->findImageIds(
            SqlCondition::fromRawSql(' FROM images WHERE id = 999999'),
            SqlCondition::fromRawSql(''),
            ''
        );

    expect($ids)
        ->toBe([]);
});

test('findImageIds() orders by a column outside the select list', function (): void {
    // Matches the real production shape ($conf['order_by']'s default is
    // 'ORDER BY date_available DESC, file ASC, id ASC') -- proves the
    // query is valid under ONLY_FULL_GROUP_BY (GROUP BY id, not SELECT
    // DISTINCT id, which has no functional-dependency exception for
    // ORDER BY columns not in the SELECT list).
    $ids = calendarTestRepo()
        ->findImageIds(
            SqlCondition::fromRawSql(' FROM images WHERE id IN (1, 2, 3)'),
            SqlCondition::fromRawSql(''),
            'ORDER BY date_available DESC, file ASC, id ASC'
        );

    sort($ids);
    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findImageIds() deduplicates rows from a join', function (): void {
    // Real production inner_sql always INNER JOINs image_category (one
    // row per category an image belongs to) -- GROUP BY id must still
    // collapse that back down to one id per image.
    $ids = calendarTestRepo()
        ->findImageIds(
            SqlCondition::fromRawSql(' FROM images INNER JOIN image_category ON id = image_id WHERE category_id IN (1, 2)'),
            SqlCondition::fromRawSql(''),
            'ORDER BY id ASC'
        );

    expect($ids)
        ->toBe([1, 2, 3, 4, 5]);
});

test('findImageIds() collapses a real multi-category duplicate down to one row', function (): void {
    // The sibling test above never actually exercises GROUP BY's own
    // deduplication (no fixture image belongs to more than one
    // category) -- a fixed 'GROUP BY id ' text removed from the query
    // entirely would still silently pass it. Image 1 temporarily
    // getting a second link into category 2 makes the join emit 2 raw
    // rows for it, so a missing GROUP BY is directly observable as a
    // repeated id.
    $conn = DbConnection::build();
    $conn->executeStatement('INSERT INTO image_category (image_id, category_id) VALUES (1, 2)');

    try {
        $ids = calendarTestRepo()
            ->findImageIds(
                SqlCondition::fromRawSql(' FROM images INNER JOIN image_category ON id = image_id WHERE category_id IN (1, 2)'),
                SqlCondition::fromRawSql(''),
                'ORDER BY id ASC'
            );

        expect($ids)
            ->toBe([1, 2, 3, 4, 5]);
    } finally {
        $conn->executeStatement('DELETE FROM image_category WHERE image_id = 1 AND category_id = 2');
    }
});

test('findImageIds() honours a real DESC order, not just the GROUP BY key\'s own implicit scan order', function (): void {
    // The "returns matching ids in order" test above uses ORDER BY id
    // ASC, which happens to coincide with GROUP BY id's own natural
    // ascending scan order on this driver even if the ORDER BY clause
    // were dropped entirely -- DESC does not coincide, so this is what
    // actually proves $orderBySql reaches the query rather than being
    // silently dropped.
    $ids = calendarTestRepo()
        ->findImageIds(
            SqlCondition::fromRawSql(' FROM images WHERE id IN (1, 2, 3)'),
            SqlCondition::fromRawSql(''),
            'ORDER BY id DESC'
        );

    expect($ids)
        ->toBe([3, 2, 1]);
});

test('findImageIds() binds real parameters and types from both $fromWhere and $dateWhere on the raw-DBAL path', function (): void {
    // Both fragments carry a genuinely bound placeholder (not just
    // literal SQL text like the sibling tests above) -- proves neither
    // side's own ->parameters/->types is dropped when the two are
    // merged into one executeQuery() call.
    $ids = calendarTestRepo()
        ->findImageIds(
            SqlCondition::fromRawSql(' FROM images WHERE id = :onlyId', [
                'onlyId' => 2,
            ], [
                'onlyId' => ParameterType::INTEGER,
            ]),
            SqlCondition::fromRawSql('AND (rating_score = :rating)', [
                'rating' => '3.00',
            ], [
                'rating' => ParameterType::STRING,
            ]),
            'ORDER BY id ASC'
        );

    expect($ids)
        ->toBe([2]);
});

test('findImageIds() applies the date_where continuation', function (): void {
    // getDateWhere() (despite its name) returns a WHERE-clause
    // *continuation* fragment ("AND (...)"), which must land right
    // after $fromWhereSql's own WHERE and before GROUP BY.
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE images SET date_available = '2026-08-02 00:00:00' WHERE id = 3");

    try {
        $ids = calendarTestRepo()
            ->findImageIds(
                SqlCondition::fromRawSql(' FROM images WHERE id IN (1, 2, 3)'),
                SqlCondition::fromRawSql("AND (date_available = '2026-08-01 00:00:00')"),
                'ORDER BY id ASC'
            );

        expect($ids)
            ->toBe([1, 2]);
    } finally {
        $conn->executeStatement("UPDATE images SET date_available = '2026-08-01 00:00:00' WHERE id = 3");
    }
});

test('findImageIds() runs DQL, applies a real $dqlDateWhere filter, and never falls through to the raw-DBAL args', function (): void {
    // $fromWhere/$dateWhere below deliberately describe a BROADER match
    // (all 5 images, no date filter) than $dqlScope/$dqlDateWhere's own
    // narrower one (ids 1-3, excluding id 3) -- if the DQL branch's own
    // early return were skipped (falling through into the raw-DBAL
    // path below), or if $dqlDateWhere's own applyCondition() call were
    // dropped, the wider/wrong raw-path result would leak through
    // instead of the correct DQL-filtered one.
    $ids = calendarTestRepo()
        ->findImageIds(
            SqlCondition::fromRawSql(' FROM images WHERE id IN (1, 2, 3, 4, 5)'),
            SqlCondition::fromRawSql(''),
            'ORDER BY id DESC',
            new CalendarQueryScope(
                '',
                false,
                SqlCondition::fromRawSql(''),
                SqlCondition::fromRawSql('i.id IN (:ids)', [
                    'ids' => [1, 2, 3],
                ], [
                    'ids' => ArrayParameterType::INTEGER,
                ])
            ),
            SqlCondition::fromRawSql('i.id != :excluded', [
                'excluded' => 3,
            ], [
                'excluded' => ParameterType::INTEGER,
            ])
        );

    expect($ids)
        ->toBe([2, 1]);
});

test('findImageIds() falls back to raw DBAL when $dqlScope is given but $dqlDateWhere is not', function (): void {
    // $dqlScope's own narrower filter (ids 1-3) must be entirely
    // ignored here -- with $dqlDateWhere null, the method must never
    // attempt DQL at all, only the raw-DBAL args below (all 5 images).
    $ids = calendarTestRepo()
        ->findImageIds(
            SqlCondition::fromRawSql(' FROM images WHERE id IN (1, 2, 3, 4, 5)'),
            SqlCondition::fromRawSql(''),
            'ORDER BY id ASC',
            new CalendarQueryScope(
                '',
                false,
                SqlCondition::fromRawSql(''),
                SqlCondition::fromRawSql('i.id IN (:ids)', [
                    'ids' => [1, 2, 3],
                ], [
                    'ids' => ArrayParameterType::INTEGER,
                ])
            ),
            null
        );

    expect($ids)
        ->toBe([1, 2, 3, 4, 5]);
});

test('findImageIds() falls back to raw DBAL when $dqlDateWhere is given but $dqlScope is not', function (): void {
    $ids = calendarTestRepo()
        ->findImageIds(
            SqlCondition::fromRawSql(' FROM images WHERE id IN (1, 2, 3, 4, 5)'),
            SqlCondition::fromRawSql(''),
            'ORDER BY id ASC',
            null,
            SqlCondition::fromRawSql('')
        );

    expect($ids)
        ->toBe([1, 2, 3, 4, 5]);
});

test('findImageIds() DQL path only joins image_category when the scope asks for it', function (): void {
    // A throwaway image with NO image_category row at all: an INNER
    // JOIN image_category (joinImageCategory: true) drops it from the
    // result, proving the join really is conditional on the scope's own
    // flag, not always-on or always-off.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction: `images`
    // carries a FULLTEXT index (images_ft_name_comment/images_ft_author),
    // and InnoDB's FULLTEXT auxiliary-index maintenance on INSERT/DELETE
    // can deadlock against another --parallel worker's own concurrent
    // DML on the same table when held open for a whole test's duration
    // -- same mechanism, same fix, as TagServiceTest.php's 'getTagIds()
    // creates a new tag for a plain name when allowed' (reproduced live
    // there: DeadlockException).
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $conn->executeStatement("INSERT INTO images (file, path) VALUES ('p17-unit-test-calendar-orphan.jpg', 'p17-unit-test-calendar-orphan.jpg')");
    $orphanId = (int) $conn->lastInsertId();

    try {
        $ids = calendarTestRepo()
            ->findImageIds(
                SqlCondition::fromRawSql(''),
                SqlCondition::fromRawSql(''),
                'ORDER BY id ASC',
                new CalendarQueryScope(
                    '',
                    true,
                    SqlCondition::fromRawSql(''),
                    SqlCondition::fromRawSql('i.id IN (:ids)', [
                        'ids' => [1, $orphanId],
                    ], [
                        'ids' => ArrayParameterType::INTEGER,
                    ])
                ),
                SqlCondition::fromRawSql('')
            );

        expect($ids)
            ->toBe([1]);
    } finally {
        $conn->executeStatement('DELETE FROM images WHERE id = ?', [$orphanId]);
    }
});

test('findImageIds() falls back to raw DBAL when a DQL scope is given but order by does not parse', function (): void {
    // `comment` is a real images column but not one of the bounded
    // $sort_fields tokens, so PhotoSortField::resolveDqlOrderBy() returns
    // null and this must still return the right members via the raw-DBAL
    // fallback, not throw, even though a $dqlScope is given.
    $ids = calendarTestRepo()
        ->findImageIds(
            SqlCondition::fromRawSql(' FROM images WHERE id IN (1, 2, 3)'),
            SqlCondition::fromRawSql(''),
            'ORDER BY comment ASC',
            new CalendarQueryScope(
                '',
                false,
                SqlCondition::fromRawSql(''),
                SqlCondition::fromRawSql('i.id IN (:ids)', [
                    'ids' => [1, 2, 3],
                ], [
                    'ids' => ArrayParameterType::INTEGER,
                ])
            ),
            SqlCondition::fromRawSql('')
        );
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findImageIds() runs a random order as DQL when a scope is given', function (): void {
    // `RAND()` parses into PhotoSortField::Random, which now resolves to the
    // registered RandFunction custom DQL function instead of returning null
    // -- so this takes the DQL path. The order is random, so only membership
    // can be asserted.
    $ids = calendarTestRepo()
        ->findImageIds(
            SqlCondition::fromRawSql(' FROM images WHERE id IN (1, 2, 3)'),
            SqlCondition::fromRawSql(''),
            'ORDER BY RAND()',
            new CalendarQueryScope(
                '',
                false,
                SqlCondition::fromRawSql(''),
                SqlCondition::fromRawSql('i.id IN (:ids)', [
                    'ids' => [1, 2, 3],
                ], [
                    'ids' => ArrayParameterType::INTEGER,
                ])
            ),
            SqlCondition::fromRawSql('')
        );
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});
