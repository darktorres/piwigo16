<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Site\Projection\SiteCategoryImageCounts;
use Piwigo\Site\SiteEntity;
use Piwigo\Site\SiteRepository;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * Piwigo\Site\SiteRepository -- has its own dedicated
 * tests/Integration/SiteRepositoryTest.php; this ports its 13 tests
 * down to the Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php
 * pattern. CurrentPathsTestFactory has no pre-boot fallback (needs a
 * real container), so this needs a real Kernel::boot() --
 * PermalinkServiceTest.php's own established beforeEach()/afterEach()
 * precedent for exactly this.
 *
 * Fixture shape: 1 real sites row (id 1, galleries_url = Paths::$root .
 * 'galleries/'); both fixture categories have site_id NULL.
 *
 * Confirmed-equivalent mutations, not individually tested:
 * countByUrl()'s own `is_numeric($value) ? (int) $value : 0` cast is
 * unreachable -- getSingleScalarResult() on a plain (non-VO) COUNT()
 * already returns a real native PHP int on this driver; findGalleriesUrlById()'s
 * own `$id < -32768 || $id > 32767` range guard is Postgres-only
 * protection (see its own docblock) -- confirmed live
 * (sed-mutate-and-rerun: an unconditionally-false guard still safely
 * returns null for 999999/-999999/PHP_INT_MAX on this project's real
 * mysqli driver, which tolerates an out-of-range bound value with a
 * plain false comparison rather than erroring the way Postgres's
 * extended query protocol does); findAllGalleriesUrls()'s own per-row
 * `is_array($row) && (is_int($row['id']) || is_string($row['id'])) &&
 * is_string($row['galleriesUrl'] ?? null)` guard is dead code under any
 * real query result -- confirmed live (an unconditionally-true guard
 * produces identical output): `s.id`/`s.galleriesUrl` are plain
 * (non-VO-typed) columns, always a real int/string from
 * getArrayResult(); findGalleriesUrlForCategory()'s own `setMaxResults(1)`
 * is unobservable at any other value -- `c.id = :categoryId` matches
 * categories' own PRIMARY KEY and `s.id = c.siteId` joins 1:1 onto
 * sites' own PRIMARY KEY, so the query can never produce more than one
 * row regardless of the limit (same reasoning as
 * PermalinkRepositoryTest.php's own findOldCategoryId() finding);
 * findAllSites()'s own `$entity->id ?? 0` coalesce is unreachable --
 * every SiteEntity this method can ever see comes from findAll(), a
 * real already-persisted row, so `$entity->id` is never null in
 * practice; findCategoryAndImageCountsBySite()'s own
 * `is_numeric(...)`-gated `(int)` casts are unreachable for the same
 * "raw DBAL/mysqli already returns native ints for a NOT NULL int
 * column" reason documented throughout this project's other Unit-suite
 * files.
 */
function siteTestRepo(): SiteRepository
{
    $repo = TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(SiteEntity::class), SiteRepository::class);

    return $repo;
}

function siteTestUrl(): string
{
    return 'p17-unit-test-' . bin2hex(random_bytes(4));
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
});

afterEach(function (): void {
    DbConnection::build()->executeStatement("DELETE FROM sites WHERE galleries_url LIKE 'p17-unit-test-%'");
    Kernel::reset();
});

test('countByUrl() returns zero when unused', function (): void {
    expect(siteTestRepo()->countByUrl(siteTestUrl()))
        ->toBe(0);
});

test('insert() then countByUrl() round-trips', function (): void {
    $repo = siteTestRepo();
    $url = siteTestUrl();

    $repo->insert($url);

    expect($repo->countByUrl($url))
        ->toBe(1);
});

test('findGalleriesUrlById() returns the inserted url', function (): void {
    $repo = siteTestRepo();
    $url = siteTestUrl();
    $repo->insert($url);
    $id = DbConnection::build()->fetchOne(
        'SELECT id FROM sites WHERE galleries_url = ?',
        [$url]
    );

    // @phpstan-ignore cast.useless
    expect($repo->findGalleriesUrlById(is_numeric($id) ? (int) $id : 0))
        ->toBe($url);
});

test('findGalleriesUrlById() returns null when unused', function (): void {
    // sites.id is tinyint unsigned (MySQL)/smallint (Postgres) -- 254
    // fits both real ranges and the fixture only ever seeds site id 1.
    expect(siteTestRepo()->findGalleriesUrlById(254))
        ->toBeNull();
});

test('delete() removes the row', function (): void {
    $repo = siteTestRepo();
    $url = siteTestUrl();
    $repo->insert($url);
    $id = DbConnection::build()->fetchOne(
        'SELECT id FROM sites WHERE galleries_url = ?',
        [$url]
    );
    // @phpstan-ignore cast.useless
    $intId = is_numeric($id) ? (int) $id : 0;

    $repo->delete($intId);

    expect($repo->findGalleriesUrlById($intId))
        ->toBeNull();
});

test('delete() on an unknown id is a silent no-op', function (): void {
    TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(SiteEntity::class), SiteRepository::class)->delete(254);
})->throwsNoExceptions();

test('findAllGalleriesUrls() returns the id-to-url map', function (): void {
    expect(siteTestRepo()->findAllGalleriesUrls())
        ->toBe([
            1 => CurrentPathsTestFactory::get()->root . 'galleries/',
        ]);
});

test('findGalleriesUrlForCategory() returns null when the category has no linked site', function (): void {
    // Both fixture categories have site_id NULL -- the join predicate is
    // never satisfied against a NULL.
    expect(siteTestRepo()->findGalleriesUrlForCategory(1))
        ->toBeNull();
});

test('findGalleriesUrlForCategory() returns the joined sites row', function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement('UPDATE categories SET site_id = 1 WHERE id = 1');

    try {
        expect(siteTestRepo()->findGalleriesUrlForCategory(1))
            ->toBe(CurrentPathsTestFactory::get()->root . 'galleries/');
    } finally {
        $conn->executeStatement('UPDATE categories SET site_id = NULL WHERE id = 1');
    }
});

test('findAllSites() includes the seeded local site', function (): void {
    $rows = siteTestRepo()
        ->findAllSites();

    expect($rows)
        ->not->toBe([]);
    $urls = array_column($rows, 'galleriesUrl');
    expect($urls)
        ->toContain(CurrentPathsTestFactory::get()->root . 'galleries/');
});

test('findAllSites() includes a newly inserted site', function (): void {
    $repo = siteTestRepo();
    $url = siteTestUrl();
    $repo->insert($url);

    $urls = array_column($repo->findAllSites(), 'galleriesUrl');

    expect($urls)
        ->toContain($url);
});

test('findCategoryAndImageCountsBySite() groups by site and ignores categories with no site', function (): void {
    // Every real fixture category has site_id NULL (a single-site
    // install) -- 2 disposable categories are the only way to reach this
    // method's own real work at all. One gets a storage-synced image
    // (storage_category_id) so nb_images is genuinely non-zero.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction: both
    // `categories` and `images` carry a FULLTEXT index, whose
    // auxiliary-index maintenance on INSERT can deadlock against another
    // --parallel worker's own concurrent INSERT on the same table when
    // held open for a whole test's duration -- same mechanism, same fix,
    // as TagServiceTest.php's 'getTagIds() creates a new tag for a plain
    // name when allowed' (reproduced live there: DeadlockException). The
    // categories INSERTs below also give `rank` an explicit, high value
    // rather than leaving it to the schema's own NULL default -- see
    // SearchServiceTest.php's own 'zqualifiesonlycat' category for why.
    DbTransactionTestOverride::rollback();
    $repo = siteTestRepo();
    $url = siteTestUrl();
    $repo->insert($url);
    $conn = DbConnection::build();
    $siteId = $conn->fetchOne('SELECT id FROM sites WHERE galleries_url = ?', [$url]);
    // @phpstan-ignore cast.useless
    $siteId = is_numeric($siteId) ? (int) $siteId : 0;
    $rankColumn = $conn->getDatabasePlatform()
        ->quoteSingleIdentifier('rank');

    $conn->executeStatement(
        "INSERT INTO categories (name, site_id, uppercats, {$rankColumn}) VALUES ('p17-unit-test-site-cat-with-image', ?, '999901', 999)",
        [$siteId]
    );
    $catWithImageId = (int) $conn->lastInsertId();
    $conn->executeStatement(
        "INSERT INTO categories (name, site_id, uppercats, {$rankColumn}) VALUES ('p17-unit-test-site-cat-without-image', ?, '999902', 999)",
        [$siteId]
    );
    $catWithoutImageId = (int) $conn->lastInsertId();
    $conn->executeStatement(
        "INSERT INTO images (file, path, storage_category_id) VALUES ('p17-unit-test-site.jpg', 'p17-unit-test-site.jpg', ?)",
        [$catWithImageId]
    );
    $imageId = (int) $conn->lastInsertId();

    try {
        $counts = $repo->findCategoryAndImageCountsBySite();

        expect($counts)
            ->toHaveKey($siteId)
            ->and($counts[$siteId])->toEqual(new SiteCategoryImageCounts(categories: 2, images: 1));
    } finally {
        $conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM categories WHERE id IN (?, ?)', [$catWithImageId, $catWithoutImageId]);
    }
});
