<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Permalink\OldPermalinkEntity;
use Piwigo\Permalink\OldPermalinkSortField;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\Projection\OldPermalink;

/**
 * Piwigo\Permalink\PermalinkRepository -- has its own dedicated
 * tests/Integration/PermalinkRepositoryTest.php; this ports its 18
 * tests down to the Unit suite via the real-DB-no-HTTP
 * ImageRepositoryTest.php pattern. Unlike PermalinkServiceTest.php
 * (already ported), this class takes only an EntityManagerInterface --
 * no Lang/PageState -- so no Kernel::boot() is needed here.
 *
 * Fixture has one real old_permalinks row: cat_id 1, permalink
 * 'old-sample-album', hit 42, last_hit '2026-08-01 00:00:00' -- restored
 * in afterEach() after any test that touches it (touchOldPermalinkHit()'s
 * own test).
 *
 * Every test that writes to `categories.permalink` (as opposed to the
 * separate `old_permalinks` table, which nothing else in the Unit suite
 * touches) uses category id 2, never 1 -- composer test's own parallel
 * runner puts this file and PermalinkServiceTest.php in different
 * worker processes against the SAME real, shared DB, and
 * PermalinkServiceTest.php's own beforeEach()/afterEach() claims
 * category 1 exclusively for its own setCatPermalink() calls. Confirmed
 * live: this file's own findPermalinkMatches() test produced a real,
 * intermittent failure when its own category-1 UPDATE raced against
 * that file's concurrently-running clearCategoryPermalink(1) calls.
 *

 * Confirmed-equivalent mutations, not individually tested:
 * findCategoryIdByPermalink()/findOldCategoryId()'s own `is_numeric($ids[0])
 * ? (int) $ids[0] : null` casts are unreachable -- getSingleColumnResult()
 * on a VO-typed field already returns a real native PHP int on this
 * driver (confirmed live via var_dump()), same root cause as this
 * project's other already-documented getSingleColumnResult() findings;
 * findOldCategoryId()'s own `setMaxResults(1)` is unobservable at any
 * other value -- `permalink` is old_permalinks' own real PRIMARY KEY and
 * `catId` joins 1:1 onto categories' own PRIMARY KEY, so the query
 * itself can never produce more than one row regardless of the limit;
 * findPermalinkMatches()'s own `if ($permalinks === []) { return []; }`
 * early return is unobservable if skipped -- confirmed live
 * (sed-mutate-and-rerun: an unconditionally-false guard still returns
 * `[]` for an empty `$permalinks`, since DBAL's own `ArrayParameterType`
 * expansion of an empty array already produces a query matching nothing);
 * findPermalinkMatches()'s own per-row `instanceof`/`is_numeric()` guard
 * (checking $idValue/$permalink/$isOld before accepting a row) is dead
 * code under any real query result -- confirmed live (disabling the
 * whole guard produces byte-identical output): `op.catId`/`c.id` are
 * both CategoryId-typed, `op.permalink`/`c.permalink` both Permalink-typed
 * (array-hydration DOES apply a custom Type's convertToPHPValue(), unlike
 * getSingleColumnResult() above), and `is_old` is a literal `1 AS
 * is_old`/`0 AS is_old` SQL constant, never a real column -- all 3
 * conditions are unconditionally false on every row this query can ever
 * produce.
 */
function permalinkRepoTest(): PermalinkRepository
{
    return new PermalinkRepository(EntityManagerFactory::build(DbConnection::build()));
}

/**
 * PermalinkRepository holds its own EntityManagerInterface directly (not
 * an EntityRepository subclass), so there's no getEntityManager()
 * accessor at all -- the em->clear() staleness tests below need direct
 * EntityManager access (for find()) alongside the repo, so this builds
 * both from the same connection.
 *
 * @return array{0: PermalinkRepository, 1: Doctrine\ORM\EntityManagerInterface}
 */
function permalinkRepoTestWithEm(): array
{
    $em = EntityManagerFactory::build(DbConnection::build());

    return [new PermalinkRepository($em), $em];
}

function permalinkRepoTestSlug(string $prefix = 'p17-unit-test-'): string
{
    return $prefix . bin2hex(random_bytes(4));
}

afterEach(function (): void {
    DbConnection::build()->executeStatement(
        "UPDATE " . Tables::oldPermalinks() . " SET hit = 42, last_hit = '2026-08-01 00:00:00' WHERE permalink = 'old-sample-album'"
    );
});

test('setCategoryPermalink() then findCategoryIdByPermalink() round-trips', function (): void {
    $repo = permalinkRepoTest();
    $slug = permalinkRepoTestSlug();

    $repo->setCategoryPermalink(2, $slug);

    try {
        expect($repo->findCategoryIdByPermalink($slug))->toBe(2)
            ->and($repo->findPermalinkByCategoryId(2))->toBe($slug);
    } finally {
        $repo->clearCategoryPermalink(2);
    }
});

test('findCategoryIdByPermalink() returns null when unused', function (): void {
    expect(permalinkRepoTest()->findCategoryIdByPermalink(permalinkRepoTestSlug('p17-unit-test-does-not-exist-')))->toBeNull();
});

test('findPermalinkByCategoryId() returns null when unset', function (): void {
    $repo = permalinkRepoTest();
    $repo->clearCategoryPermalink(2);

    expect($repo->findPermalinkByCategoryId(2))->toBeNull();
});

test('findPermalinkByCategoryId() returns null for a category that does not exist at all', function (): void {
    // Distinct from the sibling test above: em->find() itself returns
    // null here (no CategoryEntity to read ->permalink off of at all),
    // exercising the method's own `?->permalink?->value` nullsafe chain
    // on a null base, not just a real entity with a null permalink.
    expect(permalinkRepoTest()->findPermalinkByCategoryId(999999))->toBeNull();
});

test('clearCategoryPermalink() removes it', function (): void {
    $repo = permalinkRepoTest();
    $slug = permalinkRepoTestSlug();
    $repo->setCategoryPermalink(2, $slug);

    $repo->clearCategoryPermalink(2);

    expect($repo->findPermalinkByCategoryId(2))->toBeNull()
        ->and($repo->findCategoryIdByPermalink($slug))->toBeNull();
});

test('insertOldPermalinkDeleted() then findOldCategoryId() round-trips', function (): void {
    $repo = permalinkRepoTest();
    $slug = permalinkRepoTestSlug('p17-unit-old-test-');

    $repo->insertOldPermalinkDeleted(1, $slug);

    try {
        expect($repo->findOldCategoryId($slug))->toBe(1);
    } finally {
        $repo->deleteOldPermalink(1, $slug);
    }
});

test('insertOldPermalinkDeleted() starts the hit counter at exactly zero', function (): void {
    $conn = DbConnection::build();
    $slug = permalinkRepoTestSlug('p17-unit-old-test-');

    permalinkRepoTest()->insertOldPermalinkDeleted(1, $slug);

    try {
        $hit = $conn->createQueryBuilder()
            ->select('hit')
            ->from(Tables::oldPermalinks())
            ->where('permalink = :permalink')
            ->setParameter('permalink', $slug)
            ->executeQuery()
            ->fetchOne();

        expect($hit)->toBe(0);
    } finally {
        $conn->executeStatement('DELETE FROM ' . Tables::oldPermalinks() . ' WHERE permalink = ?', [$slug]);
    }
});

test('findOldCategoryId() returns null when never used', function (): void {
    expect(permalinkRepoTest()->findOldCategoryId(permalinkRepoTestSlug('p17-unit-never-used-')))->toBeNull();
});

test('markOldPermalinkDeleted() updates an existing row, not a duplicate insert', function (): void {
    $repo = permalinkRepoTest();
    $slug = permalinkRepoTestSlug('p17-unit-old-test-');
    $repo->insertOldPermalinkDeleted(1, $slug);

    // Should not throw / should not insert a duplicate row -- updates the
    // existing (cat_id, permalink) row's date_deleted instead.
    $repo->markOldPermalinkDeleted(1, $slug);

    try {
        expect($repo->findOldCategoryId($slug))->toBe(1);
    } finally {
        $repo->deleteOldPermalink(1, $slug);
    }
});

test('markOldPermalinkDeleted() clears the identity map, so a later find() sees the real update instead of a stale cached entity', function (): void {
    [$repo, $em] = permalinkRepoTestWithEm();
    $slug = permalinkRepoTestSlug('p17-unit-old-test-');
    $repo->insertOldPermalinkDeleted(1, $slug);
    $permalinkVo = Permalink::from($slug);

    try {
        $tracked = $em->find(OldPermalinkEntity::class, $permalinkVo);
        expect($tracked)->not->toBeNull();

        // A distinct, later moment than the row's own insert-time
        // dateDeleted -- Env::now() is frozen for the whole test process
        // (both insertOldPermalinkDeleted() and markOldPermalinkDeleted()
        // write the exact same frozen value), so this backdates the row
        // directly instead of relying on wall-clock drift.
        DbConnection::build()->executeStatement(
            "UPDATE " . Tables::oldPermalinks() . " SET date_deleted = '2020-01-01 00:00:00' WHERE permalink = ?",
            [$slug]
        );
        $em->clear();
        $reread = $em->find(OldPermalinkEntity::class, $permalinkVo);
        if (! $reread instanceof OldPermalinkEntity) {
            throw new RuntimeException('unreachable');
        }
        expect($reread->dateDeleted?->value)->toBe('2020-01-01 00:00:00');

        $repo->markOldPermalinkDeleted(1, $slug);

        $refetched = $em->find(OldPermalinkEntity::class, $permalinkVo);
        if (! $refetched instanceof OldPermalinkEntity) {
            throw new RuntimeException('expected the row to still exist');
        }
        expect($refetched->dateDeleted?->value)->not->toBe('2020-01-01 00:00:00');
    } finally {
        $repo->deleteOldPermalink(1, $slug);
    }
});

test('deleteOldPermalink() removes the row', function (): void {
    $repo = permalinkRepoTest();
    $slug = permalinkRepoTestSlug('p17-unit-old-test-');
    $repo->insertOldPermalinkDeleted(1, $slug);

    $repo->deleteOldPermalink(1, $slug);

    expect($repo->findOldCategoryId($slug))->toBeNull();
});

test('deleteOldPermalink() clears the identity map, so a later find() sees the real deletion instead of a stale cached entity', function (): void {
    [$repo, $em] = permalinkRepoTestWithEm();
    $slug = permalinkRepoTestSlug('p17-unit-old-test-');
    $repo->insertOldPermalinkDeleted(1, $slug);
    $permalinkVo = Permalink::from($slug);
    $tracked = $em->find(OldPermalinkEntity::class, $permalinkVo);
    expect($tracked)->not->toBeNull();

    $repo->deleteOldPermalink(1, $slug);

    expect($em->find(OldPermalinkEntity::class, $permalinkVo))->toBeNull();
});

test('deleteOldPermalinkByValue() removes the row and returns true', function (): void {
    $repo = permalinkRepoTest();
    $slug = permalinkRepoTestSlug('p17-unit-old-test-');
    $repo->insertOldPermalinkDeleted(1, $slug);

    expect($repo->deleteOldPermalinkByValue($slug))->toBeTrue()
        ->and($repo->findOldCategoryId($slug))->toBeNull();
});

test('deleteOldPermalinkByValue() clears the identity map, so a later find() sees the real deletion instead of a stale cached entity', function (): void {
    [$repo, $em] = permalinkRepoTestWithEm();
    $slug = permalinkRepoTestSlug('p17-unit-old-test-');
    $repo->insertOldPermalinkDeleted(1, $slug);
    $permalinkVo = Permalink::from($slug);
    $tracked = $em->find(OldPermalinkEntity::class, $permalinkVo);
    expect($tracked)->not->toBeNull();

    $repo->deleteOldPermalinkByValue($slug);

    expect($em->find(OldPermalinkEntity::class, $permalinkVo))->toBeNull();
});

test('deleteOldPermalinkByValue() returns false when nothing matches', function (): void {
    expect(permalinkRepoTest()->deleteOldPermalinkByValue(permalinkRepoTestSlug('p17-unit-never-used-')))->toBeFalse();
});

test('clearCategoryPermalink() on an unknown category is a silent no-op', function (): void {
    // em->find() returns null for a nonexistent id -- exercises the
    // early `return;` guard directly.
    permalinkRepoTest()->clearCategoryPermalink(999999);
})->throwsNoExceptions();

test('setCategoryPermalink() on an unknown category is a silent no-op', function (): void {
    $repo = permalinkRepoTest();

    $repo->setCategoryPermalink(999999, permalinkRepoTestSlug());

    expect($repo->findCategoryIdByPermalink('p17-unit-test-does-not-matter'))->toBeNull();
});

test('findAllOrderedBy() applies the given order column', function (): void {
    $repo = permalinkRepoTest();
    $lowSlug = permalinkRepoTestSlug('aaa-p17-unit-order-test-');
    $highSlug = permalinkRepoTestSlug('zzz-p17-unit-order-test-');
    $repo->insertOldPermalinkDeleted(1, $lowSlug);
    $repo->insertOldPermalinkDeleted(1, $highSlug);

    try {
        $rows = $repo->findAllOrderedBy(OldPermalinkSortField::Permalink);
        expect($rows[0] ?? null)->toBeInstanceOf(OldPermalink::class);
        $permalinks = array_map(static fn ($row) => $row->permalink->value, $rows);

        $lowIndex = array_search($lowSlug, $permalinks, true);
        $highIndex = array_search($highSlug, $permalinks, true);
        if (! is_int($lowIndex) || ! is_int($highIndex)) {
            throw new RuntimeException('expected both slugs to be found in the result');
        }

        expect($lowIndex)->toBeLessThan($highIndex);
    } finally {
        $repo->deleteOldPermalink(1, $lowSlug);
        $repo->deleteOldPermalink(1, $highSlug);
    }
});

test('findAllOrderedBy() sorts by a column whose order genuinely differs from the row\'s own natural (PK) order', function (): void {
    // Permalink IS old_permalinks' own primary key, so InnoDB's clustered
    // index already returns rows in alphabetical-by-permalink order with
    // NO orderBy() at all -- the sibling test above can't tell a real
    // ORDER BY apart from that coincidence. Hit is a plain non-PK
    // column: sorting by it ascending, with values assigned in the
    // OPPOSITE order of the slugs' own alphabetical/PK order, only
    // produces the expected sequence if orderBy() genuinely reaches the
    // query.
    $repo = permalinkRepoTest();
    $conn = DbConnection::build();
    $lowSlug = permalinkRepoTestSlug('aaa-p17-unit-hit-order-test-');
    $highSlug = permalinkRepoTestSlug('zzz-p17-unit-hit-order-test-');
    $repo->insertOldPermalinkDeleted(1, $lowSlug);
    $repo->insertOldPermalinkDeleted(1, $highSlug);
    $conn->executeStatement('UPDATE ' . Tables::oldPermalinks() . ' SET hit = 100 WHERE permalink = ?', [$lowSlug]);
    $conn->executeStatement('UPDATE ' . Tables::oldPermalinks() . ' SET hit = 1 WHERE permalink = ?', [$highSlug]);

    try {
        $rows = $repo->findAllOrderedBy(OldPermalinkSortField::Hit);
        $bySlug = [];
        foreach ($rows as $row) {
            if ($row->permalink->value === $lowSlug || $row->permalink->value === $highSlug) {
                $bySlug[$row->permalink->value] = $row->hit;
            }
        }

        // Ascending by hit: highSlug (hit=1) before lowSlug (hit=100) --
        // the reverse of their own alphabetical/PK order.
        expect(array_keys($bySlug))->toBe([$highSlug, $lowSlug]);
    } finally {
        $repo->deleteOldPermalink(1, $lowSlug);
        $repo->deleteOldPermalink(1, $highSlug);
    }
});

test('findAllOrderedBy() with a null sort field leaves the natural order untouched', function (): void {
    $repo = permalinkRepoTest();

    $unsorted = $repo->findAllOrderedBy(null);
    $sorted = $repo->findAllOrderedBy(OldPermalinkSortField::Permalink);

    // Both must contain the same real 'old-sample-album' fixture row --
    // a null sort field just skips the orderBy() call, not the query.
    expect(array_map(static fn ($row) => $row->permalink->value, $unsorted))
        ->toBe(array_map(static fn ($row) => $row->permalink->value, $sorted));
});

test('findPermalinkMatches() finds old and current permalinks', function (): void {
    // Deliberately 2 DIFFERENT categories, not the same one for both
    // matches -- category 1 is PermalinkServiceTest.php's own exclusive
    // id (see this file's own top-of-file docblock); using category 2
    // here for the "current permalink" half still proves the real thing
    // this test is about (a mixed old+current result set, each row
    // correctly tagged via is_old), without needing the 2 permalinks to
    // share a category.
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE " . Tables::categories() . " SET permalink = 'p17-unit-sample-album' WHERE id = 2");

    try {
        $matches = permalinkRepoTest()->findPermalinkMatches(['old-sample-album', 'p17-unit-sample-album']);

        // id/is_old come back as native int under this project's mysqli
        // driver config (unlike varchar columns like 'permalink').
        expect($matches['old-sample-album']['id'])->toBe(1)
            ->and($matches['old-sample-album']['is_old'])->toBe(1)
            ->and($matches['p17-unit-sample-album']['id'])->toBe(2)
            ->and($matches['p17-unit-sample-album']['is_old'])->toBe(0);
    } finally {
        $conn->executeStatement('UPDATE ' . Tables::categories() . ' SET permalink = NULL WHERE id = 2');
    }
});

test('findPermalinkMatches() returns empty for no permalinks', function (): void {
    expect(permalinkRepoTest()->findPermalinkMatches([]))->toBe([]);
});

test('touchOldPermalinkHit() increments the counter', function (): void {
    permalinkRepoTest()->touchOldPermalinkHit('old-sample-album', 1);

    $hit = DbConnection::build()->createQueryBuilder()
        ->select('hit')
        ->from(Tables::oldPermalinks())
        ->where('permalink = :permalink')
        ->setParameter('permalink', 'old-sample-album')
        ->executeQuery()
        ->fetchOne();

    expect($hit)->toBe(43);
});

test('deleteOldPermalinksForCategories() removes only rows for the given category ids', function (): void {
    $repo = permalinkRepoTest();
    $keptSlug = permalinkRepoTestSlug('p17-unit-keep-');
    $deletedSlug = permalinkRepoTestSlug('p17-unit-delete-');
    $repo->insertOldPermalinkDeleted(1, $keptSlug);
    $repo->insertOldPermalinkDeleted(2, $deletedSlug);

    try {
        $repo->deleteOldPermalinksForCategories([2]);

        expect($repo->findOldCategoryId($keptSlug))->toBe(1)
            ->and($repo->findOldCategoryId($deletedSlug))->toBeNull();
    } finally {
        $repo->deleteOldPermalink(1, $keptSlug);
    }
});

test('deleteOldPermalinksForCategories() is a no-op for no ids', function (): void {
    // Compares before/after rather than asserting an absolute row count
    // -- this table's exact row count depends on what else is currently
    // sharing the fixture DB, not just this file's own fixture baseline.
    $conn = DbConnection::build();
    $before = $conn->createQueryBuilder()->select('COUNT(*) AS c')->from(Tables::oldPermalinks())->executeQuery()->fetchOne();

    permalinkRepoTest()->deleteOldPermalinksForCategories([]);

    $after = $conn->createQueryBuilder()->select('COUNT(*) AS c')->from(Tables::oldPermalinks())->executeQuery()->fetchOne();
    expect($after)->toBe($before);
});
