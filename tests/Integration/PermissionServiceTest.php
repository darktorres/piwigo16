<?php

declare(strict_types=1);

// PermissionService::getForbiddenCategories() calls
// Piwigo\Auth\AccessControl::isAdmin($userStatus) directly, always with
// this file's own explicit $userStatus argument -- no stub needed.

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use LogicException;
    use Override;
    use Piwigo\Auth\AccessLevelChecker;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Core\FilterState;
    use Piwigo\Core\Kernel;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Db\TypedRepository;
    use Piwigo\Group\GroupEntity;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Tests\Support\DbTransactionTestOverride;

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
                $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
                self::$fixtureReady = true;
            }

            // PILOT (transaction-wrapping rollout): begin before any container
            // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
            // comment for the full reasoning.
            DbTransactionTestOverride::begin();

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
                TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), GroupRepository::class),
                new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig),
                CurrentUserTestFactory::get(),
                $filterState,
                new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig)
            );
        }

        #[Override]
        protected function tearDown(): void
        {
            // visible is a genuine boolean column -- a bare `1` literal in
            // the SQL text (unlike a bound parameter, which the driver
            // coerces implicitly) is rejected outright by Postgres.
            $visibleLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
            $this->conn->executeStatement("UPDATE categories SET status = 'public', visible = {$visibleLiteral}");
            $this->conn->executeStatement('DELETE FROM user_access');
            DbTransactionTestOverride::rollback();
            parent::tearDown();
        }

        public function testReturnsJustZeroWhenThereAreNoPrivateOrLockedCategories(): void
        {
            self::assertSame('0', $this->service->getForbiddenCategories(2, 'normal'));
        }

        public function testAPrivateCategoryIsForbiddenToAUserWithNoAccess(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

            // User 2 (guest, fixture) has no user_access row and isn't in
            // any group with group_access to cat 1.
            self::assertSame('1', $this->service->getForbiddenCategories(2, 'normal'));
        }

        public function testAPrivateCategoryIsNotForbiddenWithDirectUserAccess(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");
            $this->conn->executeStatement('INSERT INTO user_access (user_id, cat_id) VALUES (2, 1)');

            self::assertSame('0', $this->service->getForbiddenCategories(2, 'normal'));
        }

        public function testAPrivateCategoryIsNotForbiddenViaGroupAccess(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

            // Fixture: user 1 is a member of group 1 ("Editors"), which has
            // group_access to cat 1 -- this is exactly the group-based JOIN
            // that motivated Group's L2aCoreDomain placement.
            self::assertSame('0', $this->service->getForbiddenCategories(1, 'normal'));
        }

        public function testALockedCategoryIsForbiddenToANonAdmin(): void
        {
            $this->conn->executeStatement('UPDATE categories SET visible = ' . ($this->dbDriver === 'pgsql' ? 'false' : '0') . ' WHERE id = 2');

            self::assertSame('2', $this->service->getForbiddenCategories(2, 'normal'));
        }

        public function testALockedCategoryIsNotForbiddenToAnAdmin(): void
        {
            $this->conn->executeStatement('UPDATE categories SET visible = ' . ($this->dbDriver === 'pgsql' ? 'false' : '0') . ' WHERE id = 2');

            self::assertSame('0', $this->service->getForbiddenCategories(2, 'admin'));
        }

        public function testPrivateAndLockedCategoriesCombine(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");
            $this->conn->executeStatement('UPDATE categories SET visible = ' . ($this->dbDriver === 'pgsql' ? 'false' : '0') . ' WHERE id = 2');

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
                ->from('user_access')
                ->where('user_id = :userId')
                ->setParameter('userId', $userId)
                ->executeQuery()
                ->fetchFirstColumn();

            return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
        }

        public function testRemoveUserAccessDelegatesToTheRepository(): void
        {
            $this->conn->executeStatement('INSERT INTO user_access (user_id, cat_id) VALUES (2, 1), (2, 2)');

            $this->service->removeUserAccess(2, [1]);

            self::assertSame([2], $this->userAccessCatIdsFor(2));
        }

        public function testAddPermissionOnCategoryWithAScalarUserIdNormalizesItToAList(): void
        {
            // A plain scalar int $userIds -- exercises
            // addPermissionOnCategory()'s own `$userIds = [$userIds];`
            // scalar-to-array normalization (the sibling normalization for
            // $categoryIds is already covered by other callers that pass a
            // bare int there).
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

            $this->service->addPermissionOnCategory([1], 4);

            self::assertSame([1], $this->userAccessCatIdsFor(4));
        }

        public function testAddPermissionOnCategoryWithNoCategoriesIsANoop(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

            $this->service->addPermissionOnCategory([], [2]);

            self::assertSame([], $this->userAccessCatIdsFor(2));
        }

        public function testAddPermissionOnCategoryWithApplyOnSubAlsoGrantsTheSubcategory(): void
        {
            // Fixture: category 2 ("Nested Sub Album") is category 1's own
            // child (uppercats '1,2'). Both made private here so both are
            // eligible for an explicit user_access row -- proving
            // applyOnSub's own findSubcategoryIds() merge actually widens
            // the grant beyond category 1 alone.
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id IN (1, 2)");

            $this->service->addPermissionOnCategory([1], [3], applyOnSub: true);

            $catIds = $this->userAccessCatIdsFor(3);
            sort($catIds);

            self::assertSame([1, 2], $catIds);
        }
    }
}
