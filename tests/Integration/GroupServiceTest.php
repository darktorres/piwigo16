<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditRepository;
use Piwigo\Audit\AuditService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupRepository;
use Piwigo\Group\GroupService;

/**
 * Covers only the validation paths that fail before GroupService reaches
 * pwg_activity()/trigger_notify()/Piwigo\Cache\PermissionCacheInvalidator --
 * those need the full legacy request bootstrap (global $mysqli,
 * $persistent_cache, $logger from common.inc.php), which this lightweight
 * DBAL-only Integration harness deliberately doesn't load (same limitation
 * PermalinkServiceTest works around by only exercising l10n()-dependent
 * code, not DB-writing procedural side effects). The full create/update/
 * duplicate/merge/delete/addMembers/removeMembers success paths are
 * live-verified via ws.php against the running Apache instance instead --
 * see the P18 wrap-up memory for the exact commands used.
 */
final class GroupServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private GroupService $service;

    private GroupRepository $repo;

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

        $this->repo = \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Group\GroupEntity::class);
        $auditRepo = EntityManagerFactory::build()->getRepository(AuditLogEntity::class);
        $this->service = new GroupService($this->repo, new ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)), new AuditService($auditRepo), new ConfigService($this->buildConfigRepository()));
    }

    public function test_create_rejects_an_already_used_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This name is already used by another group.');

        $this->service->create('Editors', false);
    }

    public function test_create_rejects_an_empty_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Name field must not be empty');

        $this->service->create('   ', false);
    }

    public function test_duplicate_rejects_an_already_used_copy_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This name is already used by another group.');

        $this->service->duplicate(1, 'Editors');
    }

    public function test_duplicate_rejects_a_missing_source_group(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This group does not exist.');

        $this->service->duplicate(999999, 'p18-test-' . bin2hex(random_bytes(4)));
    }

    public function test_update_rejects_an_empty_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Name field must not be empty');

        $this->service->update(1, ['name' => '   ']);
    }

    public function test_update_rejects_a_missing_group(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This group does not exist.');

        $this->service->update(999999, ['name' => 'Anything']);
    }

    public function test_update_rejects_an_already_used_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This name is already used by another group.');

        $this->service->update(1, ['name' => 'Reviewers']);
    }

    public function test_add_members_returns_false_for_a_missing_group(): void
    {
        self::assertFalse($this->service->addMembers(999999, [1]));
    }

    public function test_remove_members_returns_false_for_a_missing_group(): void
    {
        self::assertFalse($this->service->removeMembers(999999, [1]));
    }

    public function test_merge_returns_false_when_a_group_is_missing(): void
    {
        self::assertFalse($this->service->merge(1, [999999]));
    }

    public function test_delete_returns_empty_array_when_no_ids_exist(): void
    {
        self::assertSame([], $this->service->delete([999999]));
    }

    public function test_get_name_returns_a_fixture_groups_name(): void
    {
        self::assertSame('Editors', $this->service->getName(1));
        self::assertNull($this->service->getName(999999));
    }

    public function test_get_authorized_category_ids_returns_fixture_access(): void
    {
        self::assertSame([1, 2], $this->service->getAuthorizedCategoryIds(1));
    }

    public function test_get_all_basic_returns_fixture_groups(): void
    {
        $names = array_column($this->service->getAllBasic(), 'name');

        self::assertSame(['Editors', 'Guests', 'Reviewers'], $names);
    }

    public function test_get_list_with_member_counts_returns_fixture_groups(): void
    {
        $rows = $this->service->getListWithMemberCounts();

        self::assertNotEmpty($rows);
    }
}
