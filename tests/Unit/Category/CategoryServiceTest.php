<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Event\DeleteSite;
use Piwigo\Category\Event\GetCategoryPreferredImageOrders;
use Piwigo\Category\Projection\CategoryInfo;
use Piwigo\Category\Projection\CategoryRepresentantProperties;
use Piwigo\Category\Projection\ImageOrderPreference;
use Piwigo\Category\Projection\RandomImageCategoryQuery;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Site\SiteEntity;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Category\CategoryServiceUnitTestFakeActivityLogger;
use Piwigo\Tests\Unit\Category\CategoryServiceUnitTestFakeHtmlRendererDeniesAccess;
use Piwigo\Tests\Unit\Category\CategoryServiceUnitTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;

/**
 * Piwigo\Category\CategoryService -- has its own dedicated
 * tests/Integration/CategoryServiceTest.php (64 tests); this ports them
 * down to the Unit suite via the real-DB-no-HTTP pattern. 710-line gap,
 * 881 lines, 19.40% covered, 0 existing Unit tests before this.
 *
 * Same fixture shape as CategoryRepositoryTest: category 1 "Sample
 * Album" (root, 3 direct images), category 2 "Nested Sub Album" (child
 * of 1, 2 direct images).
 *
 * Kernel::boot() IS needed here, for the whole file, unlike
 * CategoryRepositoryTest -- LangTestFactory::get() (CategoryService's
 * own first constructor arg) has no pre-boot fallback at all (its own
 * docblock: "Lang has required collaborators with no safe fake to fall
 * back to"), same reason PermalinkServiceTest.php/PermissionServiceTest.php
 * both already boot Kernel in their own beforeEach()/afterEach(). A
 * throwaway root per test, matching that established precedent.
 *
 * Same "own your row space" reasoning as CategoryRepositoryTest for
 * every state-mutating test: the Integration original's own tearDown()
 * does a blanket post-test reset (categories.status back to 'public',
 * old_permalinks.hit/last_hit) that's safe there (sequential suite, no
 * cross-file overlap) but not safe to copy into --parallel; every test
 * below restores what it touched in its own try/finally instead.
 */
function categoryServiceTestConn(): Connection
{
    return DbConnection::build();
}

/**
 * @return array{0: CategoryService, 1: CategoryRepository, 2: Connection}
 */
function categoryServiceTestServiceRepoConn(): array
{
    $conn = DbConnection::build();
    $currentConfig = CurrentConfigTestFactory::get();
    $repo = new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig);
    $filterState = new FilterState();
    $service = new CategoryService(
        LangTestFactory::get(),
        $repo,
        new PermissionService(
            new PermissionRepository(EntityManagerFactory::build($conn)),
            EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
            new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
            CurrentUserTestFactory::get(),
            $filterState,
            new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig)
        ),
        CurrentConfigTestFactory::get(),
        EventDispatcherTestFactory::get(),
        TranslatorTestFactory::get(),
        new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig),
        new UserRepository(EntityManagerFactory::build($conn), EventDispatcherTestFactory::get(), $currentConfig)
    );

    return [$service, $repo, $conn];
}

function categoryServiceTestService(): CategoryService
{
    return categoryServiceTestServiceRepoConn()[0];
}

/**
 * Same construction as categoryServiceTestServiceRepoConn(), but built
 * against a caller-supplied connection instead of a fresh one -- lets a
 * test thread one explicit connection through every collaborator, so
 * every raw-SQL mutation, the service's own writes, and every assertion
 * all happen on the one connection tests/Pest.php's own blanket per-test
 * transaction wraps and rolls back automatically. Used by every test
 * below that mutates category 1/2's own status/visible columns or
 * creates a root-level category -- CategoryRepositoryTest.php asserts on
 * exactly that shared state.
 *
 * @return array{0: CategoryService, 1: CategoryRepository}
 */
function categoryServiceTestServiceRepoForConn(Connection $conn): array
{
    $currentConfig = CurrentConfigTestFactory::get();
    $repo = new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig);
    $filterState = new FilterState();
    $service = new CategoryService(
        LangTestFactory::get(),
        $repo,
        new PermissionService(
            new PermissionRepository(EntityManagerFactory::build($conn)),
            EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
            new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
            CurrentUserTestFactory::get(),
            $filterState,
            new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig)
        ),
        CurrentConfigTestFactory::get(),
        EventDispatcherTestFactory::get(),
        TranslatorTestFactory::get(),
        new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig),
        new UserRepository(EntityManagerFactory::build($conn), EventDispatcherTestFactory::get(), $currentConfig)
    );

    return [$service, $repo];
}

function categoryServiceTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-categoryservice-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);

    return $root;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(categoryServiceTestRoot()));
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
    ]));
    CurrentConfigTestFactory::get()->rateEnabled = true;
});

afterEach(function (): void {
    Kernel::reset();
    CurrentConfigTestFactory::get()->reset();
    CurrentUserTestFactory::get()->reset();
    PageStateTestFactory::get()->reset();
});

test('compareByGlobalRank() orders naturally', function (): void {
    $rows = [
        [
            'global_rank' => '1.10',
        ],
        [
            'global_rank' => '1.2',
        ],
    ];
    usort($rows, CategoryService::compareByGlobalRank(...));

    expect($rows[0]['global_rank'])
        ->toBe('1.2');
});

test('compareByRank() orders numerically', function (): void {
    $rows = [
        [
            'rank' => 10,
        ],
        [
            'rank' => 2,
        ],
    ];
    usort($rows, CategoryService::compareByRank(...));

    expect($rows[0]['rank'])
        ->toBe(2);
});

test('getCategoryInfo() returns null for a missing category', function (): void {
    expect(categoryServiceTestService()->getCategoryInfo(999999))
        ->toBeNull();
});

test('getCategoryInfo() returns a single-level upperNames for a root category', function (): void {
    $info = categoryServiceTestService()
        ->getCategoryInfo(1);

    expect($info)
        ->not->toBeNull();
    expect($info?->upperNames)
        ->toBe([[
            'id' => 1,
            'name' => 'Sample Album',
            'permalink' => null,
        ]]);
});

test('getCategoryInfo() resolves upperNames for a nested category', function (): void {
    $info = categoryServiceTestService()
        ->getCategoryInfo(2);

    expect($info)
        ->not->toBeNull();
    if (! $info instanceof CategoryInfo) {
        throw new LogicException('unreachable -- asserted above');
    }
    $upperNames = $info->upperNames;
    expect($upperNames)
        ->toHaveCount(2);
    expect($upperNames[0]['name'])
        ->toBe('Sample Album')
        ->and($upperNames[1]['name'])
        ->toBe('Nested Sub Album');
});

test('getCategoryInfo() coerces true/false string columns to bool', function (): void {
    $info = categoryServiceTestService()
        ->getCategoryInfo(1);

    expect($info)
        ->not->toBeNull();
    expect($info?->visible)
        ->toBeTrue();
});

test('getPreferredImageOrders() returns the fixed option list', function (): void {
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
        'status' => 'normal',
    ]));

    $orders = categoryServiceTestService()
        ->getPreferredImageOrders();

    expect($orders)
        ->not->toBeEmpty();
    expect($orders[0]->orderBy)
        ->toBe('');
    // 'Permissions' (level DESC) is only visible to admins.
    $permissionsOrder = array_values(array_filter($orders, static fn (ImageOrderPreference $o): bool => $o->orderBy === 'level DESC'));
    expect($permissionsOrder[0]->visible)
        ->toBeFalse();
});

test('getPreferredImageOrders() Permissions option is visible to admin', function (): void {
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
        'status' => 'admin',
    ]));

    $orders = categoryServiceTestService()
        ->getPreferredImageOrders();

    $permissionsOrder = array_values(array_filter($orders, static fn (ImageOrderPreference $o): bool => $o->orderBy === 'level DESC'));
    expect($permissionsOrder[0]->visible)
        ->toBeTrue();
});

test('getSubcategoryIds() includes the category and its children', function (): void {
    $ids = categoryServiceTestService()
        ->getSubcategoryIds([1]);
    sort($ids);

    expect($ids)
        ->toBe([1, 2]);
});

test('findCategoryIdFromPermalinks() matches the last permalink first', function (): void {
    $conn = categoryServiceTestConn();

    try {
        $conn->executeStatement("UPDATE categories SET permalink = 'sample-album' WHERE id = 1");

        $idx = null;
        $catId = categoryServiceTestService()
            ->findCategoryIdFromPermalinks(['no-match', 'sample-album'], $idx, new PermalinkRepository(EntityManagerFactory::build($conn)));

        expect($catId)
            ->toBe(1)
            ->and($idx)
            ->toBe(1);
    } finally {
        $conn->executeStatement('UPDATE categories SET permalink = NULL WHERE id = 1');
    }
});

test('findCategoryIdFromPermalinks() returns null when nothing matches', function (): void {
    $idx = null;
    $catId = categoryServiceTestService()
        ->findCategoryIdFromPermalinks(['no-such-permalink'], $idx, new PermalinkRepository(EntityManagerFactory::build(categoryServiceTestConn())));

    expect($catId)
        ->toBeNull();
});

test('getDisplayImagesCount() reports photos and subalbums', function (): void {
    $text = CategoryService::getDisplayImagesCount(LangTestFactory::get(), 0, 5, 1, false, ' / ');

    expect($text)
        ->toContain('5')
        ->toContain('1');
});

test('getDisplayImagesCount() returns an empty string for zero images', function (): void {
    expect(CategoryService::getDisplayImagesCount(LangTestFactory::get(), 0, 0, 0))
        ->toBe('');
});

test('getRandomImageInCategory() returns null for an empty category', function (): void {
    expect(categoryServiceTestService()->getRandomImageInCategory(new RandomImageCategoryQuery(
        id: CategoryId::from(1),
        uppercats: '1',
        countImages: 0,
    )))
        ->toBeNull();
});

test('getRandomImageInCategory() returns an image id', function (): void {
    $imageId = categoryServiceTestService()
        ->getRandomImageInCategory(new RandomImageCategoryQuery(
            id: CategoryId::from(1),
            uppercats: '1',
            countImages: 3,
        ), false);

    expect($imageId)
        ->toBeIn([1, 2, 3]);
});

test('getComputedCategories() rolls up child counts into the parent', function (): void {
    $result = categoryServiceTestService()
        ->getComputedCategories(1, 0, '');
    $cats = $result['categories'];

    // category 1 (root) should count its own 3 images plus category
    // 2's 2 images in its subtree total.
    expect($cats[1]['count_images'])
        ->toBe(5)
        ->and($cats[1]['count_categories'])
        ->toBe(1)
        ->and($cats[2]['count_images'])
        ->toBe(2)
        ->and($result['lastPhotoDate'])
        ->not->toBeNull();
});

test('getComputedCategories() prunes categories with no recent activity', function (): void {
    // filterDays=0 against fixture dates in the past means nothing
    // qualifies as "recent" -- every category should be pruned.
    $result = categoryServiceTestService()
        ->getComputedCategories(1, 0, '', 0);

    expect($result['categories'])
        ->toBe([]);
});

test('removeComputedCategory() decrements parent counters', function (): void {
    $cats = [
        1 => [
            'cat_id' => 1,
            'id_uppercat' => null,
            'global_rank' => null,
            'rank' => null,
            'date_last' => null,
            'nb_images' => 3,
            'user_id' => 1,
            'nb_categories' => 1,
            'count_images' => 5,
            'count_categories' => 1,
            'max_date_last' => null,
        ],
        2 => [
            'cat_id' => 2,
            'id_uppercat' => 1,
            'global_rank' => null,
            'rank' => null,
            'date_last' => null,
            'nb_images' => 2,
            'user_id' => 1,
            'nb_categories' => 0,
            'count_images' => 2,
            'count_categories' => 0,
            'max_date_last' => null,
        ],
    ];

    CategoryService::removeComputedCategory($cats, $cats[2]);

    expect($cats)
        ->not->toHaveKey(2);
    expect($cats[1]['nb_categories'])
        ->toBe(0)
        ->and($cats[1]['count_images'])
        ->toBe(3)
        ->and($cats[1]['count_categories'])
        ->toBe(0);
});

test('getImageIdsForCategories() returns images', function (): void {
    $ids = categoryServiceTestService()
        ->getImageIdsForCategories([1], 'AND', false);
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('getCommonCategories() counts images per category', function (): void {
    $common = categoryServiceTestService()
        ->getCommonCategories([1, 2, 3], null, [], false);

    expect($common['1']['counter'])
        ->toBe(3);
});

test('getRelatedCategoriesMenu() sets count_images for directly linked categories', function (): void {
    $cats = categoryServiceTestService()
        ->getRelatedCategoriesMenu([1, 2, 3], []);

    $byId = [];
    foreach ($cats as $cat) {
        $catId = $cat['id'];
        if (is_int($catId) || is_string($catId)) {
            $byId[$catId] = $cat;
        }
    }

    expect($byId['1']['count_images'])
        ->toBe(3);
    expect($byId['1'])
        ->toHaveKey('LEVEL');
});

test('getRelatedCategoriesMenu() returns empty for no items', function (): void {
    expect(categoryServiceTestService()->getRelatedCategoriesMenu([], []))
        ->toBe([]);
});

/**
 * Matches getuserdata()'s own guaranteed shape (include/functions_user.inc.php):
 * 'forbidden_categories' always at least '0', 'image_access_type' always
 * the literal 'NOT IN', 'level' always a numeric string -- an incomplete
 * fixture (e.g. missing 'level') lets getSqlConditionFandF()'s
 * forbidden_images fallthrough build a malformed 'level <=' fragment
 * with no right-hand value, a state that can't happen in production.
 *
 * @return array<string, mixed>
 */
function categoryServiceTestRealisticUserGlobal(): array
{
    return [
        'id' => 1,
        'forbidden_categories' => '0',
        'level' => '0',
        'image_access_type' => 'NOT IN',
        'image_access_list' => '',
    ];
}

test('getImageIdsForCategories() with permissions builds valid SQL', function (): void {
    // exercising usePermissions=true with a genuinely non-empty
    // condition is what catches the andWhere() double-AND-wrap bug
    // that an empty (guest-default) CurrentUser silently skips.
    CurrentUserTestFactory::get()->set(User::fromUserArray(categoryServiceTestRealisticUserGlobal()));

    $ids = categoryServiceTestService()
        ->getImageIdsForCategories([1], 'AND', true);
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('getCommonCategories() with permissions builds valid SQL', function (): void {
    CurrentUserTestFactory::get()->set(User::fromUserArray(categoryServiceTestRealisticUserGlobal()));

    $common = categoryServiceTestService()
        ->getCommonCategories([1, 2, 3], null, [4, 5], true);

    expect($common['1']['counter'])
        ->toBe(3);
});

test('getRelatedCategoriesMenu() with permissions builds valid SQL', function (): void {
    // getRelatedCategoriesMenu() always calls getCommonCategories() with
    // usePermissions defaulted to true internally -- the real
    // menubar.inc.php code path.
    CurrentUserTestFactory::get()->set(User::fromUserArray(categoryServiceTestRealisticUserGlobal()));

    $cats = categoryServiceTestService()
        ->getRelatedCategoriesMenu([1, 2, 3], []);

    expect($cats)
        ->not->toBe([]);
});

test('getPreferredImageOrders() skips a malformed entry from the event handler', function (): void {
    EventDispatcherTestFactory::get()->addTypedHandler(
        GetCategoryPreferredImageOrders::class,
        // Missing the 3rd (visibility) element -- malformed, must be
        // skipped rather than crash on an undefined offset.
        static function (GetCategoryPreferredImageOrders $event): void {
            $event->orders = [
                ['Only Two Elements', 'name ASC'],
                ['Real Order', 'hit DESC', true],
            ];
        }
    );

    try {
        $orders = categoryServiceTestService()
            ->getPreferredImageOrders();
    } finally {
        EventDispatcherTestFactory::get()->reset();
    }

    expect($orders)
        ->toEqual([new ImageOrderPreference('Real Order', 'hit DESC', true)]);
});

test('findCategoryIdFromPermalinks() skips a non-match before finding an older match', function (): void {
    // Checked from the end first: 'no-such-permalink' misses (continue),
    // then 'old-sample-album' (the fixture's real old_permalinks row,
    // cat_id=1) matches -- also proves the is_old branch touches
    // touchOldPermalinkHit()'s hit counter.
    $conn = categoryServiceTestConn();

    try {
        $idx = null;
        $catId = categoryServiceTestService()
            ->findCategoryIdFromPermalinks(['old-sample-album', 'no-such-permalink'], $idx, new PermalinkRepository(EntityManagerFactory::build($conn)));

        expect($catId)
            ->toBe(1)
            ->and($idx)
            ->toBe(0);

        $hit = $conn->createQueryBuilder()
            ->select('hit')
            ->from('old_permalinks')
            ->where("permalink = 'old-sample-album'")
            ->executeQuery()
            ->fetchOne();
        expect(is_numeric($hit) ? (int) $hit : null)
            ->toBe(43);
    } finally {
        $conn->executeStatement("UPDATE old_permalinks SET hit = 42, last_hit = '2026-07-07 05:02:38'");
    }
});

test('findCategoryIdFromPermalinks() returns null when the SQL match has no exact string key', function (): void {
    // MySQL's default VARCHAR comparison ignores trailing whitespace
    // (PAD SPACE semantics) -- a permalink string with trailing
    // whitespace therefore SQL-matches findPermalinkMatches()'s own
    // WHERE...IN() clause (populating $permaHash with the *stored*,
    // unpadded key) but fails this method's own exact-string isset()
    // lookup against the very padded string it queried with. The real,
    // narrow gap this defensive tail return guards.
    $idx = null;
    $catId = categoryServiceTestService()
        ->findCategoryIdFromPermalinks(['old-sample-album '], $idx, new PermalinkRepository(EntityManagerFactory::build(categoryServiceTestConn())));

    expect($catId)
        ->toBeNull();
    expect($idx)
        ->toBeNull();
});

test('getComputedCategories() walks up through more than one ancestor level', function (): void {
    // `rank` is a genuine reserved word on both platforms (a bare
    // backtick is MySQL-only); visible/commentable are genuine boolean
    // columns (a bare `1` literal is rejected outright by Postgres).
    //
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // `categories` carries a FULLTEXT index (categories_ft_name_comment),
    // and InnoDB's FULLTEXT auxiliary-index maintenance on INSERT holds
    // internal locks that, under the wrapper's whole-test-duration
    // transaction, can deadlock against another --parallel worker's own
    // concurrent categories INSERT -- same mechanism, same fix, as
    // TagServiceTest.php's 'getTagIds() creates a new tag for a plain
    // name when allowed' (reproduced live there: DeadlockException).
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $grandchildId = null;

    try {
        $service = categoryServiceTestServiceRepoForConn($conn)[0];
        $rank = $conn->getDatabasePlatform()
            ->quoteSingleIdentifier('rank');
        $trueLiteral = getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? 'true' : '1';
        $conn->executeStatement(
            "INSERT INTO categories (name, id_uppercat, {$rank}, status, visible, uppercats, commentable, global_rank) VALUES ('Grandchild Album', 2, 1, 'public', {$trueLiteral}, '1,2,0', {$trueLiteral}, '1.1.1')"
        );
        $grandchildId = (int) $conn->lastInsertId();
        $conn->executeStatement("UPDATE categories SET uppercats = '1,2,{$grandchildId}' WHERE id = {$grandchildId}");
        // Reuse image 1 (already directly in category 1) as a second,
        // additional link into the new grandchild -- proves the walk
        // propagates a real image count up two ancestor levels, not
        // just one.
        $conn->executeStatement("INSERT INTO image_category (image_id, category_id) VALUES (1, {$grandchildId})");

        $result = $service
            ->getComputedCategories(1, 0, '');
        $cats = $result['categories'];

        expect($cats[$grandchildId]['count_images'])
            ->toBe(1)
            ->and($cats[2]['count_images'])
            ->toBe(3) // own 2 + grandchild's 1
            ->and($cats[2]['count_categories'])
            ->toBe(1)
            ->and($cats[1]['count_images'])
            ->toBe(6) // own 3 + cat2's 2 + grandchild's 1
            ->and($cats[1]['count_categories'])
            ->toBe(2); // cat2 + grandchild
    } finally {
        if ($grandchildId !== null) {
            $conn->executeStatement('DELETE FROM image_category WHERE category_id = ?', [$grandchildId]);
            $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$grandchildId]);
        }
    }
});

test('getRelatedCategoriesMenu() skips a stale ancestor id in a corrupted uppercats path', function (): void {
    // Simulates category 2's uppercats containing a stale ancestor id
    // (999) that findCategoriesByIds() can't resolve (no such category)
    // -- the real ancestor (1) must still get its count_categories
    // incremented despite the phantom one.
    $conn = categoryServiceTestConn();

    try {
        $conn->executeStatement("UPDATE categories SET uppercats = '1,999,2' WHERE id = 2");

        $cats = categoryServiceTestService()
            ->getRelatedCategoriesMenu([4], []);

        $byId = [];
        foreach ($cats as $cat) {
            $catId = $cat['id'];
            if (is_int($catId) || is_string($catId)) {
                $byId[$catId] = $cat;
            }
        }

        expect($byId['1']['count_categories'])
            ->toBe(1);
    } finally {
        $conn->executeStatement("UPDATE categories SET uppercats = '1,2' WHERE id = 2");
    }
});

test('checkRestrictions() denies access to a forbidden category', function (): void {
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
        'forbidden_categories' => '2,5',
    ]));

    expect(fn () => categoryServiceTestService()->checkRestrictions(2, new CategoryServiceUnitTestFakeHtmlRendererDeniesAccess(), new CategoryServiceUnitTestFakeRedirectServiceNeverCalled(), CurrentUserTestFactory::get()))
        ->toThrow(RuntimeException::class, 'CATEGORY_SERVICE_ACCESS_DENIED_MARKER');
});

test('getSubcatIds() warns and skips a non-numeric id', function (): void {
    $captured = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
        $captured = $errstr;

        return true;
    });
    try {
        $ids = categoryServiceTestService()
            ->getSubcatIds([1, 'not-a-number']);
    } finally {
        restore_error_handler();
    }
    sort($ids);

    expect($ids)
        ->toBe([1, 2]);
    expect($captured)
        ->toBe('getSubcatIds expecting numeric, not string');
});

test('getRelatedCategoriesMenuWithUrls() skips a row with no count_images', function (): void {
    // image 4 is only directly linked to category 2 -- category 1 (a
    // pure ancestor, reached only via cat2's own uppercats path) never
    // gets a 'count_images' key set. The loop's own isset() guard
    // "skips" building a 'url' for it, but the category itself stays in
    // the returned list.
    $cats = categoryServiceTestService()
        ->getRelatedCategoriesMenuWithUrls([4], UrlServiceTestFactory::build());

    $ids = array_map(static fn (array $c): mixed => $c['id'], $cats);
    expect($ids)
        ->toContain(1)
        ->toContain(2);

    $catsById = [];
    foreach ($cats as $cat) {
        $catId = $cat['id'];
        if (is_int($catId) || is_string($catId)) {
            $catsById[$catId] = $cat;
        }
    }
    expect($catsById[1])
        ->not->toHaveKey('url');
    expect($catsById[2])
        ->toHaveKey('url');
});

test('getRelatedCategoriesMenuWithUrls() merges combinedCategories into the url', function (): void {
    $urlService = UrlServiceTestFactory::build();
    $priorCat = [
        'id' => 99,
        'name' => 'Prior Combined Category',
        'permalink' => null,
    ];
    $pageCategory = [
        'id' => 1,
        'name' => 'Sample Album',
        'permalink' => null,
    ];

    $cats = categoryServiceTestService()
        ->getRelatedCategoriesMenuWithUrls(
            [4],
            $urlService,
            [],
            $pageCategory,
            [$priorCat]
        );

    $byId = [];
    foreach ($cats as $cat) {
        $catId = $cat['id'];
        if (is_int($catId) || is_string($catId)) {
            $byId[$catId] = $cat;
        }
    }

    // Same real UrlService::makeIndexUrl() call the production code
    // itself makes -- proves $combinedCategories was actually merged in
    // (not just replaced) rather than asserting a loose substring.
    $expectedUrl = $urlService->makeIndexUrl([
        'category' => $pageCategory,
        'combined_categories' => [$priorCat, $byId[2]],
    ]);

    expect($byId[2]['url'])
        ->toBe($expectedUrl);
});

test('deleteCategories() delete_orphans mode preserves an image still linked elsewhere', function (): void {
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // createVirtualCategory() below INSERTs a `categories` row, and
    // `categories` carries a FULLTEXT index (categories_ft_name_comment)
    // whose auxiliary-index maintenance can deadlock against another
    // --parallel worker's own concurrent categories INSERT when held
    // open for a whole test's duration -- same mechanism, same fix, as
    // TagServiceTest.php's 'getTagIds() creates a new tag for a plain
    // name when allowed' (reproduced live there: DeadlockException).
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $tempId = null;

    try {
        [$service, $repo] = categoryServiceTestServiceRepoForConn($conn);
        $activityLogger = new CategoryServiceUnitTestFakeActivityLogger();
        $urlService = UrlServiceTestFactory::build();

        // 'last' position -- createVirtualCategory()'s own internal
        // updateGlobalRank() call renumbers EVERY root category
        // sequentially by (rank, name); a default-position (rank 0)
        // temp category would sort ahead of real fixture category 1
        // (rank 1) and displace its own rank to 2 for as long as this
        // temp category exists, visible to any other test reading it in
        // that window. 'last' appends after category 1 instead, leaving
        // its rank untouched.
        CurrentConfigTestFactory::get()->newcatDefaultPosition = 'last';
        $result = $service->createVirtualCategory('Orphan Diff Temp', $activityLogger, CurrentUserTestFactory::get(), EntityManagerFactory::build($conn));
        $tempIdRaw = $result->id;
        expect(is_numeric($tempIdRaw))
            ->toBeTrue();
        $tempId = (int) $tempIdRaw;

        // image 2 already belongs to category 1 (not image 1 -- avoids a
        // real InnoDB deadlock against CategoryRepositoryTest.php's own
        // transaction-wrapped updateImagePathsForCategory() test, which
        // specifically needs image 1; confirmed live via a 15-run
        // --parallel verification loop) -- linking it into the temp
        // category too makes it a real "non-orphan": deleting the temp
        // category must NOT delete it, unlike a genuinely orphaned image.
        $conn->executeStatement("INSERT INTO image_category (image_id, category_id) VALUES (2, {$tempId})");

        $service->deleteCategories([$tempId], $activityLogger, $urlService, new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()), EventDispatcherTestFactory::get(), EntityManagerFactory::build($conn), 'delete_orphans');

        expect($repo->findById($tempId))
            ->toBeNull();
        $stillLinked = $conn->createQueryBuilder()
            ->select('COUNT(*) AS c')
            ->from('image_category')
            ->where('image_id = 2 AND category_id = 1')
            ->executeQuery()
            ->fetchOne();
        expect(is_numeric($stillLinked) ? (int) $stillLinked : null)
            ->toBe(1);
    } finally {
        if ($tempId !== null) {
            $conn->executeStatement('DELETE FROM image_category WHERE category_id = ?', [$tempId]);
            $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$tempId]);
        }

        CurrentConfigTestFactory::get()->newcatDefaultPosition = 'first';
    }
});

test('deleteSite() deletes the site\'s categories and dispatches DeleteSite for the row itself', function (): void {
    // Category can't depend on Site directly (a real deptrac boundary),
    // so the site's own `sites` row is deleted by a real listener on
    // DeleteSite instead of a direct CategoryRepository call -- this
    // registers the SAME listener shape RequestBootstrap.php itself
    // registers in production, to prove the wiring (not just the
    // individual pieces) actually works end to end.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // createVirtualCategory() below INSERTs a `categories` row, and
    // `categories` carries a FULLTEXT index (categories_ft_name_comment)
    // whose auxiliary-index maintenance can deadlock against another
    // --parallel worker's own concurrent categories INSERT when held
    // open for a whole test's duration -- same mechanism, same fix, as
    // TagServiceTest.php's 'getTagIds() creates a new tag for a plain
    // name when allowed' (reproduced live there: DeadlockException).
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $categoryId = null;
    $siteId = null;

    try {
        [$service, $repo] = categoryServiceTestServiceRepoForConn($conn);
        $siteRepo = EntityManagerFactory::build($conn)->getRepository(SiteEntity::class);
        $siteUrl = 'p17-test-delete-site-' . bin2hex(random_bytes(4));
        $siteRepo->insert($siteUrl);
        // lastInsertId() isn't reliable straight after an ORM persist()+
        // flush() -- read the id back with a plain SELECT instead.
        $rawSiteId = $conn->createQueryBuilder()
            ->select('id')
            ->from('sites')
            ->where('galleries_url = :url')
            ->setParameter('url', $siteUrl)
            ->executeQuery()
            ->fetchOne();
        expect(is_numeric($rawSiteId))
            ->toBeTrue();
        $siteId = is_numeric($rawSiteId) ? (int) $rawSiteId : 0;

        // 'last' position -- see 'deleteCategories() delete_orphans...'
        // above for why (avoids displacing category 1's own real rank).
        CurrentConfigTestFactory::get()->newcatDefaultPosition = 'last';
        $categoryId = $service->createVirtualCategory('Site Delete Temp', new CategoryServiceUnitTestFakeActivityLogger(), CurrentUserTestFactory::get(), EntityManagerFactory::build($conn))->id;
        expect(is_numeric($categoryId))
            ->toBeTrue();
        $conn->executeStatement('UPDATE categories SET site_id = ? WHERE id = ?', [$siteId, $categoryId]);

        $handler = static function (DeleteSite $e) use ($siteRepo): void {
            $siteRepo->delete($e->siteId);
        };
        EventDispatcherTestFactory::get()->addTypedHandler(DeleteSite::class, $handler);

        try {
            $service->deleteSite($siteId, new CategoryServiceUnitTestFakeActivityLogger(), UrlServiceTestFactory::build(), new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()), EventDispatcherTestFactory::get(), EntityManagerFactory::build($conn));

            expect($repo->findById((int) $categoryId))
                ->toBeNull();
            expect($siteRepo->findGalleriesUrlById($siteId))
                ->toBeNull();
        } finally {
            EventDispatcherTestFactory::get()->removeTypedHandler(DeleteSite::class, $handler);
        }
    } finally {
        if ($categoryId !== null) {
            $conn->executeStatement('DELETE FROM categories WHERE id = ?', [(int) $categoryId]);
        }

        if ($siteId !== null) {
            $conn->executeStatement('DELETE FROM sites WHERE id = ?', [$siteId]);
        }

        CurrentConfigTestFactory::get()->newcatDefaultPosition = 'first';
    }
});

test('checkCategoriesIntegrity() deletes orphaned image_category/user_access/group_access rows', function (): void {
    // findOrphanedColumnValues()/deleteRowsWhereColumnIn() use real DQL
    // for all 3 of CategoryOrphanTarget's cases. All 3 target tables
    // carry a real ON DELETE CASCADE FK on the category-id column, so a
    // genuine orphan can never arise through normal writes -- disabling
    // FK checks just for these inserts reproduces the only real way this
    // state has ever existed in practice.
    $conn = categoryServiceTestConn();
    $conn->executeStatement(getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? 'SET session_replication_role = replica' : 'SET FOREIGN_KEY_CHECKS=0');
    $conn->executeStatement('INSERT INTO image_category (image_id, category_id) VALUES (1, 60000)');
    $conn->executeStatement('INSERT INTO user_access (user_id, cat_id) VALUES (1, 60000)');
    $conn->executeStatement('INSERT INTO group_access (group_id, cat_id) VALUES (1, 60000)');
    $conn->executeStatement(getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? 'SET session_replication_role = DEFAULT' : 'SET FOREIGN_KEY_CHECKS=1');

    try {
        categoryServiceTestService()->checkCategoriesIntegrity(new PermalinkRepository(EntityManagerFactory::build($conn)));

        $orphanedImageCategoryCount = $conn->createQueryBuilder()
            ->select('COUNT(*) AS c')
            ->from('image_category')
            ->where('image_id = 1 AND category_id = 60000')
            ->executeQuery()
            ->fetchOne();
        expect(is_numeric($orphanedImageCategoryCount) ? (int) $orphanedImageCategoryCount : null)
            ->toBe(0);

        $orphanedUserAccessCount = $conn->createQueryBuilder()
            ->select('COUNT(*) AS c')
            ->from('user_access')
            ->where('user_id = 1 AND cat_id = 60000')
            ->executeQuery()
            ->fetchOne();
        expect(is_numeric($orphanedUserAccessCount) ? (int) $orphanedUserAccessCount : null)
            ->toBe(0);

        $orphanedGroupAccessCount = $conn->createQueryBuilder()
            ->select('COUNT(*) AS c')
            ->from('group_access')
            ->where('group_id = 1 AND cat_id = 60000')
            ->executeQuery()
            ->fetchOne();
        expect(is_numeric($orphanedGroupAccessCount) ? (int) $orphanedGroupAccessCount : null)
            ->toBe(0);

        // A real, non-orphaned image_category row (fixture image 1 in
        // fixture category 1) survives untouched.
        $realImageCategoryCount = $conn->createQueryBuilder()
            ->select('COUNT(*) AS c')
            ->from('image_category')
            ->where('image_id = 1 AND category_id = 1')
            ->executeQuery()
            ->fetchOne();
        expect(is_numeric($realImageCategoryCount) ? (int) $realImageCategoryCount : null)
            ->toBe(1);
    } finally {
        $conn->executeStatement('DELETE FROM image_category WHERE image_id = 1 AND category_id = 60000');
        $conn->executeStatement('DELETE FROM user_access WHERE user_id = 1 AND cat_id = 60000');
        $conn->executeStatement('DELETE FROM group_access WHERE group_id = 1 AND cat_id = 60000');
    }
});

test('checkCategoriesIntegrity() deletes an orphaned old_permalinks row and keeps a real one', function (): void {
    // fk_old_permalinks_cat_id now makes this orphan impossible to create
    // through normal writes, which is the point of the constraint. The
    // integrity check still has a narrow job: a dump restored with
    // referential checks disabled -- the default for mysqldump output --
    // can reintroduce one, and that is exactly how this fixture is built
    // here. Without disabling the checks the INSERT is rejected outright.
    $conn = categoryServiceTestConn();
    $isPostgres = $conn->getDatabasePlatform() instanceof PostgreSQLPlatform;
    $conn->executeStatement($isPostgres ? "SET session_replication_role = 'replica'" : 'SET FOREIGN_KEY_CHECKS = 0');

    try {
        $conn->executeStatement(
            "INSERT INTO old_permalinks (cat_id, permalink, hit) VALUES (60000, 'orphaned-permalink-test', 0)"
        );
    } finally {
        $conn->executeStatement($isPostgres ? "SET session_replication_role = 'origin'" : 'SET FOREIGN_KEY_CHECKS = 1');
    }

    categoryServiceTestService()
        ->checkCategoriesIntegrity(new PermalinkRepository(EntityManagerFactory::build($conn)));

    $orphanCount = $conn->createQueryBuilder()
        ->select('COUNT(*) AS c')
        ->from('old_permalinks')
        ->where("permalink = 'orphaned-permalink-test'")
        ->executeQuery()
        ->fetchOne();
    expect(is_numeric($orphanCount) ? (int) $orphanCount : null)
        ->toBe(0);

    $realCount = $conn->createQueryBuilder()
        ->select('COUNT(*) AS c')
        ->from('old_permalinks')
        ->where("permalink = 'old-sample-album'")
        ->executeQuery()
        ->fetchOne();
    expect(is_numeric($realCount) ? (int) $realCount : null)
        ->toBe(1);
});

test('updateGlobalRank() replaces a stale uppercats segment with an empty string', function (): void {
    // Simulates a category whose uppercats column still references an
    // ancestor id that no longer exists (deleted without a full
    // uppercats resync) -- catMapCallback()'s own defensive '' fallback
    // for an unmatched captured id.
    $conn = categoryServiceTestConn();

    try {
        $conn->executeStatement("UPDATE categories SET uppercats = '1,999,2' WHERE id = 2");

        categoryServiceTestService()
            ->updateGlobalRank();

        $globalRank = $conn->createQueryBuilder()
            ->select('global_rank')
            ->from('categories')
            ->where('id = 2')
            ->executeQuery()
            ->fetchOne();
        // '1,999,2' -> '1.999.2' with each digit run replaced by that
        // category's own freshly-computed rank -- '1' and '2' resolve
        // normally (both rank 1 in this fixture), '999' resolves to ''
        // (no such category), leaving a visibly empty segment rather
        // than a crash.
        expect($globalRank)
            ->toBe('1..1');
    } finally {
        $conn->executeStatement("UPDATE categories SET uppercats = '1,2', global_rank = '1.1' WHERE id = 2");
    }
});

test('setCatVisible() warns and returns false for an invalid value', function (): void {
    $captured = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
        $captured = $errstr;

        return true;
    });
    try {
        $result = categoryServiceTestService()
            ->setCatVisible([1], 'not-a-boolean');
    } finally {
        restore_error_handler();
    }

    expect($result)
        ->toBeFalse();
    expect($captured)
        ->toBe('setCatVisible invalid param not-a-boolean');
});

test('setCatStatus() warns and returns false for an invalid value', function (): void {
    $captured = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
        $captured = $errstr;

        return true;
    });
    try {
        $result = categoryServiceTestService()
            ->setCatStatus([1], 'archived', EntityManagerFactory::build(DbConnection::build()));
    } finally {
        restore_error_handler();
    }

    expect($result)
        ->toBeFalse();
    expect($captured)
        ->toBe('setCatStatus invalid param archived');
});

test('setCatStatus() public makes parent categories public too', function (): void {
    $conn = DbConnection::build();
    [$service, $repo] = categoryServiceTestServiceRepoForConn($conn);
    $conn->executeStatement("UPDATE categories SET status = 'private'");

    $service->setCatStatus([2], 'public', EntityManagerFactory::build($conn));

    expect($repo->findCategoryStatus(1))
        ->toBe('public')
        ->and($repo->findCategoryStatus(2))
        ->toBe('public');
});

test('setCatStatus() private uses the private parent as the permission reference', function (): void {
    $conn = DbConnection::build();
    [$service] = categoryServiceTestServiceRepoForConn($conn);
    $conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");
    $conn->executeStatement('INSERT INTO user_access (user_id, cat_id) VALUES (3, 2)');

    $service->setCatStatus([2], 'private', EntityManagerFactory::build($conn));

    // category 1 (the reference, since it's already private) grants
    // no direct user access at all -- the inconsistent-access sweep
    // must fall back to the sentinel (-1) keep-list and remove
    // category 2's own now-inconsistent user_access row, proving the
    // *parent* was used as the reference rather than category 2
    // itself.
    $remaining = $conn->createQueryBuilder()
        ->select('COUNT(*) AS c')
        ->from('user_access')
        ->where('cat_id = 2')
        ->executeQuery()
        ->fetchOne();
    expect(is_numeric($remaining) ? (int) $remaining : null)
        ->toBe(0);
});

test('setCatStatus() private removes inconsistent group_access too', function (): void {
    // deleteInconsistentAccess() handles CategoryAccessTarget::GroupAccess
    // via real DQL, alongside UserAccess -- same code path as the
    // sibling test above. A throwaway new group, not a real fixture one
    // -- the fixture's own groups 1-3 already have real access to
    // category 1 (the "reference"), which would make it the *consistent*
    // case this test isn't trying to cover.
    $conn = DbConnection::build();
    [$service] = categoryServiceTestServiceRepoForConn($conn);
    $groupsTable = $conn->getDatabasePlatform()
        ->quoteSingleIdentifier('groups');
    $conn->executeStatement("INSERT INTO {$groupsTable} (name) VALUES ('zzz-p17-unit-probe-group')");
    $groupId = (int) $conn->lastInsertId();
    $conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");
    $conn->executeStatement('INSERT INTO group_access (group_id, cat_id) VALUES (?, 2)', [$groupId]);

    $service->setCatStatus([2], 'private', EntityManagerFactory::build($conn));

    $remaining = $conn->createQueryBuilder()
        ->select('COUNT(*) AS c')
        ->from('group_access')
        ->where('cat_id = 2 AND group_id = :groupId')
        ->setParameter('groupId', $groupId)
        ->executeQuery()
        ->fetchOne();
    expect(is_numeric($remaining) ? (int) $remaining : null)
        ->toBe(0);
});

test('getCategoryRepresentantProperties() throws for a missing image', function (): void {
    // DerivativeImage::urlService() (called internally) throws
    // unconditionally when Kernel isn't booted -- confirmed by reading
    // its source. Kernel is already booted file-wide (see this file's
    // own top docblock for why).
    expect(fn (): CategoryRepresentantProperties => categoryServiceTestService()->getCategoryRepresentantProperties(999999, UrlServiceTestFactory::build(), EntityManagerFactory::build(DbConnection::build())))
        ->toThrow(Exception::class, 'getCategoryRepresentantProperties(): image 999999 does not exist (stale representative_picture_id?)');
});

test('getCategoryRepresentantProperties() returns a thumb url when size is null', function (): void {
    $urlService = UrlServiceTestFactory::build();

    $props = categoryServiceTestService()
        ->getCategoryRepresentantProperties(1, $urlService, EntityManagerFactory::build(DbConnection::build()));

    expect($props->url)
        ->toBe($urlService->getRootUrl() . 'admin.php?page=photo-1');
});

test('updatePath() rewrites image paths for storage-linked categories', function (): void {
    $conn = categoryServiceTestConn();

    try {
        $conn->executeStatement("UPDATE categories SET dir = 'sample-album', site_id = 1 WHERE id = 1");
        $conn->executeStatement('UPDATE images SET storage_category_id = 1 WHERE id = 1');

        categoryServiceTestService()
            ->updatePath(EntityManagerFactory::build($conn)->getRepository(SiteEntity::class));

        $path = $conn->createQueryBuilder()
            ->select('path')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();
        // The real project root, matching what FixtureNormalizer/
        // reimport-fixture.sh already normalized site 1's own
        // galleries_url to at fixture-load time -- not a Kernel-resolved
        // Paths instance, avoiding a needless Kernel::boot() for this test.
        $realRoot = dirname(__DIR__, 3) . '/';
        expect($path)
            ->toBe($realRoot . 'galleries/sample-album/fixture-photo-1.jpg');
    } finally {
        $conn->executeStatement('UPDATE categories SET dir = NULL, site_id = NULL WHERE id = 1');
        $realHash = getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? '2e7e2251' : '2e7e3a53';
        $conn->executeStatement(
            "UPDATE images SET storage_category_id = NULL, path = 'upload/2026/08/01/20260801000000-{$realHash}.jpg' WHERE id = 1"
        );
    }
});

test('moveCategories() rejects moving a category into its own sub album', function (): void {
    $activityLogger = new CategoryServiceUnitTestFakeActivityLogger();

    categoryServiceTestService()
        ->moveCategories([1], $activityLogger, PageStateTestFactory::get(), EntityManagerFactory::build(DbConnection::build()), 2);

    expect(PageStateTestFactory::get()->errors)
        ->toContain('You cannot move an album in its own sub album');
    // the move must not have actually happened.
    $idUppercat = categoryServiceTestConn()
        ->createQueryBuilder()
        ->select('id_uppercat')
        ->from('categories')
        ->where('id = 1')
        ->executeQuery()
        ->fetchOne();
    expect($idUppercat)
        ->toBeNull();
});

test('createVirtualCategory() returns an error when the parent does not exist', function (): void {
    $result = categoryServiceTestService()
        ->createVirtualCategory('Orphan Parent Test', new CategoryServiceUnitTestFakeActivityLogger(), CurrentUserTestFactory::get(), EntityManagerFactory::build(DbConnection::build()), 999999);

    expect($result->error)
        ->toBe('The parent album does not exist');
});

test('createVirtualCategory() inherits invisibility from an invisible parent', function (): void {
    // visible is a genuine boolean column -- bare `0`/`1` literals in the
    // SQL text are rejected outright by Postgres.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // createVirtualCategory() below INSERTs a `categories` row, and
    // `categories` carries a FULLTEXT index (categories_ft_name_comment)
    // whose auxiliary-index maintenance can deadlock against another
    // --parallel worker's own concurrent categories INSERT when held
    // open for a whole test's duration -- same mechanism, same fix, as
    // TagServiceTest.php's 'getTagIds() creates a new tag for a plain
    // name when allowed' (reproduced live there: DeadlockException).
    //
    // A disposable, already-invisible parent -- not real fixture
    // category 1 -- rather than flipping category 1's own visible
    // column, which CategoryRepositoryTest.php's own findByVisible()
    // exact-list assertion (both categories visible) can observe for
    // the whole span this real commit is live before this test's own
    // cleanup runs (confirmed live via a 15-run --parallel verification
    // loop, the same class of leak the parent's own rank needed 'last'
    // position for below).
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $parentId = null;
    $newId = null;

    try {
        $service = categoryServiceTestServiceRepoForConn($conn)[0];
        $activityLogger = new CategoryServiceUnitTestFakeActivityLogger();

        // 'last' position -- createVirtualCategory()'s own internal
        // updateGlobalRank() call renumbers EVERY root category
        // sequentially by (rank, name); a default-position (rank 0)
        // parent would sort ahead of real fixture category 1 (rank 1)
        // and displace its own rank for as long as this parent exists.
        CurrentConfigTestFactory::get()->newcatDefaultPosition = 'last';
        $parentResult = $service->createVirtualCategory('ct_invisible_parent_' . uniqid(), $activityLogger, CurrentUserTestFactory::get(), EntityManagerFactory::build($conn), null, [
            'visible' => false,
        ]);
        expect(is_numeric($parentResult->id))
            ->toBeTrue();
        $parentId = (int) $parentResult->id;

        $result = $service->createVirtualCategory('Invisible Child Test', $activityLogger, CurrentUserTestFactory::get(), EntityManagerFactory::build($conn), $parentId);
        $newIdRaw = $result->id;
        expect(is_numeric($newIdRaw))
            ->toBeTrue();
        $newId = (int) $newIdRaw;

        $visible = $conn->createQueryBuilder()
            ->select('visible')
            ->from('categories')
            ->where('id = ' . $newId)
            ->executeQuery()
            ->fetchOne();
        // A native PHP bool on Postgres, numeric 1/0 on MySQL.
        expect((int) (bool) $visible)
            ->toBe(0);
    } finally {
        if ($newId !== null) {
            $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$newId]);
        }

        if ($parentId !== null) {
            $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$parentId]);
        }

        CurrentConfigTestFactory::get()->newcatDefaultPosition = 'first';
    }
});

test('createVirtualCategory() with inherit propagates the parent\'s groups and users', function (): void {
    // User 4 (power_user), not user 3 -- CategoryRepositoryTest.php's own
    // findPrivateCategoriesGrantedToUser() test uses the exact same
    // (user_id=3, cat_id=1) user_access pair.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // createVirtualCategory() below INSERTs a `categories` row, and
    // `categories` carries a FULLTEXT index (categories_ft_name_comment)
    // whose auxiliary-index maintenance can deadlock against another
    // --parallel worker's own concurrent categories INSERT when held
    // open for a whole test's duration -- same mechanism, same fix, as
    // TagServiceTest.php's 'getTagIds() creates a new tag for a plain
    // name when allowed' (reproduced live there: DeadlockException).
    //
    // A disposable, already-private parent carrying its own explicit
    // group_access (mirroring category 1's real 1/2/3 grants) and
    // user_access grants -- not real fixture category 1 -- rather than
    // flipping category 1's own status column and adding a user_access
    // row on it directly, both of which CategoryRepositoryTest.php's own
    // findAvailableList(publicOnly)/findPrivateCategoriesGrantedToUser()
    // exact assertions can observe for the whole span this real commit
    // is live before this test's own cleanup runs (confirmed live via a
    // 15-run --parallel verification loop). Proves the exact same
    // inherit-copy behavior without ever touching category 1 itself.
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $parentId = null;
    $newId = null;

    try {
        [$service, $repo] = categoryServiceTestServiceRepoForConn($conn);
        $activityLogger = new CategoryServiceUnitTestFakeActivityLogger();

        // 'last' position -- see 'createVirtualCategory() inherits
        // invisibility...' above for why (avoids displacing category 1's
        // own real rank as this private parent is itself root-level too).
        CurrentConfigTestFactory::get()->newcatDefaultPosition = 'last';
        $parentResult = $service->createVirtualCategory('ct_inherit_parent_' . uniqid(), $activityLogger, CurrentUserTestFactory::get(), EntityManagerFactory::build($conn), null, [
            'status' => 'private',
        ]);
        expect(is_numeric($parentResult->id))
            ->toBeTrue();
        $parentId = (int) $parentResult->id;

        // createVirtualCategory() auto-grants the parent's own creator
        // (CurrentUserTestFactory's user 1) access on any private
        // category it creates -- cleared here so the parent's own grants
        // are exactly what this test controls below, not an implicit
        // extra id the child's inherit-copy would also pick up.
        $conn->executeStatement('DELETE FROM user_access WHERE cat_id = ?', [$parentId]);
        $conn->executeStatement('INSERT INTO group_access (group_id, cat_id) VALUES (1, ?), (2, ?), (3, ?)', [$parentId, $parentId, $parentId]);
        $conn->executeStatement('INSERT INTO user_access (user_id, cat_id) VALUES (4, ?)', [$parentId]);

        $result = $service->createVirtualCategory('Inherited Child Test', $activityLogger, CurrentUserTestFactory::get(), EntityManagerFactory::build($conn), $parentId, [
            'inherit' => true,
        ]);
        $newIdRaw = $result->id;
        expect(is_numeric($newIdRaw))
            ->toBeTrue();
        $newId = (int) $newIdRaw;

        // the parent's own group grants (1, 2, 3) must all have been
        // copied onto the new child.
        $groupIds = $repo->findAccessGroupIds(CategoryId::from($newId));
        sort($groupIds);
        expect($groupIds)
            ->toBe([1, 2, 3]);

        // the user_access row just added to the parent must also have
        // been copied.
        expect($repo->findAccessUserIds(CategoryId::from($newId)))
            ->toBe([4]);
    } finally {
        if ($newId !== null) {
            $conn->executeStatement('DELETE FROM user_access WHERE cat_id = ?', [$newId]);
            $conn->executeStatement('DELETE FROM group_access WHERE cat_id = ?', [$newId]);
            $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$newId]);
        }

        if ($parentId !== null) {
            $conn->executeStatement('DELETE FROM user_access WHERE cat_id = ?', [$parentId]);
            $conn->executeStatement('DELETE FROM group_access WHERE cat_id = ?', [$parentId]);
            $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$parentId]);
        }

        CurrentConfigTestFactory::get()->newcatDefaultPosition = 'first';
    }
});

test('getImageIdsForCategories() returns empty for no category ids', function (): void {
    // the empty-input early return -- never reaches the repository or
    // the permission-condition SQL building below it.
    expect(categoryServiceTestService()->getImageIdsForCategories([]))
        ->toBe([]);
});

test('updateCategory() with a scalar id clears a stale representative_picture_id', function (): void {
    // updateCategory()'s own docblock: legacy ws_functions/pwg.images.php
    // callers passed a raw, never-int-cast scalar category id, not an
    // array -- calling with a bare int here exercises that '%s=' . $ids
    // scalar branch directly.
    //
    // representative_picture_id has a real FK to images.id (ON DELETE
    // SET NULL) -- a genuinely dangling value can only exist in practice
    // from a bulk import/migration that ran with checks off, so that's
    // reproduced here rather than a plain UPDATE, which the live FK
    // would reject outright. SET FOREIGN_KEY_CHECKS is session-, not
    // transaction-scoped, so it's unaffected by the enclosing per-test
    // transaction's own eventual rollback.
    $conn = DbConnection::build();
    $conn->executeStatement(getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? 'SET session_replication_role = replica' : 'SET FOREIGN_KEY_CHECKS=0');
    $conn->executeStatement('UPDATE categories SET representative_picture_id = 999999 WHERE id = 1');
    $conn->executeStatement(getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? 'SET session_replication_role = DEFAULT' : 'SET FOREIGN_KEY_CHECKS=1');

    $result = categoryServiceTestServiceRepoForConn($conn)[0]
        ->updateCategory(1);

    expect($result)
        ->toBeNull();

    $repId = $conn->createQueryBuilder()
        ->select('representative_picture_id')
        ->from('categories')
        ->where('id = 1')
        ->executeQuery()
        ->fetchOne();
    // the scalar '%s=1' substitution scoped the wrong-representative
    // sweep to just category 1 -- clearRepresentativePictureIds()
    // nulls the bogus 999999, then the repair branch repicks a real
    // image from category 1's own 3 fixture images.
    expect(is_numeric($repId) ? (int) $repId : null)
        ->toBeIn([1, 2, 3]);
});

test('updateCategory() with the default "all" scopes to every category', function (): void {
    // Proves the `$ids === 'all'` branch really builds a `1=1` WHERE
    // (matching every category), not just a single one -- both
    // categories 1 and 2 get a dangling representative_picture_id, and
    // 'all' must clear (then re-pick) both of them.
    $conn = DbConnection::build();
    $conn->executeStatement(getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? 'SET session_replication_role = replica' : 'SET FOREIGN_KEY_CHECKS=0');
    $conn->executeStatement('UPDATE categories SET representative_picture_id = 999999 WHERE id IN (1, 2)');
    $conn->executeStatement(getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? 'SET session_replication_role = DEFAULT' : 'SET FOREIGN_KEY_CHECKS=1');

    $result = categoryServiceTestServiceRepoForConn($conn)[0]
        ->updateCategory('all');

    expect($result)
        ->toBeNull();

    $repIds = $conn->fetchFirstColumn(
        'SELECT representative_picture_id FROM categories WHERE id IN (1, 2) ORDER BY id'
    );
    foreach ($repIds as $repId) {
        expect($repId)
            ->not->toBe(999999);
    }
});

test('updateCategory() with an empty array returns false', function (): void {
    // The array branch's own `count($ids) === 0` early-return guard --
    // never reaches the wrong-representative sweep below it.
    expect(categoryServiceTestService()->updateCategory([]))
        ->toBeFalse();
});

test('updateCategory() with a non-integer id string is intval-cast before binding', function (): void {
    // A real caller never sends a decimal string, but '2.9' proves every
    // array element is actually intval()-cast to a clean int before it
    // reaches the query.
    $conn = DbConnection::build();
    $conn->executeStatement(getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? 'SET session_replication_role = replica' : 'SET FOREIGN_KEY_CHECKS=0');
    $conn->executeStatement('UPDATE categories SET representative_picture_id = 999999 WHERE id = 2');
    $conn->executeStatement(getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? 'SET session_replication_role = DEFAULT' : 'SET FOREIGN_KEY_CHECKS=1');

    $result = categoryServiceTestServiceRepoForConn($conn)[0]
        ->updateCategory(['2.9']);

    expect($result)
        ->toBeNull();

    $repId = $conn->createQueryBuilder()
        ->select('representative_picture_id')
        ->from('categories')
        ->where('id = 2')
        ->executeQuery()
        ->fetchOne();
    expect(is_numeric($repId) ? (int) $repId : null)
        ->not->toBe(999999);
});

test('setCatVisible() with unlockChild unlocks descendant categories too', function (): void {
    $conn = DbConnection::build();
    $falseLiteral = getenv('PIWIGO_DB_DRIVER') === 'pgsql' ? 'false' : '0';
    $service = categoryServiceTestServiceRepoForConn($conn)[0];
    $conn->executeStatement("UPDATE categories SET visible = {$falseLiteral} WHERE id = 2");

    // unlockChild=true merges getSubcatIds([1]) (== [1, 2]) into the
    // ancestor-only list getUppercatIds([1]) would otherwise produce --
    // category 2 only ends up unlocked because of that merge.
    $service->setCatVisible([1], true, true);

    $visible = $conn->createQueryBuilder()
        ->select('visible')
        ->from('categories')
        ->where('id = 2')
        ->executeQuery()
        ->fetchOne();
    expect((int) (bool) $visible)
        ->toBe(1);
});

test('moveCategories() returns early for no category ids', function (): void {
    $activityLogger = new CategoryServiceUnitTestFakeActivityLogger();

    categoryServiceTestService()
        ->moveCategories([], $activityLogger, PageStateTestFactory::get(), EntityManagerFactory::build(DbConnection::build()));

    // the count()===0 early return skips updateCategoryParent(),
    // updateUppercats()/updateGlobalRank(), the PageState::addInfo()
    // summary and the activity-log record below it entirely.
    expect($activityLogger->calls)
        ->toBe([]);
    expect(PageStateTestFactory::get()->infos)
        ->toBe([]);
});

test('moveCategories() to root sets parent status public', function (): void {
    $conn = DbConnection::build();
    [$service, $repo] = categoryServiceTestServiceRepoForConn($conn);
    $activityLogger = new CategoryServiceUnitTestFakeActivityLogger();

    // default $newParent = -1 -> $newParentSql = 'NULL' -> moving to
    // root, the branch that hardcodes $parentStatus = 'public' rather
    // than looking an actual parent category up.
    $service->moveCategories([2], $activityLogger, PageStateTestFactory::get(), EntityManagerFactory::build($conn));

    $idUppercat = $conn->createQueryBuilder()
        ->select('id_uppercat')
        ->from('categories')
        ->where('id = 2')
        ->executeQuery()
        ->fetchOne();
    expect($idUppercat)
        ->toBeNull();
    expect($repo->findCategoryStatus(2))
        ->toBe('public');
});

test('moveCategories() into a private parent cascades private status', function (): void {
    // The setCatStatus(..., 'private') cascade this triggers computes its
    // "reference" access set from the brand-new, empty private parent --
    // sweeping the real fixture's own (group_id=1, cat_id=2) group_access
    // row along with it, so restoring it explicitly below isn't optional.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // createVirtualCategory() below INSERTs a `categories` row, and
    // `categories` carries a FULLTEXT index (categories_ft_name_comment)
    // whose auxiliary-index maintenance can deadlock against another
    // --parallel worker's own concurrent categories INSERT when held
    // open for a whole test's duration -- same mechanism, same fix, as
    // TagServiceTest.php's 'getTagIds() creates a new tag for a plain
    // name when allowed' (reproduced live there: DeadlockException).
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $activityLogger = new CategoryServiceUnitTestFakeActivityLogger();
    $privateParentId = null;

    try {
        [$service, $repo] = categoryServiceTestServiceRepoForConn($conn);

        // 'last' position -- see 'deleteCategories() delete_orphans...'
        // above for why (avoids displacing category 1's own real rank
        // as this private parent is itself a root-level category too).
        CurrentConfigTestFactory::get()->newcatDefaultPosition = 'last';
        $privateParent = $service->createVirtualCategory(
            'ct_move_private_parent_' . uniqid(),
            $activityLogger,
            CurrentUserTestFactory::get(),
            EntityManagerFactory::build($conn),
            null,
            [
                'status' => 'private',
            ]
        );
        $privateParentIdRaw = $privateParent->id;
        expect(is_numeric($privateParentIdRaw))
            ->toBeTrue();
        $privateParentId = (int) $privateParentIdRaw;

        // moving into a real, non-root parent (status looked up via
        // findCategoryStatus()) that happens to be private -- the
        // setCatStatus(..., 'private') cascade onto the moved categories
        // themselves only fires on this branch.
        $service->moveCategories([2], $activityLogger, PageStateTestFactory::get(), EntityManagerFactory::build($conn), $privateParentId);

        expect($repo->findCategoryStatus(2))
            ->toBe('private');
    } finally {
        // Move category 2 back under its real fixture parent (1) via the
        // same production code path, so updateUppercats()/updateGlobalRank()
        // recompute its uppercats/global_rank back to their original
        // values rather than this restore having to hand-reconstruct them.
        [$service] = categoryServiceTestServiceRepoForConn($conn);
        $service->moveCategories([2], $activityLogger, PageStateTestFactory::get(), EntityManagerFactory::build($conn), 1);
        $conn->executeStatement("UPDATE categories SET status = 'public' WHERE id = 2");
        // Idempotent restore -- the setCatStatus() cascade above may or
        // may not have reached the sweep before this finally runs.
        $conn->executeStatement('DELETE FROM group_access WHERE group_id = 1 AND cat_id = 2');
        $conn->executeStatement('INSERT INTO group_access (group_id, cat_id) VALUES (1, 2)');

        if ($privateParentId !== null) {
            $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$privateParentId]);
        }

        CurrentConfigTestFactory::get()->newcatDefaultPosition = 'first';
    }
});

test('createVirtualCategory() with "last" position ranks after existing siblings', function (): void {
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // createVirtualCategory() below INSERTs a `categories` row, and
    // `categories` carries a FULLTEXT index (categories_ft_name_comment)
    // whose auxiliary-index maintenance can deadlock against another
    // --parallel worker's own concurrent categories INSERT when held
    // open for a whole test's duration -- same mechanism, same fix, as
    // TagServiceTest.php's 'getTagIds() creates a new tag for a plain
    // name when allowed' (reproduced live there: DeadlockException).
    //
    // Both siblings below live under a disposable parent, not root --
    // findMaxRankForParent(null) is a bare MAX(rank) aggregate with
    // nothing to filter down to known-real ids the way an exact-list
    // assertion could, so this test creating a real root-level sibling
    // (as it did before) is directly observable by that other test's own
    // exact toBe(1) assertion for the whole span this row is committed.
    //
    // 'last' position for every create below, including the disposable
    // parent's own -- createVirtualCategory()'s internal
    // updateGlobalRank() call renumbers every sibling group sequentially
    // by (rank, name), and a default-position (rank 0) parent would sort
    // ahead of real fixture category 1 (rank 1) and displace its own
    // rank for as long as this test's own data exists. This is also
    // exactly the behavior under test, so setting it once up front
    // (rather than only for the final create) changes nothing about the
    // test's own intent.
    DbTransactionTestOverride::rollback();
    CurrentConfigTestFactory::get()->newcatDefaultPosition = 'last';
    $conn = DbConnection::build();
    $parentId = null;
    $firstChildId = null;
    $lastChildId = null;

    try {
        $service = categoryServiceTestServiceRepoForConn($conn)[0];
        $activityLogger = new CategoryServiceUnitTestFakeActivityLogger();

        $parentResult = $service->createVirtualCategory('ct_last_position_parent_' . uniqid(), $activityLogger, CurrentUserTestFactory::get(), EntityManagerFactory::build($conn));
        expect(is_numeric($parentResult->id))
            ->toBeTrue();
        $parentId = (int) $parentResult->id;

        $firstChildResult = $service->createVirtualCategory('ct_last_position_first_' . uniqid(), $activityLogger, CurrentUserTestFactory::get(), EntityManagerFactory::build($conn), $parentId);
        expect(is_numeric($firstChildResult->id))
            ->toBeTrue();
        $firstChildId = (int) $firstChildResult->id;

        $lastChildResult = $service->createVirtualCategory('ct_last_position_last_' . uniqid(), $activityLogger, CurrentUserTestFactory::get(), EntityManagerFactory::build($conn), $parentId);
        expect(is_numeric($lastChildResult->id))
            ->toBeTrue();
        $lastChildId = (int) $lastChildResult->id;

        $rank = $conn->createQueryBuilder()
            ->select($conn->getDatabasePlatform()->quoteSingleIdentifier('rank'))
            ->from('categories')
            ->where('id = ' . $lastChildId)
            ->executeQuery()
            ->fetchOne();
        // The first child is the only existing sibling -- updateGlobalRank()
        // (called internally by createVirtualCategory(), both times)
        // renumbers every sibling group to a 1-indexed sequential rank
        // regardless of the raw insert-time value, so the first child
        // actually ends up at rank 1, not the naive "default rank 0" its
        // own insert used. newcatDefaultPosition=last must place the new
        // sibling right after it, at rank 2.
        expect(is_numeric($rank) ? (int) $rank : null)
            ->toBe(2);
    } finally {
        if ($lastChildId !== null) {
            $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$lastChildId]);
        }

        if ($firstChildId !== null) {
            $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$firstChildId]);
        }

        if ($parentId !== null) {
            $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$parentId]);
        }

        CurrentConfigTestFactory::get()->newcatDefaultPosition = 'first';
    }
});

test('setRepresentativeImageForCategories() updates both categories', function (): void {
    $conn = categoryServiceTestConn();

    try {
        categoryServiceTestService()->setRepresentativeImageForCategories([1, 2], 3);

        $repIds = $conn->fetchFirstColumn(
            'SELECT representative_picture_id FROM categories WHERE id IN (1, 2) ORDER BY id'
        );
        expect($repIds)
            ->toBe([3, 3]);
    } finally {
        $conn->executeStatement('UPDATE categories SET representative_picture_id = 1 WHERE id = 1');
        $conn->executeStatement('UPDATE categories SET representative_picture_id = 4 WHERE id = 2');
    }
});

test('getImageIdsOutsideCategories() excludes the given category', function (): void {
    // category 1 owns images 1-3, category 2 owns images 4-5 -- excluding
    // category 1 leaves only category 2's images.
    $ids = categoryServiceTestService()
        ->getImageIdsOutsideCategories([1]);
    sort($ids);

    expect($ids)
        ->toBe([4, 5]);
});
