<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;

/**
 * Real fake for setCategoryOption()'s per-method ActivityLoggerInterface
 * parameter -- a standalone class (not an anonymous class reaching into
 * the test's own private state) so each test can just read
 * `$logger->calls` directly.
 */
final class CategoryAdminServiceFakeActivityLogger implements ActivityLoggerInterface
{
    /** @var list<array{object: string, objectId: mixed, action: string, details: array<string, mixed>}> */
    public array $calls = [];

    #[\Override]
    public function record(string $object, int|string|array $objectId, string $action, array $details = []): void
    {
        $this->calls[] = [
            'object' => $object,
            'objectId' => $objectId,
            'action' => $action,
            'details' => $details,
        ];
    }
}

/**
 * Moved from Unit to Integration (Legacy Coupling Retirement Phase 4a):
 * CategoryAdminService's target free-function calls now go through a
 * real, constructor-injected CategoryService -- CategoryService and
 * CategoryRepository are both `final`, so the same-namespace
 * function-shadowing stub technique the old Unit test used can no
 * longer intercept these calls (that technique only works against a
 * bare, unqualified function call, not a method call on a concrete
 * object). Docs/PLAN-REPLAY-AUDIT.md gap-closure, 2026-07-23: the
 * remaining bare pwg_query()/query2array()/mass_inserts() calls this
 * file used to shadow (getCategoriesRefDate()/setCategoryPermissions()/
 * saveImageOrder(), none of which ever had a real production
 * definition -- every one of those methods fatal-errored outside this
 * test process) are now real repository/service calls too, so the
 * shadowing block that used to sit above this namespace is gone
 * entirely; nothing in this file needs it to pass anymore.
 *
 * Same fixture shape as CategoryServiceTest/CategoryRepositoryTest:
 * category 1 "Sample Album" (root, images 1/2/3), category 2 "Nested
 * Sub Album" (child of 1, images 4/5) -- every image shares the same
 * fixture `date_available` ('2026-08-01 00:00:00'), which is why
 * getCategoriesRefDate()'s own assertions below compare against that
 * exact literal rather than a relative/computed value. group_access
 * fixture rows: Editors (group 1) on cats 1+2, Reviewers (group 2) on
 * cat 1, Guests (group 3) on cat 1 -- setCategoryPermissions()'s own
 * tests restore these via tearDown() below, same as every other
 * mutation this file makes.
 */
final class CategoryAdminServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CategoryAdminService $service;

    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        // DI-phase follow-on to gap-closure Stage 4: CategoryAdminService
        // now resolves PermissionService via
        // Bootstrap\CoreDomainAccessor -> Kernel::container(), which this
        // isolated Integration test (no full RequestBootstrap) wouldn't
        // otherwise boot.
        Kernel::boot();

        $this->conn = DbConnection::build();
        $categoryService = new CategoryService(
            new CategoryRepository($this->conn),
            new PermissionService(
                new PermissionRepository($this->conn),
                new GroupRepository($this->conn),
                new CategoryRepository($this->conn)
            )
        );
        $this->service = new CategoryAdminService($categoryService);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET commentable = 1, visible = 1, status = 'public', representative_picture_id = NULL, image_order = NULL");
        $this->conn->executeStatement('DELETE FROM ' . Tables::userAccess());
        $this->conn->executeStatement('DELETE FROM ' . Tables::groupAccess());
        $this->conn->executeStatement('INSERT INTO ' . Tables::groupAccess() . ' (group_id, cat_id) VALUES (1, 1), (1, 2), (2, 1), (3, 1)');
        Kernel::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCategory(int $catId): ?array
    {
        $row = $this->conn->executeQuery('SELECT * FROM ' . Tables::categories() . ' WHERE id = ' . $catId)
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * @return list<int>
     */
    private function groupAccessFor(int $catId): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->conn->createQueryBuilder()
                ->select('group_id')
                ->from(Tables::groupAccess())
                ->where('cat_id = :catId')
                ->setParameter('catId', $catId)
                ->executeQuery()
                ->fetchFirstColumn()
        );
    }

    /**
     * @return list<int>
     */
    private function userAccessFor(int $catId): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->conn->createQueryBuilder()
                ->select('user_id')
                ->from(Tables::userAccess())
                ->where('cat_id = :catId')
                ->setParameter('catId', $catId)
                ->executeQuery()
                ->fetchFirstColumn()
        );
    }

    public function test_set_category_option_comments_false_updates_the_row_and_logs_activity(): void
    {
        $logger = new CategoryAdminServiceFakeActivityLogger();
        $this->service->setCategoryOption([1], 'comments', false, $logger);

        $category = $this->fetchCategory(1);
        self::assertNotNull($category);
        self::assertSame(0, $category['commentable']);

        self::assertCount(1, $logger->calls);
        self::assertSame('album', $logger->calls[0]['object']);
        self::assertSame([1], $logger->calls[0]['objectId']);
        self::assertSame('edit', $logger->calls[0]['action']);
        self::assertSame(['section' => 'comments', 'action' => 'falsify'], $logger->calls[0]['details']);
    }

    public function test_set_category_option_visible_true_updates_the_row(): void
    {
        $this->service->setCategoryOption([2], 'visible', true, new CategoryAdminServiceFakeActivityLogger());

        $category = $this->fetchCategory(2);
        self::assertNotNull($category);
        self::assertSame(1, $category['visible']);
    }

    public function test_set_category_option_status_false_sets_private(): void
    {
        $this->service->setCategoryOption([2], 'status', false, new CategoryAdminServiceFakeActivityLogger());

        $category = $this->fetchCategory(2);
        self::assertNotNull($category);
        self::assertSame('private', $category['status']);
    }

    public function test_set_category_option_representative_true_picks_a_real_image(): void
    {
        $this->service->setCategoryOption([1], 'representative', true, new CategoryAdminServiceFakeActivityLogger());

        $category = $this->fetchCategory(1);
        self::assertNotNull($category);
        // Category 1 links images 1/2/3 (piwigo_image_category fixture
        // rows) -- any real representative pick must be one of those,
        // not null and not some unrelated id.
        self::assertContains($category['representative_picture_id'], ['1', '2', '3', 1, 2, 3]);
    }

    public function test_set_category_option_representative_false_clears_the_row(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 1 WHERE id = 1');

        $this->service->setCategoryOption([1], 'representative', false, new CategoryAdminServiceFakeActivityLogger());

        $category = $this->fetchCategory(1);
        self::assertNotNull($category);
        self::assertNull($category['representative_picture_id']);
    }

    public function test_set_category_option_with_an_empty_id_list_does_nothing(): void
    {
        $before = $this->fetchCategory(1);
        $logger = new CategoryAdminServiceFakeActivityLogger();

        $this->service->setCategoryOption([], 'comments', false, $logger);

        $after = $this->fetchCategory(1);
        self::assertSame($before, $after);
        self::assertSame([], $logger->calls);
    }

    public function test_save_image_order_updates_the_category_row_only_when_subcats_is_false(): void
    {
        $this->service->saveImageOrder(2, '`rank` ASC', false, new RedirectService());

        $cat1 = $this->fetchCategory(1);
        $cat2 = $this->fetchCategory(2);
        self::assertNotNull($cat1);
        self::assertNotNull($cat2);
        self::assertNull($cat1['image_order']);
        self::assertSame('`rank` ASC', $cat2['image_order']);
    }

    public function test_save_image_order_with_subcats_cascades_to_the_uppercats_chain(): void
    {
        // Category 2's own uppercats ('1,2') includes category 1 --
        // saving on category 1 with $applySubcats=true matches every row
        // whose uppercats starts with '1,', which includes category 2.
        $this->service->saveImageOrder(1, 'id ASC', true, new RedirectService());

        $cat1 = $this->fetchCategory(1);
        $cat2 = $this->fetchCategory(2);
        self::assertNotNull($cat1);
        self::assertNotNull($cat2);
        self::assertSame('id ASC', $cat1['image_order']);
        self::assertSame('id ASC', $cat2['image_order']);
    }

    public function test_get_categories_ref_date_returns_the_shared_fixture_date(): void
    {
        $result = $this->service->getCategoriesRefDate([1, 2]);

        // Every fixture image shares the same date_available
        // ('2026-08-01 00:00:00'), so both categories' max ref_date
        // resolve to that same literal value.
        self::assertSame('2026-08-01 00:00:00', $result[1]);
        self::assertSame('2026-08-01 00:00:00', $result[2]);
    }

    public function test_get_categories_ref_date_returns_null_for_an_unknown_category(): void
    {
        $result = $this->service->getCategoriesRefDate([999999]);

        self::assertSame([999999 => null], $result);
    }

    public function test_create_virtual_category_rejects_a_blank_name(): void
    {
        $result = $this->service->createVirtualCategory('   ', new CategoryAdminServiceFakeActivityLogger());

        self::assertFalse($result->success);
        self::assertNotNull($result->message);
        self::assertNull($result->categoryId);
    }

    public function test_create_virtual_category_creates_a_real_row(): void
    {
        $result = $this->service->createVirtualCategory('Integration Test Album', new CategoryAdminServiceFakeActivityLogger());

        self::assertTrue($result->success);
        self::assertNotNull($result->categoryId);

        $created = $this->fetchCategory($result->categoryId);
        self::assertNotNull($created);
        self::assertSame('Integration Test Album', $created['name']);

        $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ' . $result->categoryId);
    }

    public function test_set_category_permissions_denies_a_group_no_longer_in_the_grant_list(): void
    {
        // Fixture: cat 1 grants groups 1 (Editors), 2 (Reviewers), 3
        // (Guests). Keeping only [1, 2] must revoke group 3.
        $this->service->setCategoryPermissions(1, 'private', 'private', false, [1, 2], []);

        self::assertSame([1, 2], $this->groupAccessFor(1));
    }

    public function test_set_category_permissions_denies_every_group_when_none_are_kept(): void
    {
        $this->service->setCategoryPermissions(1, 'private', 'private', false, [], []);

        self::assertSame([], $this->groupAccessFor(1));
    }

    public function test_set_category_permissions_grants_a_new_group_to_the_now_private_category(): void
    {
        // Cat 2 starts public with only group 1 granted; switching it to
        // private and keeping group 1 while adding group 2 must insert a
        // fresh (2, 2) row without disturbing (1, 2).
        $this->service->setCategoryPermissions(2, 'public', 'private', false, [1, 2], []);

        $category = $this->fetchCategory(2);
        self::assertNotNull($category);
        self::assertSame('private', $category['status']);
        self::assertSame([1, 2], $this->groupAccessFor(2));
    }

    public function test_set_category_permissions_grant_does_not_reach_a_still_public_ancestor(): void
    {
        // Cat 2's own uppercats chain includes cat 1, but cat 1 stays
        // public here -- the private-only grant filter must exclude it
        // even though it's in the uppercats candidate list. Deny/grant
        // here only ever target cat 2 (applyOnSub is false and cat 1 has
        // no subcats of its own), so cat 1's own fixture groups are
        // completely untouched -- proving the grant excluded it, not
        // just that nothing happened to reach it.
        $this->service->setCategoryPermissions(2, 'public', 'private', false, [2], []);

        self::assertSame([1, 2, 3], $this->groupAccessFor(1));
        self::assertSame([2], $this->groupAccessFor(2));
    }

    public function test_set_category_permissions_apply_on_sub_cascades_status_and_grants(): void
    {
        // Cat 1 -> private with subcats applied must also flip cat 2 (its
        // only child) to private. The deny step forbids groups 1/3 from
        // cat 1 *and* its subcats (cat 2), which is why cat 2 loses its
        // own fixture group 1 grant too, not just gains group 2.
        $this->service->setCategoryPermissions(1, 'public', 'private', true, [2], []);

        $cat1 = $this->fetchCategory(1);
        $cat2 = $this->fetchCategory(2);
        self::assertNotNull($cat1);
        self::assertNotNull($cat2);
        self::assertSame('private', $cat1['status']);
        self::assertSame('private', $cat2['status']);
        self::assertSame([2], $this->groupAccessFor(1));
        self::assertSame([2], $this->groupAccessFor(2));
    }

    public function test_set_category_permissions_denies_a_user_no_longer_in_the_grant_list(): void
    {
        $this->conn->executeStatement('INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (2, 1), (3, 1)');

        // Real public -> private transition (not private -> private):
        // addPermissionOnCategory()'s own grant only ever inserts rows
        // for categories genuinely private *in the database* right now,
        // not just categories the caller happens to label 'private' in
        // its params -- a real transition is required to exercise it.
        $this->service->setCategoryPermissions(1, 'public', 'private', false, [], [3]);

        self::assertSame([3], $this->userAccessFor(1));
    }

    public function test_set_category_permissions_grants_a_new_user_to_a_private_category(): void
    {
        $this->service->setCategoryPermissions(1, 'public', 'private', false, [], [2]);

        self::assertSame([2], $this->userAccessFor(1));
    }
}
