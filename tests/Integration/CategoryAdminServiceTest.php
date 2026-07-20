<?php

declare(strict_types=1);

// pwg_query()/query2array()/mass_inserts() are a separate, out-of-scope
// legacy free-function family (not part of this phase's 7 target Category
// functions) that still needs the full legacy bootstrap this Integration
// harness deliberately doesn't load. Real, DBAL-backed local
// implementations (not fake/mocked behavior) so CategoryAdminService's
// real logic still gets exercised end-to-end -- same "stub what needs
// more bootstrap than this isolated test wants" convention already used
// throughout this test suite (e.g. CategoryServiceTest's own l10n()/
// get_boolean() stubs).
namespace Piwigo\Admin\Category {
    function pwg_query(string $query): mixed
    {
        return \Piwigo\Db\DbConnection::build()->executeStatement($query);
    }

    /**
     * @return array<int|string, mixed>
     */
    function query2array(string $query, ?string $keyField = null, ?string $valueField = null): array
    {
        $rows = \Piwigo\Db\DbConnection::build()->executeQuery($query)->fetchAllAssociative();
        if ($keyField === null) {
            return $rows;
        }

        $result = [];
        foreach ($rows as $row) {
            $key = $row[$keyField];
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }
            $result[$key] = $valueField !== null ? ($row[$valueField] ?? null) : $row;
        }

        return $result;
    }

    /**
     * @param array<int, string> $fields
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $options
     */
    function mass_inserts(string $table, array $fields, array $rows, array $options = []): void
    {
        new \Piwigo\Db\BatchWriter(\Piwigo\Db\DbConnection::build())->massInsert($table, $fields, $rows, $options);
    }
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Admin\Category\CategoryAdminService;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\CategoryService;
    use Piwigo\Config\Config;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Core\ActivityLoggerInterface;
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
     * CategoryAdminService's 7 target free-function calls now go through a
     * real, constructor-injected CategoryService -- CategoryService and
     * CategoryRepository are both `final`, so the same-namespace
     * function-shadowing stub technique the old Unit test used can no
     * longer intercept these calls (that technique only works against a
     * bare, unqualified function call, not a method call on a concrete
     * object). Same fixture shape as CategoryServiceTest/CategoryRepositoryTest:
     * category 1 "Sample Album" (root, images 1/2/3), category 2 "Nested
     * Sub Album" (child of 1, images 4/5) -- every image shares the same
     * fixture `date_available` ('2026-08-01 00:00:00'), which is why
     * getCategoriesRefDate()'s own assertions below compare against that
     * exact literal rather than a relative/computed value.
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

            Config::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

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
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET commentable = 'true', visible = 'true', status = 'public', representative_picture_id = NULL, image_order = NULL");
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

        public function test_set_category_option_comments_false_updates_the_row_and_logs_activity(): void
        {
            $logger = new CategoryAdminServiceFakeActivityLogger();
            $this->service->setCategoryOption([1], 'comments', false, $logger);

            $category = $this->fetchCategory(1);
            self::assertNotNull($category);
            self::assertSame('false', $category['commentable']);

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
            self::assertSame('true', $category['visible']);
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
            $this->service->saveImageOrder(2, '`rank` ASC', false);

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
            $this->service->saveImageOrder(1, 'id ASC', true);

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

            $created = $this->fetchCategory((int) $result->categoryId);
            self::assertNotNull($created);
            self::assertSame('Integration Test Album', $created['name']);

            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ' . (int) $result->categoryId);
        }
    }
}
