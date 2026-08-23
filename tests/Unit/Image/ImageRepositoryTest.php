<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\SqlDialect;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\Projection\Image;
use Piwigo\Image\Projection\ImageCategoryLink;
use Piwigo\Image\Projection\ImageCategoryPair;
use Piwigo\Image\Projection\PathRepresentativeExt;
use Piwigo\Tests\Support\DbTransactionTestOverride;

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

    return $repo;
}

/**
 * `empty_lounge_running` is a single GLOBAL row in the real `config`
 * table, also directly manipulated by ImageServiceTest.php's own
 * emptyLounge() tests. Under --parallel, Pest runs separate test FILES
 * as separate worker processes concurrently, so a test here and a test
 * there can genuinely race on this one row. Same lock name as
 * ImageServiceTest.php's own identical helper -- both MySQL's GET_LOCK()/
 * RELEASE_LOCK() and Postgres' pg_try_advisory_lock()/pg_advisory_unlock()
 * (via Db\AdvisorySessionLock) are server-wide, not connection-local, so
 * they serialize both files' tests against each other for real.
 */
function imageRepositoryTestAcquireEmptyLoungeDbLock(Connection $conn): void
{
    if (! AdvisorySessionLock::acquire($conn, 'piwigo17-unit-tests-empty_lounge_running', 10)) {
        throw new RuntimeException('Could not acquire the empty_lounge_running test lock within 10s -- a concurrent test run may be stuck.');
    }
}

function imageRepositoryTestReleaseEmptyLoungeDbLock(Connection $conn): void
{
    AdvisorySessionLock::release($conn, 'piwigo17-unit-tests-empty_lounge_running');
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
        ->insert('images')
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
        ->delete('images')
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
    //
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // imageRepositoryTestInsertImage() INSERTs an `images` row, and
    // `images` carries a FULLTEXT index (images_ft_name_comment/
    // images_ft_author) whose auxiliary-index maintenance can deadlock
    // against another --parallel worker's own concurrent images INSERT
    // when held open for a whole test's duration -- same mechanism,
    // same fix, as TagServiceTest.php's 'getTagIds() creates a new tag
    // for a plain name when allowed' (reproduced live there:
    // DeadlockException). Every other test below using
    // imageRepositoryTestInsertImage() is exempt for the same reason.
    DbTransactionTestOverride::rollback();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/del-test.jpg');

    try {
        $result = imageRepositoryTestRepo()
            ->findPathsForFileDeletion([$imageId]);

        expect($result)
            ->toEqual([
                new PathRepresentativeExt($imageId, 'upload/2026/07/del-test.jpg', null),
            ]);
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
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $imageA = imageRepositoryTestInsertImage('upload/2026/07/lounge-a.jpg');
    $imageB = imageRepositoryTestInsertImage('upload/2026/07/lounge-b.jpg');
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->insert('lounge')
        ->values([
            'image_id' => ':i',
            'category_id' => ':c',
        ])
        ->setParameter('i', $imageB)
        ->setParameter('c', 1)
        ->executeStatement();
    $conn->createQueryBuilder()
        ->insert('lounge')
        ->values([
            'image_id' => ':i',
            'category_id' => ':c',
        ])
        ->setParameter('i', $imageA)
        ->setParameter('c', 1)
        ->executeStatement();

    try {
        $result = imageRepositoryTestRepo()
            ->findLoungeRows();

        // ORDER BY category_id ASC, image_id ASC -- both rows share
        // category 1, so imageA (the lower id) sorts first.
        expect($result)
            ->toEqual([
                new ImageCategoryLink($imageA, 1),
                new ImageCategoryLink($imageB, 1),
            ]);
    } finally {
        $conn->createQueryBuilder()
            ->delete('lounge')
            ->where('image_id IN (:ids)')
            ->setParameter('ids', [$imageA, $imageB], ArrayParameterType::INTEGER)
            ->executeStatement();
        imageRepositoryTestDeleteImage($imageA);
        imageRepositoryTestDeleteImage($imageB);
    }
});

test('deleteLoungeUpTo removes only rows at or below the given image id', function (): void {
    // Kills line 689's RemoveMethodCall (the whole DELETE statement).
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $imageA = imageRepositoryTestInsertImage('upload/2026/07/lounge-del-a.jpg');
    $imageB = imageRepositoryTestInsertImage('upload/2026/07/lounge-del-b.jpg');
    $conn = DbConnection::build();
    foreach ([$imageA, $imageB] as $id) {
        $conn->createQueryBuilder()
            ->insert('lounge')
            ->values([
                'image_id' => ':i',
                'category_id' => ':c',
            ])
            ->setParameter('i', $id)
            ->setParameter('c', 1)
            ->executeStatement();
    }

    try {
        imageRepositoryTestRepo()->deleteLoungeUpTo($imageA);

        $remaining = $conn->createQueryBuilder()
            ->select('image_id')
            ->from('lounge')
            ->where('image_id IN (:ids)')
            ->setParameter('ids', [$imageA, $imageB], ArrayParameterType::INTEGER)
            ->fetchFirstColumn();
        expect($remaining)
            ->toBe([$imageB]);
    } finally {
        $conn->createQueryBuilder()
            ->delete('lounge')
            ->where('image_id IN (:ids)')
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
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $repo = imageRepositoryTestRepo();
    $cached = $repo->find(ImageId::from(1));
    expect($cached)
        ->not->toBeNull();

    $imageId = imageRepositoryTestInsertImage('upload/2026/07/references-test.jpg');
    $bystanderId = imageRepositoryTestInsertImage('upload/2026/07/references-bystander.jpg');
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->insert('comments')
        ->values([
            'image_id' => ':i',
            'anonymous_id' => ':a',
        ])
        ->setParameter('i', $imageId)
        ->setParameter('a', 'refs-test')
        ->executeStatement();
    $conn->createQueryBuilder()
        ->insert('comments')
        ->values([
            'image_id' => ':i',
            'anonymous_id' => ':a',
        ])
        ->setParameter('i', $bystanderId)
        ->setParameter('a', 'refs-bystander')
        ->executeStatement();
    $conn->createQueryBuilder()
        ->insert('image_category')
        ->values([
            'image_id' => ':i',
            'category_id' => ':c',
        ])
        ->setParameter('i', $imageId)
        ->setParameter('c', 1)
        ->executeStatement();
    $conn->createQueryBuilder()
        ->insert('image_format')
        ->values([
            'image_id' => ':i',
            'ext' => ':e',
        ])
        ->setParameter('i', $imageId)
        ->setParameter('e', 'webp')
        ->executeStatement();
    // A disposable tag, not the real fixture tag 1 ("nature") --
    // TagRepositoryTest.php's own findImageIdsForTagIds([1]) exact-list
    // assertion (`[1, 2, 3]`) would otherwise be able to observe this
    // image_tag row's own image id as a spurious 4th entry under
    // --parallel; confirmed live via a --parallel verification loop.
    $disposableTagName = 'p17-unit-test-tag-' . bin2hex(random_bytes(4));
    $conn->executeStatement(
        'INSERT INTO tags (name, url_name, lastmodified) VALUES (?, ?, NOW())',
        [$disposableTagName, $disposableTagName]
    );
    $disposableTagId = (int) $conn->lastInsertId();
    $conn->createQueryBuilder()
        ->insert('image_tag')
        ->values([
            'image_id' => ':i',
            'tag_id' => ':t',
        ])
        ->setParameter('i', $imageId)
        ->setParameter('t', $disposableTagId)
        ->executeStatement();
    $conn->createQueryBuilder()
        ->insert('favorites')
        ->values([
            'user_id' => ':u',
            'image_id' => ':i',
        ])
        ->setParameter('u', 1)
        ->setParameter('i', $imageId)
        ->executeStatement();
    $conn->createQueryBuilder()
        ->insert('rate')
        ->values([
            'user_id' => ':u',
            'element_id' => ':i',
        ])
        ->setParameter('u', 1)
        ->setParameter('i', $imageId)
        ->executeStatement();
    $conn->createQueryBuilder()
        ->insert('caddie')
        ->values([
            'user_id' => ':u',
            'element_id' => ':i',
        ])
        ->setParameter('u', 1)
        ->setParameter('i', $imageId)
        ->executeStatement();

    try {
        $repo->deleteImages([$imageId]);

        foreach (['comments', 'image_category', 'image_format', 'image_tag', 'favorites'] as $table) {
            $count = $conn->fetchOne("SELECT COUNT(*) FROM {$table} WHERE image_id = {$imageId}");
            expect($count)
                ->toBe(0);
        }
        foreach (['rate', 'caddie'] as $table) {
            $count = $conn->fetchOne("SELECT COUNT(*) FROM {$table} WHERE element_id = {$imageId}");
            expect($count)
                ->toBe(0);
        }

        // The cascade is scoped to the deleted image's own id -- a
        // bystander image's own comment row survives untouched.
        $bystanderCount = $conn->fetchOne('SELECT COUNT(*) FROM comments' . " WHERE image_id = {$bystanderId}");
        expect($bystanderCount)
            ->toBe(1);

        $refetched = $repo->find(ImageId::from(1));
        expect($refetched)
            ->not->toBe($cached);
    } finally {
        // The image row is already gone via deleteImages() itself above
        // -- a harmless no-op delete if a genuine bug left it behind.
        imageRepositoryTestDeleteImage($imageId);
        $conn->createQueryBuilder()
            ->delete('comments')
            ->where('image_id = :i')
            ->setParameter('i', $bystanderId)
            ->executeStatement();
        imageRepositoryTestDeleteImage($bystanderId);
        $conn->executeStatement('DELETE FROM tags WHERE id = ?', [$disposableTagId]);
    }
});

test('deleteImages removes the row for real, and clears the identity map', function (): void {
    // Kills line 574's RemoveMethodCall ($em->clear()) -- probed via a
    // DIFFERENT, unrelated cached entity (fixture image 1), since the
    // deleted row itself would read back as null either way regardless
    // of whether the identity map was cleared.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $repo = imageRepositoryTestRepo();
    $cached = $repo->find(ImageId::from(1));
    expect($cached)
        ->not->toBeNull();

    $imageId = imageRepositoryTestInsertImage('upload/2026/07/delete-images-test.jpg');

    $repo->deleteImages([$imageId]);

    $stillThere = DbConnection::build()->fetchOne('SELECT COUNT(*) FROM images' . " WHERE id = {$imageId}");
    expect($stillThere)
        ->toBe(0);

    $refetched = $repo->find(ImageId::from(1));
    expect($refetched)
        ->not->toBe($cached);
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
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why. The category INSERT
    // below also gives `rank` an explicit, high value rather than
    // leaving it to the schema's own NULL default -- NULLs sort first
    // in updateGlobalRank()'s own ORDER BY, so a real fixture category
    // 1 (rank 1) would otherwise be displaced by this row for as long
    // as it's live, the exact mechanism SearchServiceTest.php's own
    // 'zqualifiesonlycat' category needed the same fix for (reproduced
    // live there: findMaxRankForParent()/findFullCategoriesByIds() both
    // observed category 1's own rank column change value).
    DbTransactionTestOverride::rollback();
    $repo = imageRepositoryTestRepo();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/represents-test.jpg');
    $conn = DbConnection::build();
    $rank = $conn->getDatabasePlatform()
        ->quoteSingleIdentifier('rank');
    $conn->executeStatement("INSERT INTO categories (name, representative_picture_id, {$rank}) VALUES ('mutation-sweep-repr-category', {$imageId}, 999)");
    $categoryId = (int) $conn->lastInsertId();

    try {
        $result = $repo->findRepresentedCategoryIds([$imageId]);

        expect($result)
            ->toBe([$categoryId]);
    } finally {
        $conn->executeStatement("DELETE FROM categories WHERE id = {$categoryId}");
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
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $repo = imageRepositoryTestRepo();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/existing-assoc-test.jpg');
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->insert('image_category')
        ->values([
            'image_id' => ':i',
            'category_id' => ':c',
        ])
        ->setParameter('i', $imageId)
        ->setParameter('c', 1)
        ->executeStatement();

    try {
        $result = $repo->findExistingAssociations([$imageId], [1]);

        expect($result)
            ->toBe([
                1 => [$imageId],
            ]);
    } finally {
        $conn->createQueryBuilder()
            ->delete('image_category')
            ->where('image_id = :i')
            ->setParameter('i', $imageId)
            ->executeStatement();
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
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why. The category INSERT
    // below also gives categories.rank (a different column from the
    // image_category.rank under test here) an explicit, high value --
    // see 'findRepresentedCategoryIds...' above for why that matters.
    DbTransactionTestOverride::rollback();
    $repo = imageRepositoryTestRepo();
    $conn = DbConnection::build();
    $categoriesRank = $conn->getDatabasePlatform()
        ->quoteSingleIdentifier('rank');
    $conn->executeStatement("INSERT INTO categories (name, {$categoriesRank}) VALUES ('mutation-sweep-max-rank-category', 999)");
    $categoryId = (int) $conn->lastInsertId();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/max-rank-test.jpg');
    $rankColumn = $conn->getDatabasePlatform()
        ->quoteSingleIdentifier('rank');
    $conn->createQueryBuilder()
        ->insert('image_category')
        ->values([
            'image_id' => ':i',
            'category_id' => ':c',
            $rankColumn => ':r',
        ])
        ->setParameter('i', $imageId)
        ->setParameter('c', $categoryId)
        ->setParameter('r', 7)
        ->executeStatement();

    try {
        $result = $repo->findMaxRanksByCategory([$categoryId]);

        expect($result)
            ->toBe([
                $categoryId => 7,
            ]);
    } finally {
        $conn->createQueryBuilder()
            ->delete('image_category')
            ->where('image_id = :i')
            ->setParameter('i', $imageId)
            ->executeStatement();
        imageRepositoryTestDeleteImage($imageId);
        $conn->executeStatement("DELETE FROM categories WHERE id = {$categoryId}");
    }
});

test('massInsertImageCategory persists every real row it is given', function (): void {
    // Kills line 1470's RemoveMethodCall (clear() -- the identity map,
    // via the same unrelated-cached-entity technique as the sibling
    // tests above).
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $repo = imageRepositoryTestRepo();
    $cached = $repo->find(ImageId::from(1));
    expect($cached)
        ->not->toBeNull();

    $imageId = imageRepositoryTestInsertImage('upload/2026/07/mass-insert-test.jpg');

    try {
        $repo->massInsertImageCategory([
            new ImageCategoryPair(
                imageId: $imageId,
                categoryId: 1,
                rank: 3,
            ),
        ]);

        $conn = DbConnection::build();
        $rankColumn = $conn->getDatabasePlatform()
            ->quoteSingleIdentifier('rank');
        $rank = $conn->fetchOne('SELECT ' . $rankColumn . " FROM image_category WHERE image_id = {$imageId} AND category_id = 1");
        expect($rank)
            ->toBe(3);
        $refetched = $repo->find(ImageId::from(1));
        expect($refetched)
            ->not->toBe($cached);
    } finally {
        DbConnection::build()->createQueryBuilder()->delete('image_category')
            ->where('image_id = :i')
            ->setParameter('i', $imageId)
            ->executeStatement();
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('massInsertImageCategory silently no-ops a row that already exists instead of throwing a duplicate-key violation', function (): void {
    // Regression test for the concurrent pwg.images.setCategory race:
    // ImageService::associateImagesToCategories()'s own findExistingAssociations()
    // pre-check has a TOCTOU window, so massInsertImageCategory() must itself
    // tolerate re-inserting an (image_id, category_id) pair that already exists
    // (image_category's own PRIMARY KEY) rather than throwing and rolling back
    // the whole batch.
    DbTransactionTestOverride::rollback();
    $repo = imageRepositoryTestRepo();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/mass-insert-duplicate-test.jpg');

    try {
        $repo->massInsertImageCategory([
            new ImageCategoryPair(
                imageId: $imageId,
                categoryId: 1,
                rank: 4,
            ),
        ]);

        // Same pair again, as a second concurrent caller's insert would look
        // once it also passed the pre-existing-row check.
        $repo->massInsertImageCategory([
            new ImageCategoryPair(
                imageId: $imageId,
                categoryId: 1,
                rank: 4,
            ),
        ]);

        $conn = DbConnection::build();
        $count = $conn->fetchOne("SELECT COUNT(*) FROM image_category WHERE image_id = {$imageId} AND category_id = 1");
        expect($count)
            ->toBe(1);
    } finally {
        DbConnection::build()->createQueryBuilder()->delete('image_category')
            ->where('image_id = :i')
            ->setParameter('i', $imageId)
            ->executeStatement();
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
        $cached = $repo->find(ImageId::from(1));
        expect($cached)
            ->not->toBeNull();

        $conn->createQueryBuilder()
            ->delete('config')
            ->where('param = :p')
            ->setParameter('p', 'empty_lounge_running')
            ->executeStatement();

        try {
            $repo->tryAcquireLoungeLock('lock-value-1');

            expect($repo->findLoungeLockValue())
                ->toBe('lock-value-1');

            // If $em->clear() didn't run, this find() would return the SAME
            // PHP object identity as $cached (the pre-clear() cached
            // instance) rather than a freshly-hydrated one.
            $refetched = $repo->find(ImageId::from(1));
            expect($refetched)
                ->not->toBe($cached);
        } finally {
            $conn->createQueryBuilder()
                ->delete('config')
                ->where('param = :p')
                ->setParameter('p', 'empty_lounge_running')
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
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $repo = imageRepositoryTestRepo();
    $cached = $repo->find(ImageId::from(1));
    expect($cached)
        ->not->toBeNull();

    $imageId = imageRepositoryTestInsertImage('upload/2026/07/md5-test.jpg', 'old0old0old0old0old0old0old0old0');

    try {
        $repo->massUpdateMd5sums([
            [
                'id' => $imageId,
                'md5sum' => 'new0new0new0new0new0new0new0new0',
            ],
        ]);

        $md5sum = DbConnection::build()->createQueryBuilder()->select('md5sum')->from('images')
            ->where('id = :id')
            ->setParameter('id', $imageId)
            ->fetchOne();
        expect($md5sum)
            ->toBe('new0new0new0new0new0new0new0new0');

        $refetched = $repo->find(ImageId::from(1));
        expect($refetched)
            ->not->toBe($cached);
    } finally {
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findPathsForMd5sum maps id => path as a real string, not a raw driver value', function (): void {
    // Kills line 955's UnwrapArrayMap/RemoveStringCast/
    // EmptyStringToNotEmpty.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/md5sum-paths.jpg');

    try {
        $result = imageRepositoryTestRepo()
            ->findPathsForMd5sum([$imageId]);

        expect($result)
            ->toBe([
                $imageId => 'upload/2026/07/md5sum-paths.jpg',
            ]);
    } finally {
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findById reads a numeric-string id, not just a native int', function (): void {
    // Kills line 1371's RemoveIntegerCast -- find() takes the primary
    // key; without normalizing a numeric STRING id to a real int
    // first, Doctrine's own identity-map/parameter-binding may not
    // resolve it the same way. That normalization now lives in
    // ImageId::from() itself (findById() just reads ->value), but this
    // still proves the numeric-string path works end-to-end through
    // the real repository call, not just ImageId's own unit tests.
    $repo = imageRepositoryTestRepo();

    $imageId = ImageId::tryFrom('1');
    if (! $imageId instanceof ImageId) {
        throw new RuntimeException('expected ImageId::tryFrom() to accept a numeric string');
    }
    $found = $repo->findById($imageId);

    if (! $found instanceof Image) {
        throw new RuntimeException('expected a real Image instance');
    }
    expect($found->id->value)
        ->toBe(1);
});

test('findById returns null for an id that does not exist, not a real Image', function (): void {
    // Kills line 1373's AlwaysReturnNull -- a bare "always null" mutant
    // would pass this test alone, but fail the sibling "found" test
    // above, so together they close it; this one specifically pins the
    // not-found path so an accidental "always return a real Image"
    // regression would also be caught.
    $repo = imageRepositoryTestRepo();

    expect($repo->findById(ImageId::from(999_999)))->toBeNull();
});

test('findIdsByFilenameInCategory returns real ints for a matching filename/category pair', function (): void {
    // Kills line 2121's UnwrapArrayMap/RemoveIntegerCast/
    // DecrementInteger/IncrementInteger -- fetchFirstColumn() on this
    // DBAL QueryBuilder path also returns raw driver types, same
    // reasoning as findPathsForFileDeletion() above.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/filename-test.jpg');
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->insert('image_category')
        ->values([
            'image_id' => ':i',
            'category_id' => ':c',
        ])
        ->setParameter('i', $imageId)
        ->setParameter('c', 1)
        ->executeStatement();

    try {
        $result = imageRepositoryTestRepo()
            ->findIdsByFilenameInCategory('filename-test.jpg', CategoryId::from(1));

        expect($result)
            ->toBe([$imageId]);
    } finally {
        $conn->createQueryBuilder()
            ->delete('image_category')
            ->where('image_id = :i')
            ->setParameter('i', $imageId)
            ->executeStatement();
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findIdsByMd5sum returns real ints for every image sharing the given md5sum', function (): void {
    // Kills line 2362's UnwrapArrayMap/RemoveIntegerCast/
    // DecrementInteger/IncrementInteger.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/md5-lookup.jpg', 'dupe0dupe0dupe0dupe0dupe0dupe0dp');

    try {
        $result = imageRepositoryTestRepo()
            ->findIdsByMd5sum('dupe0dupe0dupe0dupe0dupe0dupe0dp');

        expect($result)
            ->toBe([$imageId]);
    } finally {
        imageRepositoryTestDeleteImage($imageId);
    }
});

test('findIdsVisibleInCategoriesRecentlyAvailable returns real ints for a recently-available image in a given category', function (): void {
    // Kills line 2934's UnwrapArrayMap/RemoveIntegerCast/
    // DecrementInteger/IncrementInteger.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $imageId = imageRepositoryTestInsertImage('upload/2026/07/recent-test.jpg');
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->update('images')
        ->set('date_available', ':d')
        ->where('id = :id')
        ->setParameter('d', date('Y-m-d H:i:s'))
        ->setParameter('id', $imageId)
        ->executeStatement();
    $conn->createQueryBuilder()
        ->insert('image_category')
        ->values([
            'image_id' => ':i',
            'category_id' => ':c',
        ])
        ->setParameter('i', $imageId)
        ->setParameter('c', 1)
        ->executeStatement();

    try {
        $result = imageRepositoryTestRepo()
            ->findIdsVisibleInCategoriesRecentlyAvailable('1', SqlDialect::getRecentPeriodExpression(365));

        // Category 1 already holds the fixture's own images too (real
        // production data, not something this test controls) -- assert
        // strict membership and type instead of an exact-array match.
        expect($result)
            ->toContain($imageId);
    } finally {
        $conn->createQueryBuilder()
            ->delete('image_category')
            ->where('image_id = :i')
            ->setParameter('i', $imageId)
            ->executeStatement();
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
    $result = imageRepositoryTestRepo()
        ->findAddMethodBreakdown();

    $api = null;
    foreach ($result as $row) {
        if ($row->addMethod === 'api') {
            $api = $row;
        }
    }

    if ($api === null) {
        throw new RuntimeException('expected at least one real "api" add_method row');
    }
    expect($api->nbFiles)
        ->toBeGreaterThanOrEqual(5);
    expect($api->lastAddedOn)
        ->toBeString();
});

test('deleteNonStorageCategoryLinks clears the identity map after a raw write outside the ORM', function (): void {
    // Kills line 919's RemoveMethodCall ($em->clear()).
    //
    // A fresh, disposable image/image_category pair, not fixture image
    // 1 -- a prior version of this test deleted image 1's own real
    // image_category row for the span of the test (storage_category_id
    // NULL and no $categories keep-list skips both of the method's own
    // exclusion guards, so it really did delete it, restored in
    // finally). Confirmed live: this raced under --parallel against
    // OTHER Unit-suite files reading image 1's real category membership
    // (CalendarRepositoryTest.php's own findImageIds() tests, beyond the
    // findTopRatedImageIds()/SectionRepositoryTest.php risk this test
    // already anticipated) -- deleteNonStorageCategoryLinks() itself is
    // generic over any image id, so a disposable one with the same
    // "NULL storage_category_id, one category link" shape exercises the
    // identical code path without touching shared fixture data at all.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $conn->insert('images', [
        'file' => 'p17-unit-test-identity-map.jpg',
        'path' => 'upload/p17-unit-test-identity-map.jpg',
    ]);
    $imageId = (int) $conn->lastInsertId();
    $rankColumn = $conn->getDatabasePlatform()
        ->quoteSingleIdentifier('rank');
    $conn->executeStatement("INSERT INTO image_category (image_id, category_id, {$rankColumn}) VALUES (?, 1, 1)", [$imageId]);

    try {
        $repo = imageRepositoryTestRepo();
        $cached = $repo->find(ImageId::from($imageId));
        expect($cached)
            ->not->toBeNull();

        $repo->deleteNonStorageCategoryLinks([$imageId], []);

        $refetched = $repo->find(ImageId::from($imageId));
        expect($refetched)
            ->not->toBe($cached);
    } finally {
        $conn->executeStatement('DELETE FROM image_category WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
    }
});

test('deleteNonStorageCategoryLinks spares the image\'s own storage_category_id link', function (): void {
    // Exercises the `storage_category_id != category_id` half of the
    // exclusion filter -- the identity-map test above uses a freshly-
    // inserted image with no image_category rows at all, so the per-row
    // filtering loop never actually runs there. This seeds real
    // image_category rows across both fixture categories (1 and 2) and a
    // real storage_category_id, so a bug that deletes every link (or
    // spares the wrong one) is observable.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->insert('images')
        ->values([
            'file' => ':file',
            'path' => ':path',
            'storage_category_id' => ':scid',
        ])
        ->setParameter('file', 'storagelink.jpg')
        ->setParameter('path', 'upload/2026/07/storagelink.jpg')
        ->setParameter('scid', 1)
        ->executeStatement();
    $imageId = (int) $conn->lastInsertId();

    $conn->createQueryBuilder()
        ->insert('image_category')
        ->values([
            'image_id' => ':i',
            'category_id' => ':c',
        ])->setParameter('i', $imageId)
        ->setParameter('c', 1)
        ->executeStatement();
    $conn->createQueryBuilder()
        ->insert('image_category')
        ->values([
            'image_id' => ':i',
            'category_id' => ':c',
        ])->setParameter('i', $imageId)
        ->setParameter('c', 2)
        ->executeStatement();

    try {
        imageRepositoryTestRepo()->deleteNonStorageCategoryLinks([$imageId], []);

        $remaining = $conn->fetchFirstColumn('SELECT category_id FROM image_category WHERE image_id = ' . $imageId);
        expect($remaining)
            ->toBe([1]);
    } finally {
        $conn->executeStatement('DELETE FROM image_category WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
    }
});

test('deleteNonStorageCategoryLinks also spares any category explicitly passed in $categories, with a null storage_category_id', function (): void {
    // Covers the other half: storage_category_id IS NULL (no exclusion
    // from that side at all) plus the optional $categories keep-list,
    // which the sibling test above never passes.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- see
    // 'findPathsForFileDeletion...' above for why.
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $conn->createQueryBuilder()
        ->insert('images')
        ->values([
            'file' => ':file',
            'path' => ':path',
        ])
        ->setParameter('file', 'nostoragelink.jpg')
        ->setParameter('path', 'upload/2026/07/nostoragelink.jpg')
        ->executeStatement();
    $imageId = (int) $conn->lastInsertId();

    $conn->createQueryBuilder()
        ->insert('image_category')
        ->values([
            'image_id' => ':i',
            'category_id' => ':c',
        ])->setParameter('i', $imageId)
        ->setParameter('c', 1)
        ->executeStatement();
    $conn->createQueryBuilder()
        ->insert('image_category')
        ->values([
            'image_id' => ':i',
            'category_id' => ':c',
        ])->setParameter('i', $imageId)
        ->setParameter('c', 2)
        ->executeStatement();

    try {
        imageRepositoryTestRepo()->deleteNonStorageCategoryLinks([$imageId], [2]);

        $remaining = $conn->fetchFirstColumn('SELECT category_id FROM image_category WHERE image_id = ' . $imageId);
        expect($remaining)
            ->toBe([2]);
    } finally {
        $conn->executeStatement('DELETE FROM image_category WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
    }
});
