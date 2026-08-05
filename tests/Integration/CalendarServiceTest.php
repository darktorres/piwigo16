<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\CategoryService;
    use Piwigo\Calendar\CalendarService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Group\GroupRepository;
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

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->service = new CalendarService(
            new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class)),
            new CategoryService(
                \Piwigo\Core\Lang::current(),
                \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class),
                new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class)),
                \Piwigo\Config\CurrentConfig::current()
            )
        );

        // Matches getuserdata()'s own guaranteed shape -- an incomplete
        // fixture (e.g. missing 'level') lets getSqlConditionFandF()'s
        // forbidden_images fallthrough build a malformed 'level<=' fragment
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
        $sql = $this->service->buildInnerSql('categories', true, 1, '', []);

        self::assertNotNull($sql);
        self::assertStringContainsString('category_id IN (:innerSubIds)', $sql->sql);
        self::assertSame([1, 2], $sql->parameters['innerSubIds']);
        self::assertStringContainsString('INNER JOIN', $sql->sql);
    }

    public function test_build_inner_sql_returns_null_when_the_category_has_no_subcategories_left(): void
    {
        // category 999999 doesn't exist -- get_subcat_ids() finds nothing,
        // so the sub_ids set is empty (same "nothing to do" early return
        // as the original function).
        $sql = $this->service->buildInnerSql('categories', true, 999999, '', []);

        self::assertNull($sql);
    }

    public function test_build_inner_sql_returns_null_when_category_context_is_malformed(): void
    {
        // hasCategoryContext=true but categoryId=null (a $page['category']
        // present but missing a valid 'id') must take the "specific
        // category" branch's own empty-sub_ids early return, not silently
        // fall through to "browse everything visible".
        $sql = $this->service->buildInnerSql('categories', true, null, '', []);

        self::assertNull($sql);
    }

    public function test_build_inner_sql_excludes_forbidden_subcategories(): void
    {
        // category 1's only subcategory (besides itself) is 2 -- marking 2
        // forbidden must remove it from the sub_ids set without touching
        // category 1 itself.
        $sql = $this->service->buildInnerSql('categories', true, 1, '2', []);

        self::assertNotNull($sql);
        self::assertSame([1], $sql->parameters['innerSubIds']);
    }

    public function test_build_inner_sql_for_browsing_everything_visible(): void
    {
        $sql = $this->service->buildInnerSql('categories', false, null, '', []);

        self::assertNotNull($sql);
        self::assertStringContainsString('WHERE', $sql->sql);
        self::assertStringContainsString('INNER JOIN', $sql->sql);
    }

    public function test_build_inner_sql_for_an_explicit_item_list(): void
    {
        $sql = $this->service->buildInnerSql('items', false, null, '', [1, 2, 3]);

        self::assertNotNull($sql);
        self::assertStringContainsString('WHERE id IN (:innerItems)', $sql->sql);
        self::assertSame(['1', '2', '3'], $sql->parameters['innerItems']);
    }

    public function test_build_inner_sql_returns_null_for_an_empty_item_list(): void
    {
        self::assertNull($this->service->buildInnerSql('items', false, null, '', []));
    }
}
}
