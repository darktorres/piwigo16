<?php

declare(strict_types=1);

// PermissionService::getForbiddenCategories() now calls
// Piwigo\Auth\AccessControl::isAdmin($userStatus) directly (P23 batch 8d),
// always with this file's own explicit $userStatus argument -- no stub
// needed.

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;

use Piwigo\Category\CategoryRepository;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;

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

            CurrentConfig::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            $this->conn = DbConnection::build();
            $this->service = new PermissionService(
                new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)),
                \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class)
            , \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class));
        }

        #[\Override]
        protected function tearDown(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public', visible = 1");
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
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET visible = 0 WHERE id = 2');

            self::assertSame('2', $this->service->getForbiddenCategories(2, 'normal'));
        }

        public function test_a_locked_category_is_not_forbidden_to_an_admin(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET visible = 0 WHERE id = 2');

            self::assertSame('0', $this->service->getForbiddenCategories(2, 'admin'));
        }

        public function test_private_and_locked_categories_combine(): void
        {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET visible = 0 WHERE id = 2');

            $forbidden = explode(',', $this->service->getForbiddenCategories(2, 'normal'));
            sort($forbidden);

            self::assertSame(['1', '2'], $forbidden);
        }

        public function test_sql_condition_fand_f_returns_empty_string_with_no_globals_set(): void
        {
            CurrentUser::set(User::fromUserArray([]));

            self::assertSame('', $this->service->getSqlConditionFandF([
                'forbidden_categories' => 'id',
            ]));
        }

        public function test_sql_condition_fand_f_forces_one_condition_when_empty(): void
        {
            CurrentUser::set(User::fromUserArray([]));

            self::assertSame('1 = 1', $this->service->getSqlConditionFandF([
                'forbidden_categories' => 'id',
            ], null, true));
        }

        public function test_sql_condition_fand_f_builds_forbidden_categories_not_in(): void
        {
            CurrentUser::set(User::fromUserArray(['forbidden_categories' => '1,2,3']));

            self::assertSame('(id NOT IN (1,2,3))', $this->service->getSqlConditionFandF([
                'forbidden_categories' => 'id',
            ]));
        }

        public function test_sql_condition_fand_f_builds_visible_categories_in(): void
        {
            CurrentUser::set(User::fromUserArray([]));
            \Piwigo\Core\FilterState::set(true, '4,5');

            self::assertSame('(category_id IN (4,5))', $this->service->getSqlConditionFandF([
                'visible_categories' => 'category_id',
            ]));
        }

        public function test_sql_condition_fand_f_combines_multiple_conditions_with_prefix(): void
        {
            CurrentUser::set(User::fromUserArray(['forbidden_categories' => '1']));
            \Piwigo\Core\FilterState::set(true, '2');

            self::assertSame(
                "\n  AND (id NOT IN (1) AND category_id IN (2))",
                $this->service->getSqlConditionFandF([
                    'forbidden_categories' => 'id',
                    'visible_categories' => 'category_id',
                ], "\n  AND")
            );
        }

        public function test_sql_condition_fand_f_prefix_is_omitted_when_condition_is_empty(): void
        {
            CurrentUser::set(User::fromUserArray([]));

            self::assertSame('', $this->service->getSqlConditionFandF([
                'forbidden_categories' => 'id',
            ], "\n  AND"));
        }

        public function test_sql_condition_fand_f_visible_images_falls_through_to_level_check_for_id_field(): void
        {
            // same gate as the forbidden_images-only test below: a
            // non-default image_access_type is what opens the fallthrough.
            CurrentUser::set(User::fromUserArray(['level' => '3', 'image_access_type' => 'IN', 'image_access_list' => '']));
            \Piwigo\Core\FilterState::set(true, '', '7,8');

            self::assertSame('(id IN (7,8) AND level<=3)', $this->service->getSqlConditionFandF([
                'visible_images' => 'id',
            ]));
        }

        public function test_sql_condition_fand_f_forbidden_images_uses_i_dot_id_prefix(): void
        {
            // the outer gate ($imageAccessList !== '' || $imageAccessType
            // !== 'NOT IN') is false (and the whole condition skipped) when
            // both are at their getuserdata() defaults (empty list, 'NOT
            // IN' type) -- a non-default type is what opens the gate here.
            CurrentUser::set(User::fromUserArray(['level' => '5', 'image_access_type' => 'IN', 'image_access_list' => '']));

            self::assertSame('(i.level<=5)', $this->service->getSqlConditionFandF([
                'forbidden_images' => 'i.id',
            ]));
        }

        public function test_sql_condition_fand_f_forbidden_images_uses_access_list_for_other_fields(): void
        {
            CurrentUser::set(User::fromUserArray(['level' => '0', 'image_access_type' => 'IN', 'image_access_list' => '9,10']));

            self::assertSame('(image_id IN (9,10))', $this->service->getSqlConditionFandF([
                'forbidden_images' => 'image_id',
            ]));
        }
    }
}
