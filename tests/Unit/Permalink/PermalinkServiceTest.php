<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;
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
 * Uses fixture category 2 as "the" category under test, not category 1 --
 * PermalinkRepositoryTest.php (a separate file, so a separate --parallel
 * worker) owns category 1 exclusively, tied to the fixture's own
 * old_permalinks seed row (cat_id=1, 'old-sample-album'); confirmed live
 * that both files targeting category 1 concurrently raced
 * (findPermalinkMatches() intermittently saw a permalink this file had
 * just cleared/overwritten). A 2nd, disposable category (inserted in
 * beforeEach(), $this->otherCatId) stands in for the handful of tests
 * that need a *different* category than the one under test, since the
 * fixture only ships 2 real categories total and category 2 is now this
 * file's own.
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
    permalinkServiceTestRepo()
        ->clearCategoryPermalink(2);

    $conn = DbConnection::build();
    $conn->insert('categories', [
        'name' => permalinkServiceTestSlug('other-cat-'),
    ]);
    $this->otherCatId = (int) $conn->lastInsertId();
});

afterEach(function (): void {
    permalinkServiceTestRepo()->clearCategoryPermalink(2);
    DbConnection::build()->createQueryBuilder()->delete('categories')->where('id = :id')->setParameter('id', $this->otherCatId)->executeStatement();
    LangTestFactory::get()->reset();
    Kernel::reset();
});

test('deleteCatPermalink() forgets the cat_names ProcessCache entry to force regeneration', function (): void {
    $repo = permalinkServiceTestRepo();
    $processCache = new ProcessCache();
    $service = new PermalinkService(LangTestFactory::get(), $repo, $processCache, PageStateTestFactory::get());
    $slug = permalinkServiceTestSlug();

    $service->setCatPermalink(2, $slug, false);
    $processCache->set('cat_names', [
        'stale' => true,
    ]);
    expect($processCache->has('cat_names'))
        ->toBeTrue();

    $service->deleteCatPermalink(2, false);

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

    expect($service->setCatPermalink(2, $slug, false))
        ->toBeTrue();

    expect($processCache->has('cat_names'))
        ->toBeFalse();
});

test('setCatPermalink() then deleteCatPermalink() round-trips', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();

    expect($service->setCatPermalink(2, $slug, false))
        ->toBeTrue()
        ->and($repo->findPermalinkByCategoryId(2))
        ->toBe($slug);

    expect($service->deleteCatPermalink(2, false))
        ->toBeTrue()
        ->and($repo->findPermalinkByCategoryId(2))
        ->toBeNull();
});

test('setCatPermalink() rejects a numeric permalink and records the exact error message, prefix and translated text alike', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());

    expect($service->setCatPermalink(2, '12345', false))
        ->toBeFalse();

    // The full exact message, not just the '{12345} ' prefix -- a bare
    // prefix check can't tell a real concatenation of the translated
    // text apart from silently dropping it.
    $expected = '{12345} ' . LangTestFactory::get()->t('The permalink name must be composed of a-z, A-Z, 0-9, "-", "_" or "/". It must not be numeric or start with number followed by "-"');
    expect(PageStateTestFactory::get()->errors)->toBe([$expected]);
});

test('setCatPermalink() rejects disallowed characters', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());

    expect($service->setCatPermalink(2, 'not valid!', false))
        ->toBeFalse();
});

test('setCatPermalink() rejects a leading/trailing slash (trim() makes it differ from the sanitized form)', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());

    expect($service->setCatPermalink(2, '/foo/', false))
        ->toBeFalse();
});

test('setCatPermalink() rejects a double slash (collapsed to a single one by the sanitizer)', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());

    expect($service->setCatPermalink(2, 'foo//bar', false))
        ->toBeFalse();
});

test('setCatPermalink() rejects an already-used permalink and records the exact error message', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();

    try {
        expect($service->setCatPermalink(2, $slug, false))
            ->toBeTrue();

        expect($service->setCatPermalink($this->otherCatId, $slug, false))
            ->toBeFalse();

        $expected = sprintf(LangTestFactory::get()->t('Permalink %s is already used by album %s'), $slug, 2);
        expect(PageStateTestFactory::get()->errors)->toBe([$expected]);
    } finally {
        $repo->clearCategoryPermalink($this->otherCatId);
    }
});

test('setCatPermalink() is a no-op success when unchanged', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());
    $slug = permalinkServiceTestSlug();

    expect($service->setCatPermalink(2, $slug, false))
        ->toBeTrue()
        ->and($service->setCatPermalink(2, $slug, false))
        ->toBeTrue();
});

test('deleteCatPermalink() with no permalink set succeeds as a no-op', function (): void {
    $service = permalinkServiceTestService(permalinkServiceTestRepo());

    expect($service->deleteCatPermalink(2, false))
        ->toBeTrue();
});

test('setCatPermalink() then deleteCatPermalink() with save records history and blocks reuse on another category', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();

    try {
        expect($service->setCatPermalink(2, $slug, true))
            ->toBeTrue()
            ->and($service->deleteCatPermalink(2, true))
            ->toBeTrue();

        // Now historically used -- setting it on a DIFFERENT category must
        // be rejected until the history entry is removed.
        expect($service->setCatPermalink($this->otherCatId, $slug, false))
            ->toBeFalse();

        $expected = sprintf(LangTestFactory::get()->t('Permalink %s has been previously used by album %s. Delete from the permalink history first'), $slug, 2);
        expect(PageStateTestFactory::get()->errors)->toBe([$expected]);
    } finally {
        $repo->deleteOldPermalink(2, $slug);
    }
});

test('deleteOldPermalinkByValue() removes a recorded history entry', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();
    $repo->insertOldPermalinkDeleted(2, $slug);

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
    $repo->setCategoryPermalink(2, $slug);
    $repo->insertOldPermalinkDeleted($this->otherCatId, $slug);

    try {
        expect($service->deleteCatPermalink(2, true))
            ->toBeFalse()
            ->and(PageStateTestFactory::get()->errors)->not->toBe([])
            ->and($repo->findPermalinkByCategoryId(2))
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
    $repo->setCategoryPermalink(2, $slug);
    $repo->insertOldPermalinkDeleted(2, $slug);
    $conn->createQueryBuilder()
        ->update('old_permalinks')
        ->set('date_deleted', ':date')
        ->where('permalink = :permalink')
        ->setParameter('date', '2020-01-01 00:00:00')
        ->setParameter('permalink', $slug)
        ->executeStatement();

    try {
        expect($service->deleteCatPermalink(2, true))
            ->toBeTrue()
            ->and($repo->findPermalinkByCategoryId(2))
            ->toBeNull()
            ->and($repo->findOldCategoryId($slug))
            ->toBe(2);

        $dateDeleted = $conn->createQueryBuilder()
            ->select('date_deleted')
            ->from('old_permalinks')
            ->where('permalink = :permalink')
            ->setParameter('permalink', $slug)
            ->fetchOne();

        expect($dateDeleted)
            ->not->toBe('2020-01-01 00:00:00');
    } finally {
        $repo->deleteOldPermalink(2, $slug);
    }
});

test('setCatPermalink() clears the reclaimed permalink\'s own stale history row', function (): void {
    $repo = permalinkServiceTestRepo();
    $service = permalinkServiceTestService($repo);
    $slug = permalinkServiceTestSlug();

    expect($service->setCatPermalink(2, $slug, true))
        ->toBeTrue()
        ->and($service->deleteCatPermalink(2, true))
        ->toBeTrue()
        ->and($repo->findOldCategoryId($slug))
        ->toBe(2);

    // Re-claiming the SAME permalink back onto the SAME category it was
    // historically deleted from must succeed AND clear that now-stale
    // history row -- distinct from the cross-category rejection above.
    expect($service->setCatPermalink(2, $slug, true))
        ->toBeTrue()
        ->and($repo->findOldCategoryId($slug))
        ->toBeNull()
        ->and($repo->findPermalinkByCategoryId(2))
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
    $repo->setCategoryPermalink(2, $oldSlug);
    $repo->insertOldPermalinkDeleted($this->otherCatId, $oldSlug);

    try {
        expect($service->setCatPermalink(2, $newSlug, true))
            ->toBeFalse()
            ->and($repo->findPermalinkByCategoryId(2))
            ->toBe($oldSlug);
    } finally {
        $repo->deleteOldPermalink($this->otherCatId, $oldSlug);
    }
});
