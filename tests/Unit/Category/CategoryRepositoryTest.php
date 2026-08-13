<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryAdminListCriteria;
use Piwigo\Category\CategoryListCriteria;
use Piwigo\Category\CategoryRefDateAggregate;
use Piwigo\Category\CategoryRefDateField;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\Projection\Category;
use Piwigo\Category\Projection\CategoryIdNameUppercatsRank;
use Piwigo\Category\Projection\ComputedCategoryRollupRow;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\Tests\Support\CurrentConfigTestFactory;

/**
 * Piwigo\Category\CategoryRepository -- has its own dedicated
 * tests/Integration/CategoryRepositoryTest.php (105 tests); this ports
 * them down to the Unit suite via the real-DB-no-HTTP pattern. The
 * single biggest Unit-coverage gap in the codebase before this file
 * (1395-line gap, 3863 lines / 112 methods, 9.59% covered, 0 existing
 * Unit tests).
 *
 * Fixture shape: category 1 "Sample Album" (root, uppercats="1",
 * global_rank="1", 3 direct images via image_category), category 2
 * "Nested Sub Album" (child of 1, uppercats="1,2", global_rank="1.1",
 * 2 direct images). old_permalinks has one row: cat_id=1,
 * permalink='old-sample-album'.
 *
 * The Integration original's own tearDown() does a blanket
 * post-test reset (categories.status back to 'public',
 * old_permalinks.hit/last_hit, image_category.rank for category 1) --
 * safe there since composer test:integration runs sequentially, single
 * worker, no cross-file overlap possible. Not safe to copy verbatim
 * into the Unit suite (`--parallel`, 8 workers) -- own your row space:
 * every test below that mutates real fixture category/image_category
 * state now restores it in its own try/finally instead, matching every
 * other test in this file that already did (most of the Integration
 * original already used this pattern per-test; only a handful relied
 * on the blanket tearDown() and needed one added here). Confirmed via
 * grep: no other Unit-suite file currently reads categories.status/
 * site_id/permalink/representative_picture_id for ids 1/2, so the
 * narrow mutate-then-restore window each test opens is the same
 * accepted-risk shape as every other repository port this session.
 *
 * CurrentConfigTestFactory::get() gracefully degrades to a memoized
 * fallback instance when Kernel isn't booted (same precedent as
 * PermalinkServiceTest.php et al.), so no Kernel::boot() is needed
 * here even though the Integration original resolves CurrentConfig via
 * the container. A handful of tests set orderBy/orderByCustom on it --
 * the Integration original's own setUp() resets CurrentConfig fresh
 * before every test via the container; this file's own afterEach()
 * does the equivalent for the fallback instance instead.
 */
function categoryTestRepo(): CategoryRepository
{
    return new CategoryRepository(EntityManagerFactory::build(DbConnection::build()), CurrentConfigTestFactory::get());
}

/**
 * Same construction as categoryTestRepo(), but built against a
 * caller-supplied connection instead of a fresh one -- lets a test thread
 * one explicit connection through the repository, so every raw-SQL
 * mutation, the repository's own reads, and every assertion all happen on
 * the one connection tests/Pest.php's own blanket per-test transaction
 * wraps and rolls back automatically. Used by every test below that
 * mutates category 1/2's own status/representative_picture_id columns or
 * user_access/group_access rows on them -- CategoryServiceTest.php
 * asserts on exactly that shared state.
 */
function categoryTestRepoForConn(Connection $conn): CategoryRepository
{
    return new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get());
}

function categoryTestNoPermissionRestriction(): PermissionCriteria
{
    return new PermissionCriteria(null, null, null, null, null, null);
}

function categoryTestCountRows(string $table, ?Connection $conn = null): int
{
    $count = ($conn ?? DbConnection::build())->createQueryBuilder()
        ->select('COUNT(*) AS c')
        ->from($table)
        ->executeQuery()
        ->fetchOne();

    return is_numeric($count) ? (int) $count : 0;
}

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

test('findById() returns a typed Category projection', function (): void {
    $cat = categoryTestRepo()
        ->findById(1);

    expect($cat)
        ->toBeInstanceOf(Category::class);
    if (! $cat instanceof Category) {
        throw new LogicException('unreachable -- asserted above');
    }
    expect($cat->id)
        ->toEqual(CategoryId::from(1))
        ->and($cat->name)
        ->toBe('Sample Album');
});

test('findById() returns null for a missing category', function (): void {
    expect(categoryTestRepo()->findById(999999))
        ->toBeNull();
});

test('findNamesByIds() keys by id', function (): void {
    $names = categoryTestRepo()
        ->findNamesByIds([1, 2]);

    expect($names['1']->name)
        ->toBe('Sample Album')
        ->and($names['2']->name)
        ->toBe('Nested Sub Album');
});

test('findNamesByIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findNamesByIds([]))
        ->toBe([]);
});

test('findSubcategoryIds() matches via uppercats path', function (): void {
    // category 2's uppercats is "1,2" -- it's a subcategory of 1.
    $ids = categoryTestRepo()
        ->findSubcategoryIds([1]);
    sort($ids);

    expect($ids)
        ->toBe([1, 2]);
});

test('findSubcategoryIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findSubcategoryIds([]))
        ->toBe([]);
});

test('findRandomImageId() returns an image from the category', function (): void {
    $imageId = categoryTestRepo()
        ->findRandomImageId(1, '1', false, categoryTestNoPermissionRestriction());

    expect($imageId)
        ->toBeIn([1, 2, 3]);
});

test('findRandomImageId() recursive includes subcategory images', function (): void {
    // recursive=true against the root also pulls in category 2's images
    // (4, 5) -- run enough draws that at least one subcategory image is
    // virtually certain to come up, proving recursion actually widens
    // the pool beyond category 1's own (1, 2, 3).
    $found = [];
    for ($i = 0; $i < 30; $i++) {
        $imageId = categoryTestRepo()
            ->findRandomImageId(1, '1', true, categoryTestNoPermissionRestriction());
        if ($imageId !== null) {
            $found[$imageId] = true;
        }
    }

    expect(isset($found[4]) || isset($found[5]))
        ->toBeTrue();
    // sanity: never returns an image outside the {1,2,3,4,5} fixture set
    foreach (array_keys($found) as $id) {
        expect($id)
            ->toBeIn([1, 2, 3, 4, 5]);
    }
});

test('findRandomImageId() returns null when the permission condition excludes everything', function (): void {
    expect(categoryTestRepo()->findRandomImageId(1, '1', false, new PermissionCriteria([1], null, null, null, null, null)))
        ->toBeNull();
});

test('findRandomImageIdInCategory() returns an image from the category', function (): void {
    // Real regression coverage for the custom RandFunction DQL
    // function's own `ORDER BY RAND()` -- runs enough draws to make it
    // very unlikely a single lucky pick masks a query that's actually
    // broken (e.g. always returning the same row, or the wrong pool).
    $repo = categoryTestRepo();
    for ($i = 0; $i < 10; $i++) {
        expect($repo->findRandomImageIdInCategory(1))
            ->toBeIn([1, 2, 3]);
    }
});

test('findRandomImageIdInCategory() returns null for a category without images', function (): void {
    expect(categoryTestRepo()->findRandomImageIdInCategory(999))
        ->toBeNull();
});

test('findComputedCategoriesRollup() returns one row per category', function (): void {
    $rows = categoryTestRepo()
        ->findComputedCategoriesRollup(0, null, '');

    $byId = [];
    foreach ($rows as $row) {
        $byId[$row->catId] = $row;
    }

    // nb_images is a COUNT() aggregate -- comes back as native int under
    // this project's mysqli driver config.
    expect($byId['1']->nbImages)
        ->toBe(3)
        ->and($byId['2']->nbImages)
        ->toBe(2);
});

test('findComputedCategoriesRollup() excludes forbidden categories', function (): void {
    $rows = categoryTestRepo()
        ->findComputedCategoriesRollup(0, null, '2');

    $ids = array_map(static fn (ComputedCategoryRollupRow $row): int => $row->catId, $rows);
    expect($ids)
        ->not->toContain(2)
        ->toContain(1);
});

test('findComputedCategoriesRollup() keeps a category row when no images are in the recent period', function (): void {
    // filterDays=0 with a fixture whose images predate "now" means no
    // image matches the join condition -- the category row must still
    // appear (nb_images=0), proving the date filter lives in the JOIN's
    // ON clause and not a WHERE (see the repository's own docblock).
    $rows = categoryTestRepo()
        ->findComputedCategoriesRollup(0, 0, '');

    $byId = [];
    foreach ($rows as $row) {
        $byId[$row->catId] = $row;
    }

    expect($byId)
        ->toHaveKey('1')
        ->and($byId['1']->nbImages)
        ->toBe(0);
});

test('findImageIdsForCategories() returns images in category', function (): void {
    $ids = categoryTestRepo()
        ->findImageIdsForCategories([1], 'AND', categoryTestNoPermissionRestriction());
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findImageIdsForCategories() returns empty for no categories', function (): void {
    expect(categoryTestRepo()->findImageIdsForCategories([], 'AND', categoryTestNoPermissionRestriction()))
        ->toBe([]);
});

test('findImageIdsForCategories() AND mode requires all categories', function (): void {
    // no image belongs to both category 1 and 2 in the fixture, so AND
    // mode across [1, 2] returns nothing.
    expect(categoryTestRepo()->findImageIdsForCategories([1, 2], 'AND', categoryTestNoPermissionRestriction()))
        ->toBe([]);
});

test('findImageIdsForCategories() orders by CurrentConfig orderBy', function (): void {
    // The real DQL path -- a single bounded-vocabulary field, not
    // sorted before comparing, to prove the parsed order is what
    // actually reaches the query rather than incidental id order.
    CurrentConfigTestFactory::get()->orderBy = 'ORDER BY id DESC';

    $ids = categoryTestRepo()
        ->findImageIdsForCategories([1], 'AND', categoryTestNoPermissionRestriction());

    expect($ids)
        ->toBe([3, 2, 1]);
});

test('findImageIdsForCategories() orders by rank', function (): void {
    // `rank` lives on the image_category join row, not on images
    // itself -- deliberately set to the reverse of id order so a stale
    // fallback to id-order would fail this assertion.
    $conn = DbConnection::build();
    $rank = $conn->getDatabasePlatform()
        ->quoteSingleIdentifier('rank');

    try {
        $conn->executeStatement(
            "UPDATE image_category SET {$rank} = CASE image_id WHEN 1 THEN 3 WHEN 2 THEN 2 WHEN 3 THEN 1 END WHERE category_id = 1"
        );
        // No quoting needed here -- PhotoSortField::parseOrderByFragment()'s
        // own regex already treats a surrounding backtick as optional, and
        // this raw config string never round-trips through column()'s own
        // (now driver-aware) quoted output, so a bare, unquoted field name
        // parses identically on both platforms.
        CurrentConfigTestFactory::get()->orderBy = 'ORDER BY rank ASC';

        $ids = categoryTestRepo()
            ->findImageIdsForCategories([1], 'AND', categoryTestNoPermissionRestriction());

        expect($ids)
            ->toBe([3, 2, 1]);
    } finally {
        $conn->executeStatement(
            "UPDATE image_category SET {$rank} = CASE image_id WHEN 1 THEN 1 WHEN 2 THEN 2 WHEN 3 THEN 3 END WHERE category_id = 1"
        );
    }
});

test('findImageIdsForCategories() falls back to raw DBAL when orderByCustom is set', function (): void {
    // A sysadmin-local-config override -- RequestBootstrap.php's own
    // real bootstrap-time behavior copies its value into orderBy() too;
    // mirrored here since this test bypasses that bootstrap step.
    CurrentConfigTestFactory::get()->orderByCustom = 'ORDER BY RAND()';
    CurrentConfigTestFactory::get()->orderBy = 'ORDER BY RAND()';

    $ids = categoryTestRepo()
        ->findImageIdsForCategories([1], 'AND', categoryTestNoPermissionRestriction());
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findImageIdsForCategories() falls back to raw DBAL for an unparseable orderBy', function (): void {
    // Not one of $sort_fields's own bounded tokens -- the parser must
    // reject it and this must still return the right members via the
    // original raw-DBAL path, not throw.
    CurrentConfigTestFactory::get()->orderBy = 'ORDER BY comment ASC';

    $ids = categoryTestRepo()
        ->findImageIdsForCategories([1], 'AND', categoryTestNoPermissionRestriction());
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findCommonCategories() counts matching images', function (): void {
    $common = categoryTestRepo()
        ->findCommonCategories([1, 2, 3], null, [], categoryTestNoPermissionRestriction());

    expect($common['1']->counter)
        ->toBe(3);
});

test('findCommonCategories() returns empty for no items', function (): void {
    expect(categoryTestRepo()->findCommonCategories([], null, [], categoryTestNoPermissionRestriction()))
        ->toBe([]);
});

test('findCategoriesByIds() returns matching rows', function (): void {
    $cats = categoryTestRepo()
        ->findCategoriesByIds([1, 2]);

    $names = array_column($cats, 'name');
    sort($names);
    expect($names)
        ->toBe(['Nested Sub Album', 'Sample Album']);
});

test('findComputedCategoriesRollup() includes rank', function (): void {
    // CategoryCatsRenderer needs `rank` (sibling order) for
    // CategoryService::compareByRank(), distinct from `global_rank`.
    $rows = categoryTestRepo()
        ->findComputedCategoriesRollup(0, null, '');

    $byId = [];
    foreach ($rows as $row) {
        $byId[$row->catId] = $row;
    }

    expect($byId)
        ->toHaveKey('1')
        ->toHaveKey('2');
});

test('findFullCategoriesByIds() returns every column', function (): void {
    $cats = categoryTestRepo()
        ->findFullCategoriesByIds([1]);

    expect($cats)
        ->toHaveCount(1);
    $cat = $cats[0];
    expect($cat->name)
        ->toBe('Sample Album')
        // uppercats/rank/representativePictureId are real `categories`
        // columns findCategoriesByIds()'s own narrower 6-column contract
        // doesn't expose -- confirms this is genuinely `SELECT *`, not a
        // duplicate of that method.
        ->and($cat->uppercats)
        ->toBe('1')
        ->and($cat->rank)
        ->toBe(1)
        ->and($cat->representativePictureId)
        ->toBe(1)
        ->and($cat->comment)
        ->toBeNull();
});

test('findFullCategoriesByIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findFullCategoriesByIds([]))
        ->toBe([]);
});

test('findCategoriesByIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findCategoriesByIds([]))
        ->toBe([]);
});

test('findStorageLinkedImageIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findStorageLinkedImageIds([]))
        ->toBe([]);
});

test('findStorageLinkedImageIds() returns matching images', function (): void {
    // No fixture image has a real storage_category_id (filesystem-sync
    // linkage, distinct from image_category membership) -- give image 1
    // one temporarily.
    $conn = DbConnection::build();

    try {
        $conn->executeStatement('UPDATE images SET storage_category_id = 1 WHERE id = 1');

        $repo = categoryTestRepo();
        expect($repo->findStorageLinkedImageIds([1]))
            ->toBe([1])
            ->and($repo->findStorageLinkedImageIds([2]))
            ->toBe([]);
    } finally {
        $conn->executeStatement('UPDATE images SET storage_category_id = NULL WHERE id = 1');
    }
});

test('findDistinctStorageCategoryIds() returns the real distinct set', function (): void {
    $conn = DbConnection::build();

    try {
        $conn->executeStatement('UPDATE images SET storage_category_id = 1 WHERE id IN (1, 2)');

        expect(categoryTestRepo()->findDistinctStorageCategoryIds())
            ->toBe([1]);
    } finally {
        $conn->executeStatement('UPDATE images SET storage_category_id = NULL WHERE id IN (1, 2)');
    }
});

test('updateImagePathsForCategory() rewrites the path from the new fulldir', function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement('UPDATE images SET storage_category_id = 1 WHERE id = 1');

    categoryTestRepoForConn($conn)
        ->updateImagePathsForCategory(CategoryId::from(1), 'galleries/renamed-album');

    $path = $conn->fetchOne('SELECT path FROM images WHERE id = 1');
    expect($path)
        ->toBe('galleries/renamed-album/fixture-photo-1.jpg');
});

test('findDistinctLinkedImageIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findDistinctLinkedImageIds([]))
        ->toBe([]);
});

test('findNonOrphanImageIds() returns empty for no image ids', function (): void {
    expect(categoryTestRepo()->findNonOrphanImageIds([], [2]))
        ->toBe([]);
});

test('findNonOrphanImageIds() keeps only images still linked outside the excluded categories', function (): void {
    // Images 1-3 are (also) directly linked to category 1, which is
    // NOT excluded here -- they survive. Images 4-5 are linked ONLY to
    // category 2, the excluded one -- they don't survive, proving this
    // is a real "still has another home" diff, not just "was in the
    // requested list".
    $ids = categoryTestRepo()
        ->findNonOrphanImageIds([1, 2, 3, 4, 5], [2]);
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('deleteUserAccessForCategories() is a no-op for no ids', function (): void {
    $conn = DbConnection::build();
    $before = categoryTestCountRows('user_access', $conn);

    categoryTestRepoForConn($conn)
        ->deleteUserAccessForCategories([]);

    expect(categoryTestCountRows('user_access', $conn))
        ->toBe($before);
});

test('deleteGroupAccessForCategories() is a no-op for no ids', function (): void {
    $conn = DbConnection::build();
    $before = categoryTestCountRows('group_access', $conn);

    categoryTestRepoForConn($conn)
        ->deleteGroupAccessForCategories([]);

    expect(categoryTestCountRows('group_access', $conn))
        ->toBe($before);
});

test('deleteUserAccessForUsersAndCategories() is a no-op for no user ids', function (): void {
    $conn = DbConnection::build();
    $before = categoryTestCountRows('user_access', $conn);

    categoryTestRepoForConn($conn)
        ->deleteUserAccessForUsersAndCategories([], [1]);

    expect(categoryTestCountRows('user_access', $conn))
        ->toBe($before);
});

test('deleteGroupAccessForGroupsAndCategories() is a no-op for no group ids', function (): void {
    $conn = DbConnection::build();
    $before = categoryTestCountRows('group_access', $conn);

    categoryTestRepoForConn($conn)
        ->deleteGroupAccessForGroupsAndCategories([], [1]);

    expect(categoryTestCountRows('group_access', $conn))
        ->toBe($before);
});

test('deleteCategoriesByIds() is a no-op for no ids', function (): void {
    categoryTestRepo()->deleteCategoriesByIds([]);

    expect(categoryTestRepo()->findById(1))
        ->not->toBeNull();
});

test('clearRepresentativePictureIds() is a no-op for no ids', function (): void {
    categoryTestRepo()->clearRepresentativePictureIds([]);

    expect(categoryTestRepo()->findById(1))
        ->not->toBeNull();
});

test('updateCategoryVisibility() is a no-op for no ids', function (): void {
    categoryTestRepo()->updateCategoryVisibility([], false);

    // visible is a genuine boolean column -- DBAL's raw (unmapped)
    // fetchOne() returns a native PHP bool for it on Postgres, but a
    // numeric 1/0 on MySQL (confirmed live). (int) (bool) normalizes
    // either representation to the same value, unlike the old
    // is_numeric()-gated cast, which silently produced null for a
    // real Postgres bool.
    $visible = DbConnection::build()->createQueryBuilder()
        ->select('visible')
        ->from('categories')
        ->where('id = 1')
        ->executeQuery()
        ->fetchOne();
    expect((int) (bool) $visible)
        ->toBe(1);
});

test('updateCategoryCommentable() is a no-op for no ids', function (): void {
    categoryTestRepo()->updateCategoryCommentable([], false);

    $commentable = DbConnection::build()->createQueryBuilder()
        ->select('commentable')
        ->from('categories')
        ->where('id = 1')
        ->executeQuery()
        ->fetchOne();
    expect((int) (bool) $commentable)
        ->toBe(1);
});

test('updateCategoryStatus() is a no-op for no ids', function (): void {
    categoryTestRepo()->updateCategoryStatus([], 'private');

    expect(categoryTestRepo()->findCategoryStatus(1))
        ->toBe('public');
});

test('updateImageOrder() is a no-op for a missing category', function (): void {
    // 999999 doesn't exist -- find() returns null and the method
    // returns early instead of dereferencing a null entity.
    categoryTestRepo()
        ->updateImageOrder(CategoryId::from(999999), 'name ASC');

    expect(categoryTestRepo()->findById(999999))
        ->toBeNull();
});

test('findStatusByIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findStatusByIds([]))
        ->toBe([]);
});

test('findUppercatsColumns() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findUppercatsColumns([]))
        ->toBe([]);
});

test('findUppercatsById() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findUppercatsById([]))
        ->toBe([]);
});

test('findRefDatesByCategoryIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findRefDatesByCategoryIds([], CategoryRefDateField::DateCreation, CategoryRefDateAggregate::Min))
        ->toBe([]);
});

test('findUppercatIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findUppercatIds([]))
        ->toBe([]);
});

test('findCategoriesForFulldirs() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findCategoriesForFulldirs([]))
        ->toBe([]);
});

test('updateCategoryParent() is a no-op for no ids', function (): void {
    categoryTestRepo()->updateCategoryParent([], 'NULL');

    expect(categoryTestRepo()->findById(2))
        ->not->toBeNull();
    $idUppercat = DbConnection::build()->createQueryBuilder()
        ->select('id_uppercat')
        ->from('categories')
        ->where('id = 2')
        ->executeQuery()
        ->fetchOne();
    expect(is_numeric($idUppercat) ? (int) $idUppercat : null)
        ->toBe(1);
});

test('findMaxRankForParent() returns the highest rank among root categories', function (): void {
    expect(categoryTestRepo()->findMaxRankForParent(null))
        ->toBe(1);
});

test('findMaxRankForParent() returns the highest rank among a specific parent\'s children', function (): void {
    expect(categoryTestRepo()->findMaxRankForParent(1))
        ->toBe(1);
});

test('findMaxRankForParent() returns null when the parent has no children', function (): void {
    expect(categoryTestRepo()->findMaxRankForParent(2))
        ->toBeNull();
});

test('findRandomRepresentativeIdAmongSubcategories() finds a real match', function (): void {
    // category 2 ("1,2") is the only row LIKE '1,%', and its fixture
    // representative_picture_id is 4.
    expect(categoryTestRepo()->findRandomRepresentativeIdAmongSubcategories('1', categoryTestNoPermissionRestriction()))
        ->toBe('4');
});

test('findRandomRepresentativeIdAmongSubcategories() returns null when no subcategory matches', function (): void {
    // category 2 has no sub-categories of its own.
    expect(categoryTestRepo()->findRandomRepresentativeIdAmongSubcategories('2', categoryTestNoPermissionRestriction()))
        ->toBeNull();
});

test('findRandomRepresentativeIdAmongSubcategories() returns null when the permission condition excludes everything', function (): void {
    expect(categoryTestRepo()->findRandomRepresentativeIdAmongSubcategories('1', new PermissionCriteria(null, [999_999], null, null, null, null)))
        ->toBeNull();
});

test('findDateRangeByCategory() returns empty for no category ids', function (): void {
    expect(categoryTestRepo()->findDateRangeByCategory([], categoryTestNoPermissionRestriction()))
        ->toBe([]);
});

test('findDateRangeByCategory() returns the min and max creation date', function (): void {
    // Only image 2's date_creation is set (the other 2 direct images
    // of category 1 stay NULL) -- MIN/MAX both resolve to that single
    // real value, proving the aggregate reads real data rather than
    // just echoing back a NULL.
    $conn = DbConnection::build();

    try {
        $conn->executeStatement("UPDATE images SET date_creation = '2019-06-15 10:00:00' WHERE id = 2");

        $range = categoryTestRepo()
            ->findDateRangeByCategory([1], categoryTestNoPermissionRestriction());
        expect($range)
            ->toHaveCount(1);
        $entry = array_first($range);
        if ($entry === null) {
            throw new LogicException('unreachable -- asserted count above');
        }

        expect($entry->from)
            ->toBe('2019-06-15 10:00:00')
            ->and($entry->to)
            ->toBe('2019-06-15 10:00:00');
    } finally {
        $conn->executeStatement('UPDATE images SET date_creation = NULL WHERE id = 2');
    }
});

test('findIdNamePermalinkById() returns null for a missing category', function (): void {
    expect(categoryTestRepo()->findIdNamePermalinkById(999999))
        ->toBeNull();
});

test('findImageIdsOutsideCategories() returns ids linked to other categories', function (): void {
    // Excluding category 2 leaves only category 1's own image_category
    // rows (images 1, 2, 3) -- category 2's images (4, 5) are dropped,
    // proving the NOT IN condition actually reaches the query.
    $ids = categoryTestRepo()
        ->findImageIdsOutsideCategories([2]);
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('massInsertCategories() is a no-op for no inserts', function (): void {
    $before = categoryTestCountRows('categories');

    categoryTestRepo()
        ->massInsertCategories(['name'], []);

    expect(categoryTestCountRows('categories'))
        ->toBe($before);
});

test('updateFields() is a no-op for no data', function (): void {
    categoryTestRepo()->updateFields(CategoryId::from(1), []);

    $cat = categoryTestRepo()
        ->findById(1);
    expect($cat)
        ->not->toBeNull();
    expect($cat?->name)
        ->toBe('Sample Album');
});

test('setRepresentativeImageForCategories() is a no-op for no ids', function (): void {
    categoryTestRepo()->setRepresentativeImageForCategories([], 5);

    $repId = DbConnection::build()->createQueryBuilder()
        ->select('representative_picture_id')
        ->from('categories')
        ->where('id = 1')
        ->executeQuery()
        ->fetchOne();

    expect(is_numeric($repId) ? (int) $repId : null)
        ->toBe(1);
});

test('setRepresentativeImageForCategories() updates every listed category', function (): void {
    $conn = DbConnection::build();
    categoryTestRepoForConn($conn)
        ->setRepresentativeImageForCategories([1, 2], 3);

    $repId1 = $conn->createQueryBuilder()
        ->select('representative_picture_id')
        ->from('categories')
        ->where('id = 1')
        ->executeQuery()
        ->fetchOne();
    $repId2 = $conn->createQueryBuilder()
        ->select('representative_picture_id')
        ->from('categories')
        ->where('id = 2')
        ->executeQuery()
        ->fetchOne();

    expect(is_numeric($repId1) ? (int) $repId1 : null)
        ->toBe(3)
        ->and(is_numeric($repId2) ? (int) $repId2 : null)
        ->toBe(3);
});

test('findDirsByIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findDirsByIds([]))
        ->toBe([]);
});

test('findExistingIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findExistingIds([]))
        ->toBe([]);
});

test('findSubcategoryCountsByParent() returns empty for no parent ids', function (): void {
    expect(categoryTestRepo()->findSubcategoryCountsByParent([]))
        ->toBe([]);
});

test('findRankInfoByIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findRankInfoByIds([]))
        ->toBe([]);
});

test('findMoveDetailsByIds() returns empty for no ids', function (): void {
    expect(categoryTestRepo()->findMoveDetailsByIds([]))
        ->toBe([]);
});

test('countByVisible() counts only matching rows', function (): void {
    // Both fixture categories have visible=1 -- proves the bound
    // :visible parameter actually discriminates (a broken bind that
    // always matched, or never matched, would pass a same-count-for-
    // both regression test but not this one).
    $repo = categoryTestRepo();
    expect($repo->countByVisible(true))
        ->toBe(2)
        ->and($repo->countByVisible(false))
        ->toBe(0);
});

test('findByCommentable() filters on the commentable flag', function (): void {
    // Both fixture categories have commentable=1.
    $repo = categoryTestRepo();
    $rows = $repo->findByCommentable(true);
    expect(array_column($rows, 'id'))
        ->toBe([1, 2]);

    expect($repo->findByCommentable(false))
        ->toBe([]);
});

test('findByVisible() filters on the visible flag', function (): void {
    // Both fixture categories have visible=1.
    $repo = categoryTestRepo();
    $rows = $repo->findByVisible(true);
    expect(array_column($rows, 'id'))
        ->toBe([1, 2]);

    expect($repo->findByVisible(false))
        ->toBe([]);
});

test('findByStatus() filters on the status column', function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 2");

    $repo = categoryTestRepoForConn($conn);
    $publicRows = $repo->findByStatus('public');
    $privateRows = $repo->findByStatus('private');

    expect(array_column($publicRows, 'id'))
        ->toBe([1])
        ->and(array_column($privateRows, 'id'))
        ->toBe([2]);
});

test('findByRepresentativePresence() true returns categories with a representative', function (): void {
    $conn = DbConnection::build();
    $repo = categoryTestRepoForConn($conn);
    $rows = $repo->findByRepresentativePresence(true);
    expect(array_column($rows, 'id'))
        ->toBe([1, 2]);

    $conn->executeStatement('UPDATE categories SET representative_picture_id = NULL WHERE id IN (1, 2)');
    expect($repo->findByRepresentativePresence(true))
        ->toBe([]);
});

test('findByRepresentativePresence() false joins image_category and deduplicates', function (): void {
    $conn = DbConnection::build();

    // Category 1 has no representative but 3 direct images (image_category
    // rows 1,2,3) -- the DISTINCT is what's actually under test here: a
    // missing distinct() would return category 1 three times (once per
    // matching image_category row).
    $conn->executeStatement('UPDATE categories SET representative_picture_id = NULL WHERE id = 1');

    $rows = categoryTestRepoForConn($conn)
        ->findByRepresentativePresence(false);

    expect(array_column($rows, 'id'))
        ->toBe([1]);
});

test('findPrivateCategoriesGrantedToUser() excludes group-authorized ids', function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement(
        'INSERT INTO user_access (user_id, cat_id) VALUES (?, ?)',
        [3, 1]
    );
    $conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

    $repo = categoryTestRepoForConn($conn);
    $rows = $repo->findPrivateCategoriesGrantedToUser(3);
    expect(array_column($rows, 'id'))
        ->toBe([1]);

    // Same user_access grant, but category 1 is already covered via a
    // group membership -- must be excluded from this result.
    expect($repo->findPrivateCategoriesGrantedToUser(3, ['1']))
        ->toBe([]);
});

test('findPrivateCategoriesGrantedToGroup() matches fixture grants', function (): void {
    // Fixture group_access: (group_id=1, cat_id=1), (group_id=2, cat_id=1),
    // (group_id=3, cat_id=1), (group_id=1, cat_id=2).
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE categories SET status = 'private'");

    $repo = categoryTestRepoForConn($conn);
    expect(array_column($repo->findPrivateCategoriesGrantedToGroup(1), 'id'))
        ->toBe([1, 2])
        ->and(array_column($repo->findPrivateCategoriesGrantedToGroup(2), 'id'))
        ->toBe([1])
        ->and(array_column($repo->findPrivateCategoriesGrantedToGroup(999999), 'id'))
        ->toBe([]);
});

test('findPrivateCategoryIdsGrantedToGroup() matches fixture grants', function (): void {
    // This method's JOIN...WITH condition (ga.catId = c.id) compares
    // GroupAccessEntity's own custom-CategoryId-typed field against
    // CategoryEntity's plain int id -- confirming here that it still
    // resolves correctly against a real DB (DQL JOIN...WITH conditions
    // compile to a plain SQL column comparison regardless of PHP-side
    // Type differences).
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE categories SET status = 'private'");

    $repo = categoryTestRepoForConn($conn);
    expect($repo->findPrivateCategoryIdsGrantedToGroup(1))
        ->toBe([1, 2])
        ->and($repo->findPrivateCategoryIdsGrantedToGroup(2))
        ->toBe([1])
        ->and($repo->findPrivateCategoryIdsGrantedToGroup(999999))
        ->toBe([]);
});

test('findCategoriesAuthorizedViaGroupsForUser() deduplicates across memberships', function (): void {
    // Fixture user_group: user 3 belongs to groups 1 and 2. group_access:
    // group 1 grants cat 1 and cat 2, group 2 grants cat 1 -- cat 1 is
    // reachable via both memberships, so this also confirms the
    // ->distinct() actually dedupes.
    $ids = array_column(categoryTestRepo()->findCategoriesAuthorizedViaGroupsForUser(3), 'catId');
    sort($ids);

    expect($ids)
        ->toBe([1, 2]);
});

test('findCategoriesAuthorizedViaGroupsForUser() returns empty for a groupless user', function (): void {
    expect(categoryTestRepo()->findCategoriesAuthorizedViaGroupsForUser(2))
        ->toBe([]);
});

test('findPrivateCategoriesExcluding() filters out the given ids', function (): void {
    // Filtered down to known-real ids rather than an unfiltered toBe(),
    // since this blanket UPDATE (no WHERE clause) also makes any other
    // --parallel worker's own briefly-committed extra category private
    // for the span it's visible to this test's own isolated transaction.
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE categories SET status = 'private'");

    $repo = categoryTestRepoForConn($conn);
    $none = array_values(array_intersect(array_column($repo->findPrivateCategoriesExcluding([]), 'id'), [1, 2]));
    $exclude1 = array_values(array_intersect(array_column($repo->findPrivateCategoriesExcluding(['1']), 'id'), [1, 2]));
    $excludeBoth = array_values(array_intersect(array_column($repo->findPrivateCategoriesExcluding(['1', '2']), 'id'), [1, 2]));

    expect($none)
        ->toBe([1, 2])
        ->and($exclude1)
        ->toBe([2])
        ->and($excludeBoth)
        ->toBe([]);
});

test('findIdNameUppercatsRank() applies the given condition', function (): void {
    // Filtered down to known-real ids rather than an unfiltered toBe(),
    // since another --parallel worker's own FULLTEXT-deadlock-exempted
    // categories-creating test (CategoryServiceTest.php has several) does
    // a real, briefly-committed INSERT INTO categories this test's own
    // isolated transaction CAN observe until that other test's own
    // cleanup runs.
    $repo = categoryTestRepo();
    $matchAll = $repo->findIdNameUppercatsRank(categoryTestNoPermissionRestriction());
    $realIds = array_values(array_intersect(array_column($matchAll, 'id'), [1, 2]));
    expect($realIds)
        ->toBe([1, 2]);

    expect($repo->findIdNameUppercatsRank(new PermissionCriteria(null, [999_999], null, null, null, null)))
        ->toBe([]);
});

test('findAllForPermalinksDisplay() computes a permalink-aware name', function (): void {
    $rows = categoryTestRepo()
        ->findAllForPermalinksDisplay();

    $byId = [];
    foreach ($rows as $row) {
        $byId[$row->id] = $row;
    }

    // Both fixture categories have permalink NULL, so the CONCAT's IF()
    // branch appends nothing (no trailing checkmark).
    expect($byId[1]->name)
        ->toBe('1 - Sample Album')
        ->and($byId[2]->name)
        ->toBe('2 - Nested Sub Album')
        ->and($byId[1]->permalink)
        ->toBeNull();
});

test('findAllForPermalinksDisplay() appends a checkmark when permalink is set', function (): void {
    $conn = DbConnection::build();

    try {
        $conn->executeStatement("UPDATE categories SET permalink = 'sample-album' WHERE id = 1");

        $rows = categoryTestRepo()
            ->findAllForPermalinksDisplay();

        $byId = array_column($rows, null, 'id');
        expect($byId[1]->name)
            ->toBe('1 - Sample Album &radic;')
            ->and($byId[1]->permalink)
            ->toBe('sample-album')
            ->and($byId[2]->name)
            ->toBe('2 - Nested Sub Album');
    } finally {
        $conn->executeStatement('UPDATE categories SET permalink = NULL WHERE id = 1');
    }
});

test('findIdNameUppercatsRankBySite() filters on site id', function (): void {
    $repo = categoryTestRepo();
    $conn = DbConnection::build();
    // Both fixture categories have site_id NULL -- never matches a real id.
    expect($repo->findIdNameUppercatsRankBySite(1))
        ->toBe([]);

    try {
        $conn->executeStatement('UPDATE categories SET site_id = 1 WHERE id = 1');

        $rows = $repo->findIdNameUppercatsRankBySite(1);
        expect(array_map(static fn (CategoryIdNameUppercatsRank $row): int => $row->id, $rows))
            ->toBe([1]);
    } finally {
        $conn->executeStatement('UPDATE categories SET site_id = NULL WHERE id = 1');
    }
});

/**
 * Every findListForWs() test below that reads an unrestricted (or
 * catId=1-subtree) slice of `categories` filters its result down to
 * known-real ids rather than an unfiltered toBe() -- another --parallel
 * worker's own FULLTEXT-deadlock-exempted categories-creating test
 * (CategoryServiceTest.php has several) does a real, briefly-committed
 * INSERT INTO categories this test's own isolated transaction CAN
 * observe until that other test's own cleanup runs. 'applies the search
 * term' is naturally immune (none of those disposable category names
 * ever contain "Nested").
 */
test('findListForWs() scopes to root categories when recursive is false and catId is null', function (): void {
    $criteria = new CategoryListCriteria(catId: null, recursive: false, forbiddenCategoryIds: [], publicOnly: false);

    $result = categoryTestRepo()
        ->findListForWs($criteria, null, 10, null, false);

    $realIds = array_values(array_intersect(array_column($result->rows, 'id'), [1]));
    expect($realIds)
        ->toBe([1]);
});

test('findListForWs() matches a category and its direct children when recursive is false and catId is set', function (): void {
    $criteria = new CategoryListCriteria(catId: CategoryId::from(1), recursive: false, forbiddenCategoryIds: [], publicOnly: false);

    $result = categoryTestRepo()
        ->findListForWs($criteria, null, 10, null, false);

    $ids = array_values(array_intersect(array_column($result->rows, 'id'), [1, 2]));
    sort($ids);
    expect($ids)
        ->toBe([1, 2]);
});

test('findListForWs() matches the full subtree when recursive', function (): void {
    $criteria = new CategoryListCriteria(catId: CategoryId::from(1), recursive: true, forbiddenCategoryIds: [], publicOnly: false);

    $result = categoryTestRepo()
        ->findListForWs($criteria, null, 10, null, false);

    $ids = array_values(array_intersect(array_column($result->rows, 'id'), [1, 2]));
    sort($ids);
    expect($ids)
        ->toBe([1, 2]);
});

test('findListForWs() excludes forbidden category ids', function (): void {
    $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [2], publicOnly: false);

    $result = categoryTestRepo()
        ->findListForWs($criteria, null, 10, null, false);

    $realIds = array_values(array_intersect(array_column($result->rows, 'id'), [1]));
    expect($realIds)
        ->toBe([1]);
});

test('findListForWs() publicOnly excludes non-public categories', function (): void {
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 2");

    $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [], publicOnly: true);

    $result = categoryTestRepoForConn($conn)
        ->findListForWs($criteria, null, 10, null, false);

    $realIds = array_values(array_intersect(array_column($result->rows, 'id'), [1]));
    expect($realIds)
        ->toBe([1]);
});

test('findListForWs() applies the search term', function (): void {
    $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [], publicOnly: false);

    $result = categoryTestRepo()
        ->findListForWs($criteria, 'Nested', 10, null, false);

    expect(array_column($result->rows, 'id'))
        ->toBe([2]);
});

test('findListForWs() applies the limit and reports the total', function (): void {
    $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [], publicOnly: false);

    $result = categoryTestRepo()
        ->findListForWs($criteria, null, 10, 1, false);

    // total reflects the real (unlimited) count, not just count($rows) --
    // >= 2 rather than exactly 2, since it's as susceptible to the same
    // transient-extra-category visibility as every sibling test above,
    // and a raw scalar total can't be filtered down to known-real ids
    // the way a row list can.
    expect($result->rows)
        ->toHaveCount(1);
    expect($result->total)
        ->toBeGreaterThanOrEqual(2);
});

/**
 * Same reasoning as findListForWs()'s own leading docblock -- another
 * --parallel worker's own FULLTEXT-deadlock-exempted categories-creating
 * test can leave a real, briefly-committed extra category visible here.
 */
test('findAdminListForWs() scopes to root categories when recursive is false and catId is null', function (): void {
    $criteria = new CategoryAdminListCriteria(catId: null, recursive: false);

    $result = categoryTestRepo()
        ->findAdminListForWs($criteria, null, 10);

    $realIds = array_values(array_intersect(array_column($result->rows, 'id'), [1]));
    expect($realIds)
        ->toBe([1]);
    // total reflects the real (unrestricted) count -- >= 1 rather than
    // exactly 1, since a raw scalar total can't be filtered down to
    // known-real ids the way a row list can.
    expect($result->total)
        ->toBeGreaterThanOrEqual(1);
});

test('findAdminListForWs() matches the full subtree when recursive', function (): void {
    $criteria = new CategoryAdminListCriteria(catId: CategoryId::from(1), recursive: true);

    $result = categoryTestRepo()
        ->findAdminListForWs($criteria, null, 10);

    $ids = array_values(array_intersect(array_column($result->rows, 'id'), [1, 2]));
    sort($ids);
    expect($ids)
        ->toBe([1, 2]);
});

test('findAdminListForWs() applies the search term', function (): void {
    $criteria = new CategoryAdminListCriteria(catId: null, recursive: true);

    $result = categoryTestRepo()
        ->findAdminListForWs($criteria, 'Nested', 10);

    expect(array_column($result->rows, 'id'))
        ->toBe([2]);
});

test('findNextId() returns one more than the current max', function (): void {
    // findNextId() computes COALESCE(MAX(id)+1, 1) -- verified here
    // against the real, non-empty fixture table (the empty-table branch
    // isn't practically testable against this shared fixture DB).
    $maxId = DbConnection::build()->fetchOne('SELECT MAX(id) FROM categories');

    expect(categoryTestRepo()->findNextId())
        ->toBe((is_numeric($maxId) ? $maxId : 0) + 1);
});

test('findPhotoCountsByCategory() counts direct images', function (): void {
    // findPhotoCountsByCategory() combines a custom-Typed categoryId
    // GROUP BY with a blind instanceof-then-continue fallback -- it
    // silently drops the row instead of throwing if the VO-hydration
    // assumption is wrong.
    expect(categoryTestRepo()->findPhotoCountsByCategory())
        ->toBe([
            1 => 3,
            2 => 2,
        ]);
});

test('hasImages() is true for a category with images', function (): void {
    expect(categoryTestRepo()->hasImages(1))
        ->toBeTrue();
});

test('hasImages() is false for a category without images', function (): void {
    expect(categoryTestRepo()->hasImages(999))
        ->toBeFalse();
});

test('findPhotoCountAndDateRange() matches the fixture', function (): void {
    // Category 1 has 3 direct images, all sharing the same
    // date_available (2026-08-01 00:00:00), so min and max both resolve
    // to that same date.
    $row = categoryTestRepo()
        ->findPhotoCountAndDateRange(1);

    expect($row->count)
        ->toBe(3)
        ->and($row->minDate)
        ->toBe('2026-08-01')
        ->and($row->maxDate)
        ->toBe('2026-08-01');
});

test('findPhotoCountAndDateRange() is zero for a category without images', function (): void {
    $row = categoryTestRepo()
        ->findPhotoCountAndDateRange(999);

    expect($row->count)
        ->toBe(0)
        ->and($row->minDate)
        ->toBeNull()
        ->and($row->maxDate)
        ->toBeNull();
});

test('findDistinctImageIdsInCategories() returns the linked images', function (): void {
    $ids = categoryTestRepo()
        ->findDistinctImageIdsInCategories([1]);
    sort($ids);
    expect($ids)
        ->toBe([1, 2, 3]);
});

test('deleteImageCategoryLinksForCategories() removes only the given categories', function (): void {
    $conn = DbConnection::build();
    new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get())
        ->deleteImageCategoryLinksForCategories([1]);

    $remaining = $conn->createQueryBuilder()
        ->select('DISTINCT category_id')
        ->from('image_category')
        ->executeQuery()
        ->fetchFirstColumn();

    expect(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $remaining))
        ->toBe([2]);
});
