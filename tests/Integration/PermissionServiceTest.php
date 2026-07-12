<?php

declare(strict_types=1);

// PermissionService::getForbiddenCategories() calls the real is_admin()
// (unqualified, resolves to the global namespace) -- a real, stable,
// already-migrated function, but one that needs full app bootstrap
// (global $user, $conf) this isolated integration test doesn't load. Same
// "minimal stub to load standalone" pattern as tests/Unit/PasswordHashTest.php.
namespace {
    if (! function_exists('is_admin')) {
        // Matches the real is_admin($user_status = '')'s own contract: an
        // explicit non-empty $user_status is checked directly (this file's
        // own calling convention), an empty/default one falls back to a
        // test-controlled global (needed by
        // tests/Integration/CommentServiceTest.php's own calling
        // convention) -- function_exists() guards mean whichever
        // Integration test file's stub loads first wins for the whole test
        // run, so every file declaring this stub must support both
        // conventions identically.
        function is_admin(string $user_status = ''): bool
        {
            if ($user_status !== '') {
                return $user_status === 'admin';
            }

            return (bool) ($GLOBALS['test_is_admin'] ?? false);
        }
    }
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Config\Config;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;

    final class PermissionServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private PermissionService $service;

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
            $this->service = new PermissionService(
                new PermissionRepository($this->conn),
                new GroupRepository($this->conn)
            );
        }

        #[\Override]
        protected function tearDown(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public', visible = 'true'");
            $this->conn->executeStatement('DELETE FROM ' . Tables::userAccess());
            parent::tearDown();
        }

        public function test_returns_just_zero_when_there_are_no_private_or_locked_categories(): void
        {
            self::assertSame('0', $this->service->getForbiddenCategories(2, 'normal'));
        }

        public function test_a_private_category_is_forbidden_to_a_user_with_no_access(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");

            // User 2 (guest, fixture) has no user_access row and isn't in
            // any group with group_access to cat 1.
            self::assertSame('1', $this->service->getForbiddenCategories(2, 'normal'));
        }

        public function test_a_private_category_is_not_forbidden_with_direct_user_access(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");
            $this->conn->executeStatement('INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (2, 1)');

            self::assertSame('0', $this->service->getForbiddenCategories(2, 'normal'));
        }

        public function test_a_private_category_is_not_forbidden_via_group_access(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");

            // Fixture: user 1 is a member of group 1 ("Editors"), which has
            // group_access to cat 1 -- this is exactly the group-based JOIN
            // that motivated Group's L2aCoreDomain placement.
            self::assertSame('0', $this->service->getForbiddenCategories(1, 'normal'));
        }

        public function test_a_locked_category_is_forbidden_to_a_non_admin(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET visible = 'false' WHERE id = 2");

            self::assertSame('2', $this->service->getForbiddenCategories(2, 'normal'));
        }

        public function test_a_locked_category_is_not_forbidden_to_an_admin(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET visible = 'false' WHERE id = 2");

            self::assertSame('0', $this->service->getForbiddenCategories(2, 'admin'));
        }

        public function test_private_and_locked_categories_combine(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET visible = 'false' WHERE id = 2");

            $forbidden = explode(',', $this->service->getForbiddenCategories(2, 'normal'));
            sort($forbidden);

            self::assertSame(['1', '2'], $forbidden);
        }
    }
}
