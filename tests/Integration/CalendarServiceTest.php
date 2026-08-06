<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Override;
    use Piwigo\Auth\AccessLevelChecker;
    use Piwigo\Core\Kernel;
    use LogicException;
    use Piwigo\Core\FilterState;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Group\GroupEntity;
    use Piwigo\Core\Lang;
    use Piwigo\PluginConfig\EventDispatcher;
    use Piwigo\Lang\Translator;
    use Piwigo\Tests\Support\TranslatorTestFactory;
    use Doctrine\DBAL\Connection;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\CategoryService;
    use Piwigo\Calendar\CalendarService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;

/**
 * Same fixture shape as CategoryRepositoryTest: category 1 "Sample Album"
 * (root, 3 direct images), category 2 "Nested Sub Album" (child of 1, 2
 * direct images).
 */
final class CalendarServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CalendarService $service;

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
        $accessLevelChecker = new AccessLevelChecker(CurrentUser::current(), $currentConfig);
        $this->service = new CalendarService(
            new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig), CurrentUser::current(), $filterState, $accessLevelChecker),
            new CategoryService(
                Lang::current(),
                new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig),
                new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig), CurrentUser::current(), $filterState, $accessLevelChecker),
                CurrentConfig::current(),
                new EventDispatcher(),
                TranslatorTestFactory::get(),
                $accessLevelChecker
            )
        );

        // Matches getuserdata()'s own guaranteed shape -- an incomplete
        // fixture (e.g. missing 'level') lets getSqlConditionFandF()'s
        // forbidden_images fallthrough build a malformed 'level <=' fragment
        // with no right-hand value, same gotcha documented in
        // CategoryServiceTest/SearchServiceTest.
        CurrentUser::current()->set(User::fromUserArray([
            'id' => 1,
            'forbidden_categories' => '0',
            'level' => '0',
            'image_access_type' => 'NOT IN',
            'image_access_list' => '',
        ]));
    }

    public function test_build_inner_sql_for_a_specific_category(): void
    {
        // get_subcat_ids([1]) includes category 1's own subcategory (2),
        // not just 1 itself -- matches CategoryRepositoryTest's own
        // findSubcategoryIds([1]) === [1, 2] finding.
        $scope = $this->service->buildInnerSql('categories', true, 1, '', []);

        self::assertNotNull($scope);
        self::assertStringContainsString('category_id IN (:innerSubIds)', $scope->rawSqlFromWhere->sql);
        self::assertSame([1, 2], $scope->rawSqlFromWhere->parameters['innerSubIds']);
        self::assertStringContainsString('INNER JOIN', $scope->rawSqlFromWhere->sql);

        // DQL representation: same subcategory ids, DQL property path
        // (ic.categoryId) instead of the raw column name, join flag set.
        self::assertTrue($scope->joinImageCategory);
        self::assertStringContainsString('ic.categoryId IN (:innerSubIds)', $scope->dqlWhere->sql);
        self::assertSame([1, 2], $scope->dqlWhere->parameters['innerSubIds']);
    }

    public function test_build_inner_sql_returns_null_when_the_category_has_no_subcategories_left(): void
    {
        // category 999999 doesn't exist -- get_subcat_ids() finds nothing,
        // so the sub_ids set is empty (same "nothing to do" early return
        // as the original function).
        $scope = $this->service->buildInnerSql('categories', true, 999999, '', []);

        self::assertNull($scope);
    }

    public function test_build_inner_sql_returns_null_when_category_context_is_malformed(): void
    {
        // hasCategoryContext=true but categoryId=null (a $page['category']
        // present but missing a valid 'id') must take the "specific
        // category" branch's own empty-sub_ids early return, not silently
        // fall through to "browse everything visible".
        $scope = $this->service->buildInnerSql('categories', true, null, '', []);

        self::assertNull($scope);
    }

    public function test_build_inner_sql_excludes_forbidden_subcategories(): void
    {
        // category 1's only subcategory (besides itself) is 2 -- marking 2
        // forbidden must remove it from the sub_ids set without touching
        // category 1 itself.
        $scope = $this->service->buildInnerSql('categories', true, 1, '2', []);

        self::assertNotNull($scope);
        self::assertSame([1], $scope->rawSqlFromWhere->parameters['innerSubIds']);
        self::assertSame([1], $scope->dqlWhere->parameters['innerSubIds']);
    }

    public function test_build_inner_sql_for_browsing_everything_visible(): void
    {
        $scope = $this->service->buildInnerSql('categories', false, null, '', []);

        self::assertNotNull($scope);
        self::assertStringContainsString('WHERE', $scope->rawSqlFromWhere->sql);
        self::assertStringContainsString('INNER JOIN', $scope->rawSqlFromWhere->sql);
        self::assertTrue($scope->joinImageCategory);
        // Every real condition below references ic.categoryId/i.id/i.level
        // (DQL property paths), never the raw ic.category_id/id/level
        // column names the raw-SQL side uses.
        self::assertStringContainsString('ic.categoryId', $scope->dqlWhere->sql);
    }

    public function test_build_inner_sql_for_an_explicit_item_list(): void
    {
        $scope = $this->service->buildInnerSql('items', false, null, '', [1, 2, 3]);

        self::assertNotNull($scope);
        self::assertStringContainsString('WHERE id IN (:innerItems)', $scope->rawSqlFromWhere->sql);
        self::assertSame(['1', '2', '3'], $scope->rawSqlFromWhere->parameters['innerItems']);

        self::assertFalse($scope->joinImageCategory);
        self::assertSame('i.id IN (:innerItems)', $scope->dqlWhere->sql);
        self::assertSame([1, 2, 3], $scope->dqlWhere->parameters['innerItems']);
    }

    public function test_build_inner_sql_returns_null_for_an_empty_item_list(): void
    {
        self::assertNull($this->service->buildInnerSql('items', false, null, '', []));
    }
}
}
