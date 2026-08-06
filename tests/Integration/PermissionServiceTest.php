<?php

declare(strict_types=1);

// PermissionService::getForbiddenCategories() now calls
// Piwigo\Auth\AccessControl::isAdmin($userStatus) directly (P23 batch 8d),
// always with this file's own explicit $userStatus argument -- no stub
// needed.

namespace Piwigo\Tests\Integration {

    use Override;
    use Piwigo\Auth\AccessLevelChecker;
    use Piwigo\Core\Kernel;
    use LogicException;
    use Piwigo\Core\FilterState;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Group\GroupEntity;
    use Piwigo\Users\CurrentUser;
    use Doctrine\DBAL\Connection;

use Piwigo\Category\CategoryRepository;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;

    final class PermissionServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private PermissionService $service;

        private Connection $conn;

        #[Override]
        protected function setUp(): void
        {
            parent::setUp();
            $this->setUpConnectionFromEnv();

            if (! self::$fixtureReady) {
                $this->resetDatabase();
                $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
                self::$fixtureReady = true;
            }

            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            $filterState = Kernel::container()->get(FilterState::class);
            if (! $filterState instanceof FilterState) {
                throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
            }

            $this->conn = DbConnection::build();
            $this->service = new PermissionService(
                new PermissionRepository(EntityManagerFactory::build($this->conn)),
                EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class)
            , new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig), CurrentUser::current(), $filterState, new AccessLevelChecker(CurrentUser::current(), $currentConfig));
        }

        #[Override]
        protected function tearDown(): void
        {
            // visible is a genuine boolean column -- a bare `1` literal in
            // the SQL text (unlike a bound parameter, which the driver
            // coerces implicitly) is rejected outright by Postgres.
            $visibleLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public', visible = {$visibleLiteral}");
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
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET visible = ' . ($this->dbDriver === 'pgsql' ? 'false' : '0') . ' WHERE id = 2');

            self::assertSame('2', $this->service->getForbiddenCategories(2, 'normal'));
        }

        public function test_a_locked_category_is_not_forbidden_to_an_admin(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET visible = ' . ($this->dbDriver === 'pgsql' ? 'false' : '0') . ' WHERE id = 2');

            self::assertSame('0', $this->service->getForbiddenCategories(2, 'admin'));
        }

        public function test_private_and_locked_categories_combine(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET visible = ' . ($this->dbDriver === 'pgsql' ? 'false' : '0') . ' WHERE id = 2');

            $forbidden = explode(',', $this->service->getForbiddenCategories(2, 'normal'));
            sort($forbidden);

            self::assertSame(['1', '2'], $forbidden);
        }

        /**
         * @return list<int>
         */
        private function userAccessCatIdsFor(int $userId): array
        {
            $rows = $this->conn->createQueryBuilder()
                ->select('cat_id')
                ->from(Tables::userAccess())
                ->where('user_id = :userId')
                ->setParameter('userId', $userId)
                ->executeQuery()
                ->fetchFirstColumn();

            return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
        }

        public function test_remove_user_access_delegates_to_the_repository(): void
        {
            $this->conn->executeStatement('INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (2, 1), (2, 2)');

            $this->service->removeUserAccess(2, [1]);

            self::assertSame([2], $this->userAccessCatIdsFor(2));
        }

        public function test_grant_user_access_delegates_to_add_permission_on_category(): void
        {
            // grantUserAccess()'s own $userId param is a plain scalar int,
            // not a list -- this also exercises addPermissionOnCategory()'s
            // own `$userIds = [$userIds];` scalar-to-array normalization
            // (the sibling normalization for $categoryIds is already
            // covered by other callers that pass a bare int there).
            $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET status = 'private' WHERE id = 1");

            $this->service->grantUserAccess(4, [1]);

            self::assertSame([1], $this->userAccessCatIdsFor(4));
        }

        public function test_add_permission_on_category_with_no_categories_is_a_noop(): void
        {
            $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET status = 'private' WHERE id = 1");

            $this->service->addPermissionOnCategory([], [2]);

            self::assertSame([], $this->userAccessCatIdsFor(2));
        }

        public function test_add_permission_on_category_with_apply_on_sub_also_grants_the_subcategory(): void
        {
            // Fixture: category 2 ("Nested Sub Album") is category 1's own
            // child (uppercats '1,2'). Both made private here so both are
            // eligible for an explicit user_access row -- proving
            // applyOnSub's own findSubcategoryIds() merge actually widens
            // the grant beyond category 1 alone.
            $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET status = 'private' WHERE id IN (1, 2)");

            $this->service->addPermissionOnCategory([1], [3], applyOnSub: true);

            $catIds = $this->userAccessCatIdsFor(3);
            sort($catIds);

            self::assertSame([1, 2], $catIds);
        }
    }
}
