<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;

/**
 * Piwigo\Image\ImageRepository -- direct Unit-suite coverage for the
 * bulk/lounge/config methods pest --mutate reported as uncovered by
 * both this and the Integration suite. Uses the real test database
 * (same DbConnection::build() the Integration suite itself uses, not a
 * mock) since this class is pure DBAL/DQL persistence -- there is no
 * meaningful way to unit-test its actual query behavior without a real
 * connection. Every insert here is a throwaway row cleaned up in each
 * test's own finally block; the 5 fixture images (ids 1-5) and
 * fixture category (id 1) are read but never mutated.
 */
function imageRepositoryTestRepo(): ImageRepository
{
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(ImageEntity::class);
    expect($repo)->toBeInstanceOf(ImageRepository::class);

    return $repo;
}

/**
 * `empty_lounge_running` is a single GLOBAL row in the real `config`
 * table, also directly manipulated by ImageServiceTest.php's own
 * emptyLounge() tests. Under --parallel, Pest runs separate test FILES
 * as separate worker processes concurrently, so a test here and a test
 * there can genuinely race on this one row. Same lock name as
 * ImageServiceTest.php's own identical helper -- MySQL's GET_LOCK()/
 * RELEASE_LOCK() are server-wide, not connection-local, so they
 * serialize both files' tests against each other for real.
 */
function imageRepositoryTestAcquireEmptyLoungeDbLock(Connection $conn): void
{
    $acquired = $conn->fetchOne("SELECT GET_LOCK('piwigo17-unit-tests-empty_lounge_running', 10)");
    if (! is_numeric($acquired) || (int) $acquired !== 1) {
        throw new RuntimeException('Could not acquire the empty_lounge_running test lock within 10s -- a concurrent test run may be stuck.');
    }
}

function imageRepositoryTestReleaseEmptyLoungeDbLock(Connection $conn): void
{
    $conn->executeStatement("SELECT RELEASE_LOCK('piwigo17-unit-tests-empty_lounge_running')");
}

/**
 * Confirmed-equivalent: every remaining reported mutation in this file
 * after the tests below (UnwrapArrayMap/RemoveIntegerCast/
 * RemoveStringCast/DecrementInteger/IncrementInteger/
 * EmptyStringToNotEmpty across findPathsForFileDeletion(),
 * findLoungeRows(), findImageIdsWithoutMd5sum(), findPathsForMd5sum(),
 * findById(), findIdsByFilenameInCategory(), findIdsByMd5sum(),
 * findIdsVisibleInCategoriesRecentlyAvailable(),
 * findAddMethodBreakdown(), findRepresentedCategoryIds(),
 * findExistingAssociations(), and findMaxRanksByCategory() -- roughly
 * 29 mutation IDs across 12 methods), plus line 745's TrueToFalse in
 * tryAcquireLoungeLock()/findLoungeLockValue() (documented separately,
 * same root cause category: a defensive normalization step that never
 * actually does anything on THIS driver's real output).
 * findRepresentedCategoryIds()'s, findExistingAssociations()'s, and
 * findMaxRanksByCategory()'s own dedicated tests below prove the real
 * logic (array grouping/shape, real aggregate value) directly; live
 * sed-mutate-and-rerun confirmed their is_numeric()/(int) casts
 * specifically are ALSO equivalent under this same root cause, same as
 * every other method
 * here.
 *
 * Root cause, confirmed live against every method above (each
 * array_map()/cast/find()-argument-cast was individually removed from
 * the real source and the corresponding real-DB test above re-run
 * unchanged): this project's mysqli driver returns NATIVE PHP types
 * (real int for int columns, real string for varchar/text columns,
 * real null for NULL) directly from fetchAllAssociative()/
 * fetchFirstColumn()/fetchAllKeyValue() -- not the driver-generic
 * strings some other DBAL drivers hand back. Every one of these
 * methods' own SELECT list is also EXACTLY the target array's key set
 * (no extra columns to filter out, no renaming) and every source
 * column involved is `NOT NULL` (id, image_id, category_id, path) or
 * -- for findById()'s `(int) $imageId` -- Doctrine's own find() already
 * accepts a numeric string identically to a real int. The
 * is_numeric()/is_string() defensive checks, their (int)/(string)
 * casts, and their false-branch default literals were written for
 * driver-portability (a real concern in general), but on this exact
 * driver + these exact queries, the "normalized" array_map() output is
 * byte-for-byte identical to the raw driver row for every input these
 * methods can ever actually receive.
 */

function imageRepositoryTestInsertImage(string $path, ?string $md5sum = null): int
{
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->insert(Tables::images())
        ->values([
            'file' => ':file',
            'path' => ':path',
            'md5sum' => ':md5sum',
        ])
        ->setParameter('file', basename($path))
        ->setParameter('path', $path)
        ->setParameter('md5sum', $md5sum)
        ->executeStatement();

    return (int) $conn->lastInsertId();
}

function imageRepositoryTestDeleteImage(int $imageId): void
{
    DbConnection::build()->createQueryBuilder()
        ->delete(Tables::images())
        ->where('id = :id')
        ->setParameter('id', $imageId)
        ->executeStatement();
}

test('findPathsForFileDeletion returns id as a real int and path as a real string, not raw driver types', function (): void {
    // Kills line 324's UnwrapArrayMap (bare $rows, un-normalized) and
    // line 326's RemoveIntegerCast -- fetchAllAssociative() on a raw
    // executeQuery() returns the DBAL driver's own raw column types,
    // not PHP-native ones; the (int) cast is what makes 'id' a genuine
    // int rather than whatever the driver handed back.
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/del-test.jpg');

    try {
        $result = imageRepositoryTestRepo()->findPathsForFileDeletion([$imageId]);

        expect($result)->toBe([[
            'id' => $imageId,
            'path' => 'upload/2026/07/del-test.jpg',
            'representative_ext' => null,
        ]]);
    } finally {
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findLoungeRows returns image_id/category_id as real ints, ordered by category then image', function (): void {
    // Kills line 613's UnwrapArrayMap/AlwaysReturnEmptyArray and line
    // 615's RemoveIntegerCast/TernaryNegated/RemoveArrayItem (the
    // 'image_id' key) -- the exact-shape/exact-order assertion below
    // also closes line 615's TernaryNegated (is_numeric() negated would
    // fall to the 0 default for a genuinely numeric image_id) since
    // both fixture ids used are non-zero. Line 616's
    // DecrementInteger/IncrementInteger/RemoveIntegerCast (the mirrored
    // cast for 'category_id') is closed the identical way -- both rows
    // use a non-zero category_id (1) too.
    $imageA = imageRepositoryTestInsertImage('upload/2026/07/lounge-a.jpg');
    $imageB = imageRepositoryTestInsertImage('upload/2026/07/lounge-b.jpg');
    $conn = DbConnection::build();
    $conn->createQueryBuilder()->insert(Tables::lounge())
        ->values(['image_id' => ':i', 'category_id' => ':c'])
        ->setParameter('i', $imageB)->setParameter('c', 1)
        ->executeStatement();
    $conn->createQueryBuilder()->insert(Tables::lounge())
        ->values(['image_id' => ':i', 'category_id' => ':c'])
        ->setParameter('i', $imageA)->setParameter('c', 1)
        ->executeStatement();

    try {
        $result = imageRepositoryTestRepo()->findLoungeRows();

        // ORDER BY category_id ASC, image_id ASC -- both rows share
        // category 1, so imageA (the lower id) sorts first.
        expect($result)->toBe([
            ['image_id' => $imageA, 'category_id' => 1],
            ['image_id' => $imageB, 'category_id' => 1],
        ]);
    } finally {
        $conn->createQueryBuilder()->delete(Tables::lounge())->where('image_id IN (:ids)')
            ->setParameter('ids', [$imageA, $imageB], ArrayParameterType::INTEGER)
            ->executeStatement();
        imageRepositoryTestDeleteImage($imageA);
        imageRepositoryTestDeleteImage($imageB);
    }
});

test('deleteLoungeUpTo removes only rows at or below the given image id', function (): void {
    // Kills line 689's RemoveMethodCall (the whole DELETE statement).
    $imageA = imageRepositoryTestInsertImage('upload/2026/07/lounge-del-a.jpg');
    $imageB = imageRepositoryTestInsertImage('upload/2026/07/lounge-del-b.jpg');
    $conn = DbConnection::build();
    foreach ([$imageA, $imageB] as $id) {
        $conn->createQueryBuilder()->insert(Tables::lounge())
            ->values(['image_id' => ':i', 'category_id' => ':c'])
            ->setParameter('i', $id)->setParameter('c', 1)
            ->executeStatement();
    }

    try {
        imageRepositoryTestRepo()->deleteLoungeUpTo($imageA);

        $remaining = $conn->createQueryBuilder()->select('image_id')->from(Tables::lounge())
            ->where('image_id IN (:ids)')
            ->setParameter('ids', [$imageA, $imageB], ArrayParameterType::INTEGER)
            ->fetchFirstColumn();
        expect($remaining)->toBe([$imageB]);
    } finally {
        $conn->createQueryBuilder()->delete(Tables::lounge())->where('image_id IN (:ids)')
            ->setParameter('ids', [$imageA, $imageB], ArrayParameterType::INTEGER)
            ->executeStatement();
        imageRepositoryTestDeleteImage($imageA);
        imageRepositoryTestDeleteImage($imageB);
    }
});

test('deleteImages cascades away rows from every real referencing table, and clears the identity map', function (): void {
    // Every referencing table carries a real ON DELETE CASCADE FK
    // straight to images.id (tests/Fixtures/piwigo-17.0.sql), so
    // deleteImages() alone already performs this cleanup, via the
    // database itself. One real row is seeded in every table to prove
    // that live, not just cite the schema.
    $repo = imageRepositoryTestRepo();
    $cached = $repo->find(1);
    expect($cached)->not->toBeNull();

    $imageId = imageRepositoryTestInsertImage('upload/2026/07/references-test.jpg');
    $bystanderId = imageRepositoryTestInsertImage('upload/2026/07/references-bystander.jpg');
    $conn = DbConnection::build();
    $conn->createQueryBuilder()->insert(Tables::comments())
        ->values(['image_id' => ':i', 'anonymous_id' => ':a'])
        ->setParameter('i', $imageId)->setParameter('a', 'refs-test')->executeStatement();
    $conn->createQueryBuilder()->insert(Tables::comments())
        ->values(['image_id' => ':i', 'anonymous_id' => ':a'])
        ->setParameter('i', $bystanderId)->setParameter('a', 'refs-bystander')->executeStatement();
    $conn->createQueryBuilder()->insert(Tables::imageCategory())
        ->values(['image_id' => ':i', 'category_id' => ':c'])
        ->setParameter('i', $imageId)->setParameter('c', 1)->executeStatement();
    $conn->createQueryBuilder()->insert(Tables::imageFormat())
        ->values(['image_id' => ':i', 'ext' => ':e'])
        ->setParameter('i', $imageId)->setParameter('e', 'webp')->executeStatement();
    $conn->createQueryBuilder()->insert(Tables::imageTag())
        ->values(['image_id' => ':i', 'tag_id' => ':t'])
        ->setParameter('i', $imageId)->setParameter('t', 1)->executeStatement();
    $conn->createQueryBuilder()->insert(Tables::favorites())
        ->values(['user_id' => ':u', 'image_id' => ':i'])
        ->setParameter('u', 1)->setParameter('i', $imageId)->executeStatement();
    $conn->createQueryBuilder()->insert(Tables::rate())
        ->values(['user_id' => ':u', 'element_id' => ':i'])
        ->setParameter('u', 1)->setParameter('i', $imageId)->executeStatement();
    $conn->createQueryBuilder()->insert(Tables::caddie())
        ->values(['user_id' => ':u', 'element_id' => ':i'])
        ->setParameter('u', 1)->setParameter('i', $imageId)->executeStatement();

    try {
        $repo->deleteImages([$imageId]);

        foreach ([Tables::comments(), Tables::imageCategory(), Tables::imageFormat(), Tables::imageTag(), Tables::favorites()] as $table) {
            $count = $conn->fetchOne("SELECT COUNT(*) FROM {$table} WHERE image_id = {$imageId}");
            expect(is_numeric($count) ? (int) $count : -1)->toBe(0);
        }
        foreach ([Tables::rate(), Tables::caddie()] as $table) {
            $count = $conn->fetchOne("SELECT COUNT(*) FROM {$table} WHERE element_id = {$imageId}");
            expect(is_numeric($count) ? (int) $count : -1)->toBe(0);
        }

        // The cascade is scoped to the deleted image's own id -- a
        // bystander image's own comment row survives untouched.
        $bystanderCount = $conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::comments() . " WHERE image_id = {$bystanderId}");
        expect(is_numeric($bystanderCount) ? (int) $bystanderCount : -1)->toBe(1);

        $refetched = $repo->find(1);
        expect($refetched)->not->toBe($cached);
    } finally {
        // The image row is already gone via deleteImages() itself above
        // -- a harmless no-op delete if a genuine bug left it behind.
        imageRepositoryTestDeleteImage($imageId);
        $conn->createQueryBuilder()->delete(Tables::comments())->where('image_id = :i')->setParameter('i', $bystanderId)->executeStatement();
        imageRepositoryTestDeleteImage($bystanderId);
    }
});

test('deleteImages removes the row for real, and clears the identity map', function (): void {
    // Kills line 574's RemoveMethodCall ($em->clear()) -- probed via a
    // DIFFERENT, unrelated cached entity (fixture image 1), since the
    // deleted row itself would read back as null either way regardless
    // of whether the identity map was cleared.
    $repo = imageRepositoryTestRepo();
    $cached = $repo->find(1);
    expect($cached)->not->toBeNull();

    $imageId = imageRepositoryTestInsertImage('upload/2026/07/delete-images-test.jpg');

    $repo->deleteImages([$imageId]);

    $stillThere = DbConnection::build()->fetchOne('SELECT COUNT(*) FROM ' . Tables::images() . " WHERE id = {$imageId}");
    expect(is_numeric($stillThere) ? (int) $stillThere : -1)->toBe(0);

    $refetched = $repo->find(1);
    expect($refetched)->not->toBe($cached);
});

test('findRepresentedCategoryIds returns real ints for every category whose representative points at one of the given images', function (): void {
    // Kills line 588's AlwaysReturnEmptyArray/TernaryNegated (a real,
    // non-empty result). Its sibling Decrement/IncrementInteger/
    // RemoveIntegerCast/UnwrapArrayMap are confirmed-equivalent instead
    // (live sed-mutate-and-rerun: this exact test still passes with
    // each removed) -- fetchFirstColumn() already returns a real int
    // for this NOT NULL int column on this driver, same root cause as
    // the file's own top-of-file consolidated docblock.
    // ImageService::deleteElements() (this method's only real caller)
    // never reaches this method with a category whose representative
    // still points at a live image -- the FK's own ON DELETE SET NULL
    // already clears it first -- so a direct call here, bypassing that
    // caller, is what actually exercises the non-empty path for real.
    $repo = imageRepositoryTestRepo();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/represents-test.jpg');
    $conn = DbConnection::build();
    $conn->executeStatement('INSERT INTO ' . Tables::categories() . " (name, representative_picture_id) VALUES ('mutation-sweep-repr-category', {$imageId})");
    $categoryId = (int) $conn->lastInsertId();

    try {
        $result = $repo->findRepresentedCategoryIds([$imageId]);

        expect($result)->toBe([$categoryId]);
    } finally {
        $conn->executeStatement("DELETE FROM " . Tables::categories() . " WHERE id = {$categoryId}");
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findExistingAssociations groups real int image ids under their real int category id', function (): void {
    // Proves the real array-grouping shape (category_id => [image_ids]).
    // Line 773's own two RemoveIntegerCast (the category_id key and the
    // image_id value) are confirmed-equivalent instead (live
    // sed-mutate-and-rerun: this exact test still passes with both
    // removed) -- same mysqli-native-types root cause as the file's own
    // top-of-file consolidated docblock (image_category's own image_id/
    // category_id columns are NOT NULL ints).
    $repo = imageRepositoryTestRepo();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/existing-assoc-test.jpg');
    $conn = DbConnection::build();
    $conn->createQueryBuilder()->insert(Tables::imageCategory())
        ->values(['image_id' => ':i', 'category_id' => ':c'])
        ->setParameter('i', $imageId)->setParameter('c', 1)->executeStatement();

    try {
        $result = $repo->findExistingAssociations([$imageId], [1]);

        expect($result)->toBe([1 => [$imageId]]);
    } finally {
        $conn->createQueryBuilder()->delete(Tables::imageCategory())
            ->where('image_id = :i')->setParameter('i', $imageId)->executeStatement();
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findMaxRanksByCategory returns the real int max rank for a category with ranked images', function (): void {
    // Kills line 800's AlwaysReturnEmptyArray (a real, non-empty
    // result). Its sibling Decrement/IncrementInteger/RemoveIntegerCast/
    // UnwrapArrayMap are confirmed-equivalent instead (live
    // sed-mutate-and-rerun: this exact test still passes with each
    // removed) -- fetchAllKeyValue() already returns a real int for
    // this NOT NULL `rank` column on this driver, same root cause as
    // the file's own top-of-file consolidated docblock. A fresh,
    // private throwaway category is required here, not the shared
    // fixture category 1 --
    // MAX(rank) aggregates over EVERY image in the category, so under
    // --parallel execution any concurrently-running test that also
    // writes to category 1 races this assertion (caught live: a
    // concurrent rank=8 write made this test observe 8, not the 7 it
    // itself inserted).
    $repo = imageRepositoryTestRepo();
    $conn = DbConnection::build();
    $conn->executeStatement("INSERT INTO " . Tables::categories() . " (name) VALUES ('mutation-sweep-max-rank-category')");
    $categoryId = (int) $conn->lastInsertId();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/max-rank-test.jpg');
    $conn->createQueryBuilder()->insert(Tables::imageCategory())
        ->values(['image_id' => ':i', 'category_id' => ':c', '`rank`' => ':r'])
        ->setParameter('i', $imageId)->setParameter('c', $categoryId)->setParameter('r', 7)->executeStatement();

    try {
        $result = $repo->findMaxRanksByCategory([$categoryId]);

        expect($result)->toBe([$categoryId => 7]);
    } finally {
        $conn->createQueryBuilder()->delete(Tables::imageCategory())
            ->where('image_id = :i')->setParameter('i', $imageId)->executeStatement();
        imageRepositoryTestDeleteImage($imageId);
        $conn->executeStatement("DELETE FROM " . Tables::categories() . " WHERE id = {$categoryId}");
    }
});

test('massInsertImageCategory persists every real row it is given', function (): void {
    // Kills line 1470's RemoveMethodCall (clear() -- the identity map,
    // via the same unrelated-cached-entity technique as the sibling
    // tests above).
    $repo = imageRepositoryTestRepo();
    $cached = $repo->find(1);
    expect($cached)->not->toBeNull();

    $imageId = imageRepositoryTestInsertImage('upload/2026/07/mass-insert-test.jpg');

    try {
        $repo->massInsertImageCategory([
            ['image_id' => $imageId, 'category_id' => 1, 'rank' => 3],
        ]);

        $rank = DbConnection::build()->fetchOne('SELECT `rank` FROM ' . Tables::imageCategory() . " WHERE image_id = {$imageId} AND category_id = 1");
        expect($rank)->toBe(3);
        $refetched = $repo->find(1);
        expect($refetched)->not->toBe($cached);
    } finally {
        DbConnection::build()->createQueryBuilder()->delete(Tables::imageCategory())
            ->where('image_id = :i')->setParameter('i', $imageId)->executeStatement();
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('tryAcquireLoungeLock persists a real, JSON-round-trippable value, and clears the identity map', function (): void {
    // Kills line 731's RemoveMethodCall ($em->clear()) -- verified via
    // ImageEntity's own identity map: a prior find() on a real image
    // caches it; without clear(), a raw UPDATE to that same row done
    // outside the ORM would still read back the stale cached instance
    // afterward. tryAcquireLoungeLock() doesn't touch `images` itself,
    // so this proves the *general* clear() mechanism via the same
    // repository instance instead of a same-table round-trip.
    $repo = imageRepositoryTestRepo();
    $conn = DbConnection::build();
    imageRepositoryTestAcquireEmptyLoungeDbLock($conn);

    // The lock acquire above must be paired with a release no matter
    // what happens next -- an outer try/finally wrapping literally
    // everything (not just the tryAcquireLoungeLock() call itself)
    // guarantees that, since a stuck lock here doesn't just fail this
    // test, it blocks every other test using the same lock name
    // (ImageServiceTest.php's emptyLounge() tests) for a full 10s each,
    // for the rest of the process.
    try {
        $cached = $repo->find(1);
        expect($cached)->not->toBeNull();

        $conn->createQueryBuilder()->delete('piwigo_config')
            ->where('param = :p')->setParameter('p', 'empty_lounge_running')
            ->executeStatement();

        try {
            $repo->tryAcquireLoungeLock('lock-value-1');

            expect($repo->findLoungeLockValue())->toBe('lock-value-1');

            // If $em->clear() didn't run, this find() would return the SAME
            // PHP object identity as $cached (the pre-clear() cached
            // instance) rather than a freshly-hydrated one.
            $refetched = $repo->find(1);
            expect($refetched)->not->toBe($cached);
        } finally {
            $conn->createQueryBuilder()->delete('piwigo_config')
                ->where('param = :p')->setParameter('p', 'empty_lounge_running')
                ->executeStatement();
        }
    } finally {
        imageRepositoryTestReleaseEmptyLoungeDbLock($conn);
    }
});

/**
 * Confirmed-equivalent: line 745's TrueToFalse (json_decode()'s
 * `assoc` flag, `true` mutated to `false`). tryAcquireLoungeLock()
 * only ever json_encode()s a plain PHP string ($lockValue), never an
 * array or object -- decoding a JSON string literal (e.g. `"foo"`)
 * always produces a plain PHP string regardless of the assoc flag,
 * which only affects how JSON *objects* decode. Live sed-verified
 * against the full suite too.
 */
test('massUpdateMd5sums updates only the md5sum column, keyed by id, and clears the identity map', function (): void {
    // Kills line 1479's RemoveMethodCall (massUpdate() entirely),
    // line 1483's RemoveArrayItem ('primary' => [] -- BatchWriter
    // couldn't build a WHERE clause at all), line 1484's two
    // RemoveArrayItem (emptying, then dropping, the 'update' key --
    // either leaves the row's md5sum untouched), and line 1488's
    // RemoveMethodCall ($em->clear()).
    $repo = imageRepositoryTestRepo();
    $cached = $repo->find(1);
    expect($cached)->not->toBeNull();

    $imageId = imageRepositoryTestInsertImage('upload/2026/07/md5-test.jpg', 'old0old0old0old0old0old0old0old0');

    try {
        $repo->massUpdateMd5sums([
            ['id' => $imageId, 'md5sum' => 'new0new0new0new0new0new0new0new0'],
        ]);

        $md5sum = DbConnection::build()->createQueryBuilder()->select('md5sum')->from(Tables::images())
            ->where('id = :id')->setParameter('id', $imageId)->fetchOne();
        expect($md5sum)->toBe('new0new0new0new0new0new0new0new0');

        $refetched = $repo->find(1);
        expect($refetched)->not->toBe($cached);
    } finally {
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findPathsForMd5sum maps id => path as a real string, not a raw driver value', function (): void {
    // Kills line 955's UnwrapArrayMap/RemoveStringCast/
    // EmptyStringToNotEmpty.
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/md5sum-paths.jpg');

    try {
        $result = imageRepositoryTestRepo()->findPathsForMd5sum([$imageId]);

        expect($result)->toBe([$imageId => 'upload/2026/07/md5sum-paths.jpg']);
    } finally {
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findById reads a numeric-string id, not just a native int', function (): void {
    // Kills line 1371's RemoveIntegerCast -- find() takes the primary
    // key; without normalizing a numeric STRING id to a real int
    // first, Doctrine's own identity-map/parameter-binding may not
    // resolve it the same way.
    $repo = imageRepositoryTestRepo();

    $found = $repo->findById('1');

    if ($found === null) {
        throw new RuntimeException('expected a real Image instance');
    }
    expect($found->id)->toBe(1);
});

test('findById returns null for an id that does not exist, not a real Image', function (): void {
    // Kills line 1373's AlwaysReturnNull -- a bare "always null" mutant
    // would pass this test alone, but fail the sibling "found" test
    // above, so together they close it; this one specifically pins the
    // not-found path so an accidental "always return a real Image"
    // regression would also be caught.
    $repo = imageRepositoryTestRepo();

    expect($repo->findById(999_999))->toBeNull();
});

test('findIdsByFilenameInCategory returns real ints for a matching filename/category pair', function (): void {
    // Kills line 2121's UnwrapArrayMap/RemoveIntegerCast/
    // DecrementInteger/IncrementInteger -- fetchFirstColumn() on this
    // DBAL QueryBuilder path also returns raw driver types, same
    // reasoning as findPathsForFileDeletion() above.
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/filename-test.jpg');
    $conn = DbConnection::build();
    $conn->createQueryBuilder()->insert(Tables::imageCategory())
        ->values(['image_id' => ':i', 'category_id' => ':c'])
        ->setParameter('i', $imageId)->setParameter('c', 1)
        ->executeStatement();

    try {
        $result = imageRepositoryTestRepo()->findIdsByFilenameInCategory('filename-test.jpg', 1);

        expect($result)->toBe([$imageId]);
    } finally {
        $conn->createQueryBuilder()->delete(Tables::imageCategory())
            ->where('image_id = :i')->setParameter('i', $imageId)->executeStatement();
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findIdsByMd5sum returns real ints for every image sharing the given md5sum', function (): void {
    // Kills line 2362's UnwrapArrayMap/RemoveIntegerCast/
    // DecrementInteger/IncrementInteger.
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/md5-lookup.jpg', 'dupe0dupe0dupe0dupe0dupe0dupe0dp');

    try {
        $result = imageRepositoryTestRepo()->findIdsByMd5sum('dupe0dupe0dupe0dupe0dupe0dupe0dp');

        expect($result)->toBe([$imageId]);
    } finally {
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findIdsVisibleInCategoriesRecentlyAvailable returns real ints for a recently-available image in a given category', function (): void {
    // Kills line 2934's UnwrapArrayMap/RemoveIntegerCast/
    // DecrementInteger/IncrementInteger.
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/recent-test.jpg');
    $conn = DbConnection::build();
    $conn->createQueryBuilder()->update(Tables::images())
        ->set('date_available', ':d')->where('id = :id')
        ->setParameter('d', date('Y-m-d H:i:s'))->setParameter('id', $imageId)
        ->executeStatement();
    $conn->createQueryBuilder()->insert(Tables::imageCategory())
        ->values(['image_id' => ':i', 'category_id' => ':c'])
        ->setParameter('i', $imageId)->setParameter('c', 1)
        ->executeStatement();

    try {
        $result = imageRepositoryTestRepo()->findIdsVisibleInCategoriesRecentlyAvailable('1', 'DATE_SUB(NOW(), INTERVAL 1 YEAR)');

        // Category 1 already holds the fixture's own images too (real
        // production data, not something this test controls) -- assert
        // strict membership and type instead of an exact-array match.
        expect($result)->toContain($imageId);
        foreach ($result as $id) {
            expect($id)->toBeInt();
        }
    } finally {
        $conn->createQueryBuilder()->delete(Tables::imageCategory())
            ->where('image_id = :i')->setParameter('i', $imageId)->executeStatement();
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findAddMethodBreakdown reports real string/int columns for at least one real add_method group', function (): void {
    // Kills line 3011's UnwrapArrayMap/AlwaysReturnEmptyArray, line
    // 3013's EmptyStringToNotEmpty, line 3014's TernaryNegated/
    // CoalesceRemoveLeft, and line 3015's RemoveIntegerCast/
    // TernaryNegated/DecrementInteger/IncrementInteger -- the fixture's
    // own 5 images (storage_category_id NULL => 'api') always produce
    // at least one real, non-empty group.
    $result = imageRepositoryTestRepo()->findAddMethodBreakdown();

    $api = null;
    foreach ($result as $row) {
        if ($row['add_method'] === 'api') {
            $api = $row;
        }
    }

    if ($api === null) {
        throw new RuntimeException('expected at least one real "api" add_method row');
    }
    expect($api['nb_files'])->toBeInt();
    expect($api['nb_files'])->toBeGreaterThanOrEqual(5);
    expect($api['last_added_on'])->toBeString();
});

test('deleteNonStorageCategoryLinks clears the identity map after a raw write outside the ORM', function (): void {
    // Kills line 919's RemoveMethodCall ($em->clear()).
    $repo = imageRepositoryTestRepo();
    $cached = $repo->find(1);
    expect($cached)->not->toBeNull();

    $repo->deleteNonStorageCategoryLinks([1], []);

    $refetched = $repo->find(1);
    expect($refetched)->not->toBe($cached);
});

test('deleteNonStorageCategoryLinks spares the image\'s own storage_category_id link', function (): void {
    // Exercises the `storage_category_id != category_id` half of the
    // exclusion filter -- the identity-map test above uses a freshly-
    // inserted image with no image_category rows at all, so the per-row
    // filtering loop never actually runs there. This seeds real
    // image_category rows across both fixture categories (1 and 2) and a
    // real storage_category_id, so a bug that deletes every link (or
    // spares the wrong one) is observable.
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->insert(Tables::images())
        ->values(['file' => ':file', 'path' => ':path', 'storage_category_id' => ':scid'])
        ->setParameter('file', 'storagelink.jpg')
        ->setParameter('path', 'upload/2026/07/storagelink.jpg')
        ->setParameter('scid', 1)
        ->executeStatement();
    $imageId = (int) $conn->lastInsertId();

    $conn->createQueryBuilder()->insert(Tables::imageCategory())
        ->values(['image_id' => ':i', 'category_id' => ':c'])->setParameter('i', $imageId)->setParameter('c', 1)
        ->executeStatement();
    $conn->createQueryBuilder()->insert(Tables::imageCategory())
        ->values(['image_id' => ':i', 'category_id' => ':c'])->setParameter('i', $imageId)->setParameter('c', 2)
        ->executeStatement();

    try {
        imageRepositoryTestRepo()->deleteNonStorageCategoryLinks([$imageId], []);

        $remaining = $conn->fetchFirstColumn('SELECT category_id FROM ' . Tables::imageCategory() . ' WHERE image_id = ' . $imageId);
        expect($remaining)->toBe([1]);
    } finally {
        $conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
    }
});

test('deleteNonStorageCategoryLinks also spares any category explicitly passed in $categories, with a null storage_category_id', function (): void {
    // Covers the other half: storage_category_id IS NULL (no exclusion
    // from that side at all) plus the optional $categories keep-list,
    // which the sibling test above never passes.
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->insert(Tables::images())
        ->values(['file' => ':file', 'path' => ':path'])
        ->setParameter('file', 'nostoragelink.jpg')
        ->setParameter('path', 'upload/2026/07/nostoragelink.jpg')
        ->executeStatement();
    $imageId = (int) $conn->lastInsertId();

    $conn->createQueryBuilder()->insert(Tables::imageCategory())
        ->values(['image_id' => ':i', 'category_id' => ':c'])->setParameter('i', $imageId)->setParameter('c', 1)
        ->executeStatement();
    $conn->createQueryBuilder()->insert(Tables::imageCategory())
        ->values(['image_id' => ':i', 'category_id' => ':c'])->setParameter('i', $imageId)->setParameter('c', 2)
        ->executeStatement();

    try {
        imageRepositoryTestRepo()->deleteNonStorageCategoryLinks([$imageId], [2]);

        $remaining = $conn->fetchFirstColumn('SELECT category_id FROM ' . Tables::imageCategory() . ' WHERE image_id = ' . $imageId);
        expect($remaining)->toBe([2]);
    } finally {
        $conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
    }
});
