<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;

/**
 * Piwigo\Permalink\PermalinkService -- has its own dedicated
 * tests/Integration/PermalinkServiceTest.php; this is the same spec
 * (same scenarios, same slug-generation convention) ported down to the
 * Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php pattern for
 * the repository, plus the existing tests/Support factories for
 * Lang/PageState. LangTestFactory has no pre-boot fallback (Lang's own
 * Paths collaborator has no autowireable default), so this needs a real
 * Kernel::boot() -- PermissionServiceTest.php's own established
 * beforeEach()/afterEach() precedent for exactly this, not a full
 * Integration-style HTTP bootstrap.
 *
 * Uses 2 disposable categories, not real fixture ids -- both inserted
 * fresh in beforeEach() and deleted in afterEach(): $this->catId ("the"
 * category under test) and $this->otherCatId (for the handful of tests
 * that need a *different* category than the one under test). This file
 * used to hardcode fixture category 2 for $this->catId, coordinating via
 * a docblock note with PermalinkRepositoryTest.php (a separate file, so
 * a separate --parallel worker) supposedly claiming category 1
 * exclusively -- except PermalinkRepositoryTest.php's own docblock made
 * the mirror-image claim about THIS file owning category 1, and both
 * ended up hardcoding category 2 instead. Confirmed live: this file's
 * own continuous category-2 permalink churn (every single test, via
 * beforeEach()/afterEach()) raced against
 * PermalinkRepositoryTest.php's findPermalinkMatches() test under
 * --parallel. Disposable, per-test-run categories sidestep this
 * permanently -- no hardcoded id for any file to silently start
 * colliding on again after a future refactor.
 *
 * 3 confirmed-equivalent mutations in setCatPermalink(), not
 * individually tested: the sanitizer's `preg_replace(..., '', $permalink)`
 * empty-string replacement mutated to a non-empty one is unobservable
 * either way -- $sanitized_permalink is a local variable only ever
 * compared with `!==` against the original, and ANY replacement (empty
 * or not) of a real disallowed character already makes that comparison
 * true, so the method's own return value/error path is identical
 * regardless of what the replacement string actually is; `(bool)
 * preg_match(...)` in the `or` boolean condition is a cast in an
 * already-coercing position (PHP auto-coerces preg_match()'s int/false
 * result to bool in a boolean context regardless), same category-5
 * inert-cast reasoning as this project's existing mutation-testing
 * gap-closure plan; the `(string)` cast on `trim()`'s own first
 * argument is unreachable in practice -- `preg_replace()` with a
 * string (not array) `$subject` only ever returns `null` on a regex
 * engine failure (invalid UTF-8 subject, backtrack/recursion limit),
 * never for any input this method's own fixed, simple pattern can
 * receive from a real caller (confirmed live: removing the cast by
 * hand and rerunning this file's own setCatPermalink() tests still
 * passes identically).
 */
function permalinkServiceTestRepo(): PermalinkRepository
{
    return new PermalinkRepository(EntityManagerFactory::build(DbConnection::build()));
}

function permalinkServiceTestService(PermalinkRepository $repo): PermalinkService
{
    return new PermalinkService(LangTestFactory::get(), $repo, new ProcessCache(), PageStateTestFactory::get());
}

function permalinkServiceTestSlug(string $suffix = ''): string
{
    return 'p17-unit-test-' . $suffix . bin2hex(random_bytes(4));
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    PageStateTestFactory::get()->reset();

    // Exempt from tests/Pest.php's blanket per-test transaction: every
    // test in this file mutates categories.permalink on the 2 disposable
    // categories created here, and `categories` carries a FULLTEXT index
    // whose auxiliary-index maintenance on INSERT can deadlock against
    // another --parallel worker's own concurrent categories INSERT when
    // held open for a whole test's duration -- same mechanism, same fix,
    // as TagServiceTest.php's 'getTagIds() creates a new tag for a plain
    // name when allowed' (reproduced live there: DeadlockException).
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $rankColumn = $conn->getDatabasePlatform()
        ->quoteSingleIdentifier('rank');
    $conn->insert('categories', [
        'name' => permalinkServiceTestSlug('under-test-cat-'),
    ]);
    $this->catId = (int) $conn->lastInsertId();
    // An explicit, high rank rather than leaving it to the schema's own
    // NULL default -- NULLs sort first in updateGlobalRank()'s own ORDER
    // BY, so a real fixture category 1 (rank 1) would otherwise be
    // displaced by this row for as long as it's live, the exact
    // mechanism SearchServiceTest.php's own 'zqualifiesonlycat' category
    // needed the same fix for.
    $conn->executeStatement("UPDATE categories SET {$rankColumn} = 999 WHERE id = {$this->catId}");
    $conn->insert('categories', [
        'name' => permalinkServiceTestSlug('other-cat-'),
    ]);
    $this->otherCatId = (int) $conn->lastInsertId();
    $conn->executeStatement("UPDATE categories SET {$rankColumn} = 999 WHERE id = {$this->otherCatId}");
});

afterEach(function (): void {
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->delete('categories')
        ->where('id = :id')
        ->setParameter('id', $this->catId)
        ->executeStatement();
    $conn->createQueryBuilder()
        ->delete('categories')
        ->where('id = :id')
        ->setParameter('id', $this->otherCatId)
        ->executeStatement();
    LangTestFactory::get()->reset();
    Kernel::reset();
});

test('deleteCatPermalink() forgets the cat_names ProcessCache entry to force regeneration', function (): void {
    $repo = permalinkServiceTestRepo();
    $processCache = new ProcessCache();
    $service = new PermalinkService(LangTestFactory::get(), $repo, $processCache, PageStateTestFactory::get());
    $slug = permalinkServiceTestSlug();

    $service->setCatPermalink($this->catId, $slug, false);
    $processCache->set('cat_names', [
        'stale' => true,
    ]);
    expect($processCache->has('cat_names'))
        ->toBeTrue();

    $service->deleteCatPermalink($this->catId, false);

    expect($processCache->has('cat_names'))
        ->toBeFalse();
});

test('setCatPermalink() forgets the cat_names ProcessCache entry to force regeneration', function (): void {
    $repo = permalinkServiceTestRepo();
    $processCache = new ProcessCache();
    $service = new PermalinkService(LangTestFactory::get(), $repo, $processCache, PageStateTestFactory::get());
    $slug = permalinkServiceTestSlug();
    $processCache->set('cat_names', [
        'stale' => true,
    ]);
    expect($processCache->has('cat_names'))
        ->toBeTrue();

    expect($service->setCatPermalink($this->catId, $slug, false))
        ->toBeTrue();

    expect($processCache->has('cat_names'))
        ->toBeFalse();
});

test('setCatPermalink() then deleteCatPermalink() round-trips', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();

    expect($service->setCatPermalink($this->catId, $slug, false))
        ->toBeTrue()
        ->and($repo->findPermalinkByCategoryId($this->catId))
        ->toBe($slug);

    expect($service->deleteCatPermalink($this->catId, false))
        ->toBeTrue()
        ->and($repo->findPermalinkByCategoryId($this->catId))
        ->toBeNull();
});

test('setCatPermalink() rejects a numeric permalink and records the exact error message, prefix and translated text alike', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());

    expect($service->setCatPermalink($this->catId, '12345', false))
        ->toBeFalse();

    // The full exact message, not just the '{12345} ' prefix -- a bare
    // prefix check can't tell a real concatenation of the translated
    // text apart from silently dropping it.
    $expected = '{12345} ' . LangTestFactory::get()->t('The permalink name must be composed of a-z, A-Z, 0-9, "-", "_" or "/". It must not be numeric or start with number followed by "-"');
    expect(PageStateTestFactory::get()->errors)->toBe([$expected]);
});

test('setCatPermalink() rejects disallowed characters', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());

    expect($service->setCatPermalink($this->catId, 'not valid!', false))
        ->toBeFalse();
});

test('setCatPermalink() rejects a leading/trailing slash (trim() makes it differ from the sanitized form)', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());

    expect($service->setCatPermalink($this->catId, '/foo/', false))
        ->toBeFalse();
});

test('setCatPermalink() rejects a double slash (collapsed to a single one by the sanitizer)', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());

    expect($service->setCatPermalink($this->catId, 'foo//bar', false))
        ->toBeFalse();
});

test('setCatPermalink() rejects an already-used permalink and records the exact error message', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();

    try {
        expect($service->setCatPermalink($this->catId, $slug, false))
            ->toBeTrue();

        expect($service->setCatPermalink($this->otherCatId, $slug, false))
            ->toBeFalse();

        $expected = sprintf(LangTestFactory::get()->t('Permalink %s is already used by album %s'), $slug, $this->catId);
        expect(PageStateTestFactory::get()->errors)->toBe([$expected]);
    } finally {
        $repo->clearCategoryPermalink($this->otherCatId);
    }
});

test('setCatPermalink() is a no-op success when unchanged', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());
    $slug = permalinkServiceTestSlug();

    expect($service->setCatPermalink($this->catId, $slug, false))
        ->toBeTrue()
        ->and($service->setCatPermalink($this->catId, $slug, false))
        ->toBeTrue();
});

test('deleteCatPermalink() with no permalink set succeeds as a no-op', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());

    expect($service->deleteCatPermalink($this->catId, false))
        ->toBeTrue();
});

test('setCatPermalink() then deleteCatPermalink() with save records history and blocks reuse on another category', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();

    try {
        expect($service->setCatPermalink($this->catId, $slug, true))
            ->toBeTrue()
            ->and($service->deleteCatPermalink($this->catId, true))
            ->toBeTrue();

        // Now historically used -- setting it on a DIFFERENT category must
        // be rejected until the history entry is removed.
        expect($service->setCatPermalink($this->otherCatId, $slug, false))
            ->toBeFalse();

        $expected = sprintf(LangTestFactory::get()->t('Permalink %s has been previously used by album %s. Delete from the permalink history first'), $slug, $this->catId);
        expect(PageStateTestFactory::get()->errors)->toBe([$expected]);
    } finally {
        $repo->deleteOldPermalink($this->catId, $slug);
    }
});

test('deleteOldPermalinkByValue() removes a recorded history entry', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();
    $repo->insertOldPermalinkDeleted($this->catId, $slug);

    expect($service->deleteOldPermalinkByValue($slug))
        ->toBeTrue()
        ->and($repo->findOldCategoryId($slug))
        ->toBeNull();
});

test('deleteOldPermalinkByValue() returns false and records an error when unmatched', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());

    expect($service->deleteOldPermalinkByValue(permalinkServiceTestSlug('never-used-')))
        ->toBeFalse()
        ->and(PageStateTestFactory::get()->errors)->not->toBe([]);
});

test('deleteCatPermalink() with save rejects when the live permalink was historically owned by a different category', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();

    // deleteCatPermalink()'s own internal "previously used by a different
    // album" guard is distinct from setCatPermalink()'s *caller-side*
    // check of the same shape. Normal setCatPermalink() usage can never
    // actually put a category into this state (its own guard blocks
    // setting a permalink that history says belongs to someone else) --
    // constructing it directly via the repository, bypassing that guard,
    // is the only way to reach this specific branch in isolation.
    $repo->setCategoryPermalink($this->catId, $slug);
    $repo->insertOldPermalinkDeleted($this->otherCatId, $slug);

    try {
        expect($service->deleteCatPermalink($this->catId, true))
            ->toBeFalse()
            ->and(PageStateTestFactory::get()->errors)->not->toBe([])
            ->and($repo->findPermalinkByCategoryId($this->catId))
            ->toBe($slug);
    } finally {
        $repo->deleteOldPermalink($this->otherCatId, $slug);
    }
});

test('deleteCatPermalink() with save marks an already-recorded history row deleted again, instead of duplicating it', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();

    // A history row already exists for THIS SAME category/permalink pair
    // (as opposed to insertOldPermalinkDeleted()'s "no history row yet"
    // branch) -- deleteCatPermalink() must UPDATE that existing row
    // (markOldPermalinkDeleted) rather than inserting a duplicate, which
    // would violate old_permalinks' own permalink-as-primary-key
    // constraint. markOldPermalinkDeleted() doesn't touch catId (it's
    // part of the WHERE, not the SET) -- only date_deleted, so this seeds
    // a deliberately old, distinctive date_deleted via raw SQL first;
    // if markOldPermalinkDeleted() were skipped entirely (removed), that
    // stale date would still be sitting there afterward.
    $conn = DbConnection::build();
    $repo->setCategoryPermalink($this->catId, $slug);
    $repo->insertOldPermalinkDeleted($this->catId, $slug);
    $conn->createQueryBuilder()
        ->update('old_permalinks')
        ->set('date_deleted', ':date')
        ->where('permalink = :permalink')
        ->setParameter('date', '2020-01-01 00:00:00')
        ->setParameter('permalink', $slug)
        ->executeStatement();

    try {
        expect($service->deleteCatPermalink($this->catId, true))
            ->toBeTrue()
            ->and($repo->findPermalinkByCategoryId($this->catId))
            ->toBeNull()
            ->and($repo->findOldCategoryId($slug))
            ->toBe($this->catId);

        $dateDeleted = $conn->createQueryBuilder()
            ->select('date_deleted')
            ->from('old_permalinks')
            ->where('permalink = :permalink')
            ->setParameter('permalink', $slug)
            ->fetchOne();

        expect($dateDeleted)
            ->not->toBe('2020-01-01 00:00:00');
    } finally {
        $repo->deleteOldPermalink($this->catId, $slug);
    }
});

test('setCatPermalink() clears the reclaimed permalink\'s own stale history row', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();

    expect($service->setCatPermalink($this->catId, $slug, true))
        ->toBeTrue()
        ->and($service->deleteCatPermalink($this->catId, true))
        ->toBeTrue()
        ->and($repo->findOldCategoryId($slug))
        ->toBe($this->catId);

    // Re-claiming the SAME permalink back onto the SAME category it was
    // historically deleted from must succeed AND clear that now-stale
    // history row -- distinct from the cross-category rejection above.
    expect($service->setCatPermalink($this->catId, $slug, true))
        ->toBeTrue()
        ->and($repo->findOldCategoryId($slug))
        ->toBeNull()
        ->and($repo->findPermalinkByCategoryId($this->catId))
        ->toBe($slug);
});

test('setCatPermalink() fails when its own internal delete of the old permalink is rejected', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $oldSlug = permalinkServiceTestSlug('old-');
    $newSlug = permalinkServiceTestSlug('new-');

    // Same inconsistent-state construction as the "deleteCatPermalink()
    // with save rejects..." test above, but reached through
    // setCatPermalink() as the caller this time: the category under
    // test's CURRENT live permalink ($oldSlug) is one history says
    // belongs to the other category, so setCatPermalink()'s own internal
    // "clear the old one first" step fails, and that failure must
    // propagate out of setCatPermalink() itself as false.
    $repo->setCategoryPermalink($this->catId, $oldSlug);
    $repo->insertOldPermalinkDeleted($this->otherCatId, $oldSlug);

    try {
        expect($service->setCatPermalink($this->catId, $newSlug, true))
            ->toBeFalse()
            ->and($repo->findPermalinkByCategoryId($this->catId))
            ->toBe($oldSlug);
    } finally {
        $repo->deleteOldPermalink($this->otherCatId, $oldSlug);
    }
});
