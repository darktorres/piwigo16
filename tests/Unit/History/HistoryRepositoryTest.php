<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryEntity;
use Piwigo\History\HistoryRepository;
use Piwigo\History\Projection\HistorySummaryCount;
use Piwigo\Users\UserInfoEntity;

/**
 * Piwigo\History\HistoryRepository -- has its own dedicated
 * tests/Integration/HistoryRepositoryTest.php; this ports its 22 tests
 * down to the Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php
 * pattern. `history`/`history_summary` are both empty in the fixture, so
 * every test inserts its own rows and cleans them up via try/finally,
 * matching the Integration original's own isolation discipline.
 *
 * findLastVisit() (LastVisitLookupInterface's own implementation) and
 * getSectionEnumOptions()/alterSectionEnum()/insert() aren't covered by
 * this repository's own Integration spec -- findLastVisit() is exercised
 * via AuthRepository's own caller test, getSectionEnumOptions()/
 * alterSectionEnum() via HistoryServiceTest's own
 * test_log_visit_widens_the_section_enum_for_a_brand_new_section() (see
 * that Integration test's own docblock), same as the original.
 *
 * Confirmed-equivalent mutations, not individually tested (all verified
 * live via sed-mutate-and-rerun, not just pattern-matched): every
 * `is_numeric(...) ? (int) ... : default`/`(...) instanceof Vo ? ->value
 * : default` cast across this file's own methods (findMinHistoryId(),
 * countAll(), sumPageViews(), findLatestHistoryId()/findOldestHistoryId(),
 * findImageIdsByFilename(), findGroupedCountsSince()'s date/time
 * unwrap, search()'s own HistorySearchRow field-by-field mapping) is
 * unreachable on this driver -- getSingleScalarResult()/
 * getSingleColumnResult() on a plain column already return a real
 * native PHP int, and getArrayResult() on a VO-typed column already
 * returns the real VO instance (confirmed live via var_dump()), same
 * root cause documented throughout this project's other Unit-suite
 * files; findLastByType()/findMonthlyRows()/findDailyRowsForMonths()'s
 * own `array_filter($row, is_string(...), ARRAY_FILTER_USE_KEY)` before
 * `HistorySummaryRow::fromRow()` is dead code -- every key in a plain
 * DQL-aliased array-hydrated row is already a string, never an int;
 * findLastSummaryWithHistoryIdTo()'s own `setMaxResults(1)` is
 * unobservable at any other value -- only `$rows[0]` is ever read
 * regardless of how many rows come back; search()'s own
 * `if ($imageIdsFromFilename === [])` guard (the "matched no image"
 * `1 = 2` shortcut) is unobservable if skipped -- confirmed live
 * (sed-mutate-and-rerun: disabling it falls through to `h.imageId IN
 * (:imageIds)` with a genuinely empty array, which DBAL's own
 * `ArrayParameterType` expansion already turns into a clause matching
 * nothing on this driver, same root cause as
 * PermalinkRepositoryTest.php's own findPermalinkMatches() finding);
 * findGroupedCountsSince()'s own `substr($time, 0, 3)` variant (widening
 * the hour-extraction length by one) is confirmed live to produce the
 * identical result as the correct `substr($time, 0, 2)` for every real
 * "HH:MM:SS" value -- `(int)` casting the 3-character "HH:" result stops
 * at the non-numeric `:` regardless, same 2-digit hour either way (only
 * the start-index variant, `substr($time, 1, 2)`, is a real, killable
 * mutation, and is covered by this file's own dedicated hour-23 test);
 * every remaining `instanceof`/`is_numeric()` fallback branch inside
 * search()'s own HistorySearchRow mapping (the *inner* ternary of each
 * `(...) instanceof Vo ? ->value : (is_numeric(...) ? (int) ... :
 * default)` chain) is confirmed live to be unreachable -- the *outer*
 * instanceof/is_string() condition is what this file's own per-column
 * assertions (date/time/userId/ip/section/categoryId/searchId/tagIds/
 * imageId/imageType, spread across "filters by user id", "filters by an
 * IP LIKE pattern", and "maps every optional column") actually verify,
 * and confirmed via sed-mutate-and-rerun that negating the *outer*
 * condition alone (not the inner fallback) is what those assertions
 * catch; findSummaryRowsForHierarchy()'s own `if ($hour !== null)` guard
 * (line 269) is unobservable at $hour = null specifically -- confirmed
 * live: forcing that branch to wrongly fire anyway binds a NULL
 * `:hour` parameter into `(hs.hour IS NULL OR hs.hour = :hour)`, and SQL's
 * own 3-valued NULL logic makes `x = NULL` always NULL (never TRUE), so
 * the OR degrades back to exactly `hs.hour IS NULL` either way -- the
 * `$day`-level sibling guard (line 267) is a real, killable branch (see
 * this file's own 2 dedicated tests), only the deepest `$hour` guard
 * happens to be semantically absorbed by its own OR clause.
 */
function historyTestRepo(): HistoryRepository
{
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(HistoryEntity::class);
    expect($repo)->toBeInstanceOf(HistoryRepository::class);

    return $repo;
}

/**
 * getEntityManager() is protected on Doctrine's own EntityRepository base
 * class -- the em->clear() staleness tests below need direct
 * EntityManager access (for find()) alongside the repo, same
 * CaddieRepositoryTest.php precedent.
 *
 * @return array{0: HistoryRepository, 1: Doctrine\ORM\EntityManagerInterface}
 */
function historyTestRepoWithEm(): array
{
    $em = EntityManagerFactory::build(DbConnection::build());
    $repo = $em->getRepository(HistoryEntity::class);
    expect($repo)->toBeInstanceOf(HistoryRepository::class);

    return [$repo, $em];
}

function historyTestInsertLine(int $userId, string $date, string $time): int
{
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->insert(Tables::history())
        ->values(['date' => ':date', 'time' => ':time', 'user_id' => ':userId', 'IP' => "'127.0.0.1'"])
        ->setParameter('date', $date)
        ->setParameter('time', $time)
        ->setParameter('userId', $userId)
        ->executeStatement();

    return (int) $conn->lastInsertId();
}

function historyTestMinId(Connection $conn): int
{
    $value = $conn->createQueryBuilder()->select('MIN(id)')->from(Tables::history())->executeQuery()->fetchOne();

    return is_numeric($value) ? (int) $value : 0;
}

function historyTestInsertSummary(int $year, ?int $month, ?int $day, ?int $hour, int $nbPages, int $idFrom, int $idTo): void
{
    DbConnection::build()->createQueryBuilder()
        ->insert(Tables::historySummary())
        ->values([
            'year' => ':year', 'month' => ':month', 'day' => ':day', 'hour' => ':hour',
            'nb_pages' => ':nbPages', 'history_id_from' => ':idFrom', 'history_id_to' => ':idTo',
        ])
        ->setParameter('year', $year)
        ->setParameter('month', $month)
        ->setParameter('day', $day)
        ->setParameter('hour', $hour)
        ->setParameter('nbPages', $nbPages)
        ->setParameter('idFrom', $idFrom)
        ->setParameter('idTo', $idTo)
        ->executeStatement();
}

/**
 * @return array<string, mixed>
 */
function historyTestFetchSummary(Connection $conn, int $year, ?int $month, ?int $day, ?int $hour): array
{
    $qb = $conn->createQueryBuilder()
        ->select('*')
        ->from(Tables::historySummary())
        ->where('year = :year')
        ->setParameter('year', $year);
    $qb->andWhere($month === null ? 'month IS NULL' : 'month = ' . $month);
    $qb->andWhere($day === null ? 'day IS NULL' : 'day = ' . $day);
    $qb->andWhere($hour === null ? 'hour IS NULL' : 'hour = ' . $hour);

    $row = $qb->executeQuery()->fetchAssociative();
    if (! is_array($row)) {
        throw new RuntimeException('expected a real summary row');
    }

    return $row;
}

function historyTestClearHistory(): void
{
    DbConnection::build()->executeStatement('DELETE FROM ' . Tables::history());
}

function historyTestClearSummary(): void
{
    DbConnection::build()->executeStatement('DELETE FROM ' . Tables::historySummary());
}

test('findLastSummaryWithHistoryIdTo() returns null when empty', function (): void {
    expect(historyTestRepo()->findLastSummaryWithHistoryIdTo())->toBeNull();
});

test('findLastSummaryWithHistoryIdTo() returns the highest', function (): void {
    historyTestInsertSummary(2026, 7, 12, 3, 10, 1, 100);
    historyTestInsertSummary(2026, 7, 12, 4, 5, 101, 200);

    try {
        $found = historyTestRepo()->findLastSummaryWithHistoryIdTo();

        expect($found)->not->toBeNull();
        if ($found === null) {
            throw new RuntimeException('unreachable');
        }
        expect($found->historyIdTo)->toBe(200)
            ->and($found->year)->toBe(2026)
            ->and($found->month)->toBe(7)
            ->and($found->day)->toBe(12)
            ->and($found->hour)->toBe(4);
    } finally {
        historyTestClearSummary();
    }
});

test('findMinHistoryId() returns null when empty', function (): void {
    expect(historyTestRepo()->findMinHistoryId())->toBeNull();
});

test('findMinHistoryId() returns the lowest', function (): void {
    historyTestInsertLine(1, '2026-07-12', '03:00:00');
    historyTestInsertLine(1, '2026-07-12', '04:00:00');
    $conn = DbConnection::build();

    try {
        expect(historyTestRepo()->findMinHistoryId())->toBe(historyTestMinId($conn));
    } finally {
        historyTestClearHistory();
    }
});

test('findGroupedCountsSince() buckets by date and hour, tracking the real min/max id per bucket', function (): void {
    $first = historyTestInsertLine(1, '2026-07-12', '03:10:00');
    $second = historyTestInsertLine(1, '2026-07-12', '03:50:00');
    historyTestInsertLine(1, '2026-07-12', '04:00:00');

    try {
        $groups = historyTestRepo()->findGroupedCountsSince(0, null);

        expect($groups)->toHaveCount(2)
            ->and($groups[0]->date)->toBe('2026-07-12')
            ->and($groups[0]->hour)->toBe(3)
            ->and($groups[0]->nbPages)->toBe(2)
            // min()/max() swapped would still pass a single-row bucket
            // (the sibling 04:00 one) -- only this 2-row bucket, where
            // insertion order (auto-increment id) puts the smaller id
            // FIRST, actually distinguishes them.
            ->and($groups[0]->minId)->toBe($first)
            ->and($groups[0]->maxId)->toBe($second)
            ->and($groups[1]->hour)->toBe(4)
            ->and($groups[1]->nbPages)->toBe(1);
    } finally {
        historyTestClearHistory();
    }
});

test('findGroupedCountsSince() sorts buckets by date then hour, not just row/insertion order', function (): void {
    // Inserted in the OPPOSITE order of the expected result -- both a
    // dropped usort() call and a usort() comparator missing its own
    // "date" or "hour" half would leave this in insertion order instead.
    historyTestInsertLine(1, '2026-07-13', '01:00:00');
    historyTestInsertLine(1, '2026-07-12', '05:00:00');
    historyTestInsertLine(1, '2026-07-12', '02:00:00');

    try {
        $groups = historyTestRepo()->findGroupedCountsSince(0, null);

        $keys = array_map(static fn ($g) => $g->date . ' ' . $g->hour, $groups);
        expect($keys)->toBe(['2026-07-12 2', '2026-07-12 5', '2026-07-13 1']);
    } finally {
        historyTestClearHistory();
    }
});

test('findGroupedCountsSince() extracts a real 2-digit hour, not just a single leading digit', function (): void {
    // Every other test in this file uses an hour < 10 -- (int) substr($time, 0, 2)
    // and a naive (int) cast of the whole "HH:MM:SS" string agree by
    // coincidence there (both read the same single leading digit). Hour
    // 23 is what actually forces the real 2-character substr(): a
    // shifted start index (substr($time, 1, 2) => "3:") or a shifted
    // length (substr($time, 0, 3) => "23:") would both produce a
    // different, wrong bucket.
    historyTestInsertLine(1, '2026-07-12', '23:10:00');

    try {
        $groups = historyTestRepo()->findGroupedCountsSince(0, null);

        expect($groups)->toHaveCount(1)
            ->and($groups[0]->hour)->toBe(23);
    } finally {
        historyTestClearHistory();
    }
});

test('findGroupedCountsSince() respects the max id', function (): void {
    $id1 = historyTestInsertLine(1, '2026-07-12', '03:00:00');
    historyTestInsertLine(1, '2026-07-12', '04:00:00');

    try {
        $groups = historyTestRepo()->findGroupedCountsSince(0, $id1);

        expect($groups)->toHaveCount(1)
            ->and($groups[0]->hour)->toBe(3);
    } finally {
        historyTestClearHistory();
    }
});

test('findSummaryRowsForHierarchy() returns every existing level', function (): void {
    historyTestInsertSummary(2026, null, null, null, 100, 1, 50); // year-only
    historyTestInsertSummary(2026, 7, null, null, 40, 1, 30); // year+month
    historyTestInsertSummary(2026, 8, null, null, 10, 40, 50); // different month -- should not match

    try {
        $rows = historyTestRepo()->findSummaryRowsForHierarchy(2026, 7, 12, 3);

        $keys = array_map(
            static fn (HistorySummaryCount $r): string => $r->year . '-' . ($r->month ?? 'x') . '-' . ($r->day ?? 'x') . '-' . ($r->hour ?? 'x'),
            $rows
        );
        sort($keys);

        expect($keys)->toBe(['2026-7-x-x', '2026-x-x-x']);
    } finally {
        historyTestClearSummary();
    }
});

test('findSummaryRowsForHierarchy() with a null day/hour only reaches the shallower levels', function (): void {
    // The sibling test above always passes non-null $day/$hour, so the
    // nested `if ($day !== null)`/`if ($hour !== null)` branches always
    // take their "given" side -- this exercises their own "null" side
    // instead, at the month level (day/hour both null).
    historyTestInsertSummary(2026, null, null, null, 100, 1, 50); // year-only
    historyTestInsertSummary(2026, 7, null, null, 40, 1, 30); // year+month
    historyTestInsertSummary(2026, 7, 12, null, 10, 31, 40); // year+month+day -- should not match (day given as null here)

    try {
        $rows = historyTestRepo()->findSummaryRowsForHierarchy(2026, 7, null, null);

        $keys = array_map(
            static fn (HistorySummaryCount $r): string => $r->year . '-' . ($r->month ?? 'x') . '-' . ($r->day ?? 'x') . '-' . ($r->hour ?? 'x'),
            $rows
        );
        sort($keys);

        expect($keys)->toBe(['2026-7-x-x', '2026-x-x-x']);
    } finally {
        historyTestClearSummary();
    }
});

test('findSummaryRowsForHierarchy() with a non-null day but a null hour reaches the day level, not the hour level', function (): void {
    // The 2 sibling tests above only ever exercise $day/$hour together
    // (both given, or both null) -- $hour's own nested `if ($hour !==
    // null)` guard only actually runs once $day has already taken its
    // "given" branch, so day=12/hour=null is the one combination that
    // tells the guard's 2 sides apart at the deepest level.
    historyTestInsertSummary(2026, null, null, null, 100, 1, 50); // year-only
    historyTestInsertSummary(2026, 7, null, null, 40, 1, 30); // year+month
    historyTestInsertSummary(2026, 7, 12, null, 20, 1, 20); // year+month+day -- should match
    historyTestInsertSummary(2026, 7, 12, 3, 10, 21, 30); // year+month+day+hour -- should not match (hour given as null here)

    try {
        $rows = historyTestRepo()->findSummaryRowsForHierarchy(2026, 7, 12, null);

        $keys = array_map(
            static fn (HistorySummaryCount $r): string => $r->year . '-' . ($r->month ?? 'x') . '-' . ($r->day ?? 'x') . '-' . ($r->hour ?? 'x'),
            $rows
        );
        sort($keys);

        expect($keys)->toBe(['2026-7-12-x', '2026-7-x-x', '2026-x-x-x']);
    } finally {
        historyTestClearSummary();
    }
});

test('updateSummaryRows() updates the matching null-inclusive key', function (): void {
    historyTestInsertSummary(2026, null, null, null, 100, 1, 50);
    $conn = DbConnection::build();

    try {
        historyTestRepo()->updateSummaryRows([
            ['year' => 2026, 'month' => null, 'day' => null, 'hour' => null, 'nbPages' => 150, 'historyIdTo' => 80],
        ]);

        $row = historyTestFetchSummary($conn, 2026, null, null, null);
        expect($row['nb_pages'])->toBe(150)
            ->and($row['history_id_to'])->toBe(80);
    } finally {
        historyTestClearSummary();
    }
});

test('updateSummaryRows() scopes the update to the exact month/day/hour key, sparing sibling rows', function (): void {
    // The sibling test above only ever has ONE row in the whole table,
    // so its own andWhere() calls on month/day/hour are unobservable --
    // if any of them were silently dropped, the single existing row
    // would still be the only possible match. 2 rows sharing the same
    // year, differing only by month, is what actually proves each
    // condition scopes the UPDATE instead of hitting every row for that
    // year.
    historyTestInsertSummary(2026, 7, null, null, 100, 1, 50);
    historyTestInsertSummary(2026, 8, null, null, 200, 1, 50);
    $conn = DbConnection::build();

    try {
        historyTestRepo()->updateSummaryRows([
            ['year' => 2026, 'month' => 7, 'day' => null, 'hour' => null, 'nbPages' => 999, 'historyIdTo' => 999],
        ]);

        expect(historyTestFetchSummary($conn, 2026, 7, null, null)['nb_pages'])->toBe(999)
            ->and(historyTestFetchSummary($conn, 2026, 8, null, null)['nb_pages'])->toBe(200);
    } finally {
        historyTestClearSummary();
    }
});

test('insertSummaryRows() inserts new rows', function (): void {
    $conn = DbConnection::build();

    try {
        historyTestRepo()->insertSummaryRows([
            ['year' => 2026, 'month' => 7, 'day' => 12, 'hour' => 3, 'nbPages' => 5, 'historyIdFrom' => 1, 'historyIdTo' => 5],
        ]);

        $row = historyTestFetchSummary($conn, 2026, 7, 12, 3);
        expect($row['nb_pages'])->toBe(5);
    } finally {
        historyTestClearSummary();
    }
});

test('sumPageViews() sums only year-only rows', function (): void {
    // month IS NULL is summarize()'s own "whole year" rollup row -- the
    // month-level row below must not also get counted, or the sum would
    // double.
    historyTestInsertSummary(2026, null, null, null, 100, 1, 50);
    historyTestInsertSummary(2027, null, null, null, 25, 51, 60);
    historyTestInsertSummary(2026, 7, null, null, 999, 1, 30);

    try {
        expect(historyTestRepo()->sumPageViews())->toBe(125);
    } finally {
        historyTestClearSummary();
    }
});

test('countAll()/findLatestHistoryId()/findOldestHistoryId()/deleteBefore() round-trip', function (): void {
    $id1 = historyTestInsertLine(1, '2026-07-10', '03:00:00');
    historyTestInsertLine(1, '2026-07-11', '03:00:00');
    $id3 = historyTestInsertLine(1, '2026-07-12', '03:00:00');

    try {
        $repo = historyTestRepo();
        expect($repo->countAll())->toBe(3)
            ->and($repo->findLatestHistoryId())->toBe($id3)
            ->and($repo->findOldestHistoryId())->toBe($id1);

        $repo->deleteBefore($id3);

        expect($repo->countAll())->toBe(1)
            ->and($repo->findOldestHistoryId())->toBe($id3);
    } finally {
        historyTestClearHistory();
    }
});

test('deleteBefore() clears the identity map, so a later find() sees the real deletion instead of a stale cached entity', function (): void {
    [$repo, $em] = historyTestRepoWithEm();
    $id = historyTestInsertLine(1, '2026-07-10', '03:00:00');
    $afterId = historyTestInsertLine(1, '2026-07-12', '03:00:00');

    try {
        $tracked = $em->find(HistoryEntity::class, $id);
        expect($tracked)->not->toBeNull();

        $repo->deleteBefore($afterId);

        expect($em->find(HistoryEntity::class, $id))->toBeNull();
    } finally {
        historyTestClearHistory();
    }
});

test('findImageIdsByFilename() matches the fixture image', function (): void {
    expect(historyTestRepo()->findImageIdsByFilename('fixture-photo-1%'))->toBe([1]);
});

test('search() filters by user id', function (): void {
    historyTestInsertLine(1, '2026-07-12', '03:00:00');
    historyTestInsertLine(3, '2026-07-12', '03:00:00');

    try {
        $rows = historyTestRepo()->search(null, null, null, [], 1, null, null, null);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->userId)->toBe(1);
    } finally {
        historyTestClearHistory();
    }
});

test('search() with no criteria returns everything', function (): void {
    historyTestInsertLine(1, '2026-07-12', '03:00:00');
    historyTestInsertLine(3, '2026-07-12', '03:00:00');

    try {
        expect(historyTestRepo()->search(null, null, null, [], null, null, null, null))->toHaveCount(2);
    } finally {
        historyTestClearHistory();
    }
});

test('search() with an unmatched filename returns nothing', function (): void {
    historyTestInsertLine(1, '2026-07-12', '03:00:00');

    try {
        expect(historyTestRepo()->search(null, null, null, [], null, null, [], null))->toBe([]);
    } finally {
        historyTestClearHistory();
    }
});

test('search() filters by an IP LIKE pattern', function (): void {
    // h.ip is ip_address_graceful-Typed -- a raw LIKE pattern (not a
    // full valid IpAddress) bound against it must bypass that Type's own
    // convertToDatabaseValue() (which only accepts null|IpAddress), or
    // this throws instead of matching.
    historyTestInsertLine(1, '2026-07-12', '03:00:00');

    try {
        $rows = historyTestRepo()->search(null, null, null, [], null, null, null, '127.%');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->ip)->toBe('127.0.0.1');
    } finally {
        historyTestClearHistory();
    }
});

test('search() filters by image type, against the full real $types vocabulary', function (): void {
    // Every sibling test above always passes null for $imageTypes,
    // never exercising this filter at all. $types is the caller's own
    // full known vocabulary (matching a real call site's shape); only
    // rows whose image_type is in the given $imageTypes subset (or has
    // no image_type at all, via the 'none' member) survive.
    $conn = DbConnection::build();
    $pictureId = historyTestInsertLine(1, '2026-07-12', '03:00:00');
    $conn->executeStatement('UPDATE ' . Tables::history() . " SET image_type = 'picture' WHERE id = ?", [$pictureId]);
    $highId = historyTestInsertLine(1, '2026-07-12', '03:00:01');
    $conn->executeStatement('UPDATE ' . Tables::history() . " SET image_type = 'high' WHERE id = ?", [$highId]);
    $noneId = historyTestInsertLine(1, '2026-07-12', '03:00:02');

    try {
        $rows = historyTestRepo()->search(null, null, ['picture', 'none'], ['picture', 'high', 'other', 'none'], null, null, null, null);

        $ids = array_map(static fn ($row) => $row->userId . '-' . ($row->imageType ?? 'x'), $rows);
        sort($ids);
        expect($rows)->toHaveCount(2)
            ->and($ids)->toBe(['1-picture', '1-x']);
    } finally {
        historyTestClearHistory();
    }
});

test('search() binds a distinct query parameter per non-none image type, not a shared/colliding one', function (): void {
    // The sibling test above only ever binds ONE non-'none' type param,
    // so a dropped `$i` suffix ('type' . $i => bare 'type') can't be
    // told apart from the correct 'type0' -- picture AND high together
    // need 2 distinct bound values at once to prove the suffix matters.
    $conn = DbConnection::build();
    $pictureId = historyTestInsertLine(1, '2026-07-12', '03:00:00');
    $conn->executeStatement('UPDATE ' . Tables::history() . " SET image_type = 'picture' WHERE id = ?", [$pictureId]);
    $highId = historyTestInsertLine(1, '2026-07-12', '03:00:01');
    $conn->executeStatement('UPDATE ' . Tables::history() . " SET image_type = 'high' WHERE id = ?", [$highId]);
    historyTestInsertLine(1, '2026-07-12', '03:00:02'); // no image_type -- excluded, 'none' isn't in $imageTypes here

    try {
        $rows = historyTestRepo()->search(null, null, ['picture', 'high'], ['picture', 'high', 'other', 'none'], null, null, null, null);

        $types = array_map(static fn ($row) => $row->imageType, $rows);
        sort($types);
        expect($types)->toBe(['high', 'picture']);
    } finally {
        historyTestClearHistory();
    }
});

test('search() maps every optional column when a row actually has them populated', function (): void {
    // The sibling tests above only ever insert bare date/time/user_id/IP
    // rows, so section/categoryId/searchId/tagIds/imageId/imageType are
    // always real database NULLs there -- this is what actually proves
    // each column's own VO-hydration branch (the "instanceof" true side)
    // maps a genuinely non-null value correctly, not just its null
    // fallback.
    $conn = DbConnection::build();
    $id = historyTestInsertLine(1, '2026-07-12', '03:00:00');
    $conn->executeStatement(
        'UPDATE ' . Tables::history() . " SET section = 'categories', category_id = 1, search_id = 5, tag_ids = '1,2,3', image_id = 1, image_type = 'picture' WHERE id = ?",
        [$id]
    );

    try {
        $rows = historyTestRepo()->search(null, null, null, [], null, null, null, null);

        expect($rows)->toHaveCount(1);
        $row = $rows[0];
        expect($row->date)->toBe('2026-07-12')
            ->and($row->time)->toBe('03:00:00')
            ->and($row->section)->toBe('categories')
            ->and($row->categoryId)->toBe(1)
            ->and($row->searchId)->toBe(5)
            ->and($row->tagIds)->toBe('1,2,3')
            ->and($row->imageId)->toBe(1)
            ->and($row->imageType)->toBe('picture');
    } finally {
        historyTestClearHistory();
    }
});

test('findLastByType() filters and orders per hierarchy level', function (): void {
    historyTestInsertSummary(2025, 6, 10, 2, 5, 1, 10);
    historyTestInsertSummary(2026, 7, 12, 3, 20, 11, 30);
    historyTestInsertSummary(2026, 7, 12, 4, 15, 31, 40);
    historyTestInsertSummary(2026, null, null, null, 35, 1, 40); // month IS NULL "whole year" row

    try {
        $repo = historyTestRepo();
        $hours = $repo->findLastByType('hour', 10);
        expect($hours)->toHaveCount(3)
            ->and($hours[0]->hour)->toBe(4)
            ->and($hours[1]->hour)->toBe(3)
            ->and($hours[2]->hour)->toBe(2);

        expect($repo->findLastByType('hour', 1))->toHaveCount(1);

        $default = $repo->findLastByType('year', 10);
        expect($default)->toHaveCount(1)
            ->and($default[0]->year)->toBe(2026)
            ->and($default[0]->month)->toBeNull();
    } finally {
        historyTestClearSummary();
    }
});

test('findMonthlyRows() returns month-level rows most recent first and respects the limit', function (): void {
    historyTestInsertSummary(2025, 6, null, null, 5, 1, 10);
    historyTestInsertSummary(2026, 7, null, null, 20, 11, 30);
    historyTestInsertSummary(2026, 7, 12, null, 20, 11, 30); // day-level, excluded

    try {
        $repo = historyTestRepo();
        $rows = $repo->findMonthlyRows(null);
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->year)->toBe(2026)
            ->and($rows[1]->year)->toBe(2025);

        expect($repo->findMonthlyRows(1))->toHaveCount(1);
    } finally {
        historyTestClearSummary();
    }
});

test('findDailyRowsForMonths() matches only the three given year/month pairs', function (): void {
    historyTestInsertSummary(2026, 7, 5, null, 5, 1, 10);
    historyTestInsertSummary(2026, 6, 5, null, 6, 11, 20);
    historyTestInsertSummary(2025, 7, 5, null, 7, 21, 30);
    historyTestInsertSummary(2024, 1, 5, null, 8, 31, 40); // not one of the 3 pairs, excluded

    try {
        $rows = historyTestRepo()->findDailyRowsForMonths(2026, 7, 2026, 6, 2025, 7);

        expect($rows)->toHaveCount(3);
        $years = array_column($rows, 'year');
        sort($years);
        expect($years)->toBe([2025, 2026, 2026]);
    } finally {
        historyTestClearSummary();
    }
});

test('findAverageDailyPageViewsSince() averages matching day-level rows', function (): void {
    historyTestInsertSummary(2026, 3, 1, null, 10, 1, 10);
    historyTestInsertSummary(2026, 3, 2, null, 20, 11, 20);
    historyTestInsertSummary(2025, 11, 1, null, 999, 21, 30); // previousYear but month <= afterMonth, excluded
    historyTestInsertSummary(2025, 12, 1, null, 30, 31, 40); // previousYear, month > afterMonth, included

    try {
        $avg = historyTestRepo()->findAverageDailyPageViewsSince(2026, 2025, 11);

        expect($avg)->not->toBeNull();
        if ($avg === null) {
            throw new RuntimeException('unreachable');
        }
        expect(abs($avg - 20.0))->toBeLessThan(0.001);
    } finally {
        historyTestClearSummary();
    }
});

test('findAverageDailyPageViewsSince() returns null when nothing matches', function (): void {
    expect(historyTestRepo()->findAverageDailyPageViewsSince(2026, 2025, 11))->toBeNull();
});

test('updateLastVisitNow() sets last_visit on the real user_infos row', function (): void {
    $conn = DbConnection::build();
    $before = $conn->createQueryBuilder()
        ->select('last_visit')
        ->from(Tables::userInfos())
        ->where('user_id = 4')
        ->executeQuery()
        ->fetchOne();
    expect($before)->toBeNull();

    historyTestRepo()->updateLastVisitNow(4);

    try {
        $after = $conn->createQueryBuilder()
            ->select('last_visit')
            ->from(Tables::userInfos())
            ->where('user_id = 4')
            ->executeQuery()
            ->fetchOne();
        expect($after)->toBeString()
            ->and($after)->not->toBe('');
    } finally {
        $conn->executeStatement('UPDATE ' . Tables::userInfos() . ' SET last_visit = NULL WHERE user_id = 4');
    }
});

test('updateLastVisitNow() clears the identity map, so a later find() sees the real update instead of a stale cached entity', function (): void {
    [$repo, $em] = historyTestRepoWithEm();
    $userIdVo = UserId::from(4);
    $tracked = $em->find(UserInfoEntity::class, $userIdVo);
    expect($tracked)->not->toBeNull();
    if (! $tracked instanceof UserInfoEntity) {
        throw new RuntimeException('unreachable');
    }
    expect($tracked->lastVisit)->toBeNull();

    try {
        $repo->updateLastVisitNow(4);

        $refetched = $em->find(UserInfoEntity::class, $userIdVo);
        if (! $refetched instanceof UserInfoEntity) {
            throw new RuntimeException('expected the row to still exist');
        }
        expect($refetched->lastVisit)->not->toBeNull();
    } finally {
        DbConnection::build()->executeStatement('UPDATE ' . Tables::userInfos() . ' SET last_visit = NULL WHERE user_id = 4');
    }
});
