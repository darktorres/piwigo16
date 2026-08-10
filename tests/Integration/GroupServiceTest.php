<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Group\GroupEntity;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use InvalidArgumentException;
use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityService;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupRepository;
use Piwigo\Group\GroupService;

/**
 * Most of this file covers only the validation paths that fail before
 * GroupService reaches pwg_activity()/trigger_notify()/
 * Piwigo\Cache\PermissionCacheInvalidator -- those need the full legacy
 * request bootstrap (global $mysqli, $persistent_cache, $logger from
 * common.inc.php), which this lightweight DBAL-only Integration harness
 * deliberately doesn't load. The full create/update/duplicate/merge/
 * delete/addMembers/removeMembers success paths are live-verified via
 * ws.php against the running Apache instance instead.
 *
 * The specific gaps below (duplicate()'s and merge()'s own per-member
 * activity-logging loop, addAccess()) are the one real exception: they
 * only need PermissionCacheInvalidator::invalidate() -> its own private
 * currentConfigService()->get()
 * wired, the same one missing piece UserServiceTest's own setUp() already
 * documents fixing the same way -- everything else those methods touch
 * (repo writes, the real ActivityService instance already constructed
 * above, EventDispatcher's own lazy no-handler-registered default) works
 * standalone in this harness already.
 */
final class GroupServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private GroupService $service;

    private GroupRepository $repo;

    private ConfigService $configService;

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

        $this->conn = DbConnection::build();
        $this->repo = EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class);
        $auditRepo = EntityManagerFactory::build()->getRepository(AuditLogEntity::class);
        $this->configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get());
        $this->service = new GroupService($this->repo, new ActivityService(EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class)), new AuditService($auditRepo), $this->configService, new EventDispatcher(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());

        // Only addAccess()/duplicate()/merge() need this (see class docblock)
        // -- PermissionCacheInvalidator::invalidate() -> its own private
        // currentConfigService()->get()
        // would otherwise throw "not initialised" the moment any of their
        // real success paths run.
        CurrentConfigServiceTestFactory::get()->set(new ConfigService(EntityManagerFactory::build($this->conn)->getRepository(ConfigEntry::class), new EventDispatcher(), CurrentConfigTestFactory::get()));
    }

    public function test_create_rejects_an_already_used_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('This name is already used by another group.');

        $this->service->create('Editors', false);
    }

    public function test_create_rejects_an_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Name field must not be empty');

        $this->service->create('   ', false);
    }

    public function test_duplicate_rejects_an_already_used_copy_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('This name is already used by another group.');

        $this->service->duplicate(GroupId::from(1), 'Editors');
    }

    public function test_duplicate_rejects_a_missing_source_group(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('This group does not exist.');

        $this->service->duplicate(GroupId::from(999999), 'p18-test-' . bin2hex(random_bytes(4)));
    }

    public function test_update_rejects_an_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Name field must not be empty');

        $this->service->update(GroupId::from(1), ['name' => '   ']);
    }

    public function test_update_rejects_a_missing_group(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('This group does not exist.');

        $this->service->update(GroupId::from(999999), ['name' => 'Anything']);
    }

    public function test_update_rejects_an_already_used_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('This name is already used by another group.');

        $this->service->update(GroupId::from(1), ['name' => 'Reviewers']);
    }

    public function test_add_members_returns_false_for_a_missing_group(): void
    {
        self::assertFalse($this->service->addMembers(GroupId::from(999999), [UserId::from(1)]));
    }

    public function test_remove_members_returns_false_for_a_missing_group(): void
    {
        self::assertFalse($this->service->removeMembers(GroupId::from(999999), [UserId::from(1)]));
    }

    public function test_merge_returns_false_when_a_group_is_missing(): void
    {
        self::assertFalse($this->service->merge(GroupId::from(1), [GroupId::from(999999)]));
    }

    public function test_delete_returns_empty_array_when_no_ids_exist(): void
    {
        self::assertSame([], $this->service->delete([GroupId::from(999999)]));
    }

    public function test_get_name_returns_a_fixture_groups_name(): void
    {
        self::assertSame('Editors', $this->service->getName(GroupId::from(1)));
        self::assertNull($this->service->getName(GroupId::from(999999)));
    }

    public function test_get_authorized_category_ids_returns_fixture_access(): void
    {
        $ids = $this->service->getAuthorizedCategoryIds(GroupId::from(1));

        self::assertSame([1, 2], array_map(static fn (CategoryId $id): int => $id->value, $ids));
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

    public function test_get_member_usernames_delegates_to_the_repository(): void
    {
        $names = $this->service->getMemberUsernames(GroupId::from(1));

        self::assertSame(['fixture_admin', 'regular_user'], $names);
    }

    public function test_delete_with_no_ids_triggers_a_warning_and_returns_false(): void
    {
        // trigger_error(E_USER_WARNING) would otherwise trip
        // failOnWarning="true" -- a real no-op error handler for the
        // duration of this one expected-to-warn call is the reliable way
        // to swallow it (a plain @ does not stop PHPUnit's ErrorHandler),
        // matching UserServiceTest's own established pattern.
        set_error_handler(static fn (): bool => true);
        try {
            $result = $this->service->delete([]);
        } finally {
            restore_error_handler();
        }

        self::assertFalse($result);
    }

    public function test_delete_flips_email_admin_on_new_user_to_all_when_the_configured_group_is_among_the_requested_ids(): void
    {
        // 999999 isn't a real group (groups only has ids 1-3), so
        // repo->delete() finds nothing and delete() returns [] the same
        // way test_delete_returns_empty_array_when_no_ids_exist() already
        // does -- this only isolates the email_admin_on_new_user
        // consistency-write branch, which runs *before* that repo call.
        $this->configService->confUpdateParam('email_admin_on_new_user', 'group:999999', true);

        try {
            $result = $this->service->delete([GroupId::from(999999)]);

            self::assertSame([], $result);
            self::assertSame('all', CurrentConfigTestFactory::get()->emailAdminOnNewUser);
        } finally {
            $this->configService->confUpdateParam('email_admin_on_new_user', 'none', true);
        }
    }

    public function test_delete_leaves_email_admin_on_new_user_untouched_when_no_requested_id_matches_the_configured_group(): void
    {
        $this->configService->confUpdateParam('email_admin_on_new_user', 'group:555555', true);

        try {
            $result = $this->service->delete([GroupId::from(999999)]);

            self::assertSame([], $result);
            self::assertSame('group:555555', CurrentConfigTestFactory::get()->emailAdminOnNewUser);
        } finally {
            $this->configService->confUpdateParam('email_admin_on_new_user', 'none', true);
        }
    }

    public function test_remove_access_revokes_only_the_requested_categorys_access(): void
    {
        $groupId = $this->service->create('p18-test-remove-access-' . bin2hex(random_bytes(4)), false);

        try {
            $this->service->addAccess($groupId, [CategoryId::from(1), CategoryId::from(2)]);

            $this->service->removeAccess($groupId, [CategoryId::from(1)]);

            $ids = array_map(static fn (CategoryId $id): int => $id->value, $this->service->getAuthorizedCategoryIds($groupId));
            self::assertSame([2], $ids);
        } finally {
            $this->repo->delete([$groupId]);
        }
    }

    public function test_add_access_authorizes_new_categories_then_is_a_noop_once_all_are_already_authorized(): void
    {
        $groupId = $this->service->create('p18-test-add-access-' . bin2hex(random_bytes(4)), false);

        try {
            // First call: both categories are new -> the real apply path
            // (repo write + PermissionCacheInvalidator::invalidate()).
            $this->service->addAccess($groupId, [CategoryId::from(1), CategoryId::from(2)]);

            $ids = array_map(static fn (CategoryId $id): int => $id->value, $this->service->getAuthorizedCategoryIds($groupId));
            sort($ids);
            self::assertSame([1, 2], $ids);

            // Second call: same two categories, already fully authorized ->
            // array_diff() leaves nothing to authorize, an early return
            // before ever touching the repository or the cache invalidator.
            $this->service->addAccess($groupId, [CategoryId::from(1), CategoryId::from(2)]);

            $idsAfter = array_map(static fn (CategoryId $id): int => $id->value, $this->service->getAuthorizedCategoryIds($groupId));
            sort($idsAfter);
            self::assertSame([1, 2], $idsAfter);
        } finally {
            $this->repo->delete([$groupId]);
        }
    }

    public function test_add_access_authorizes_only_the_not_yet_authorized_categories_from_a_mixed_request(): void
    {
        $groupId = $this->service->create('p18-test-add-access-mixed-' . bin2hex(random_bytes(4)), false);

        try {
            $this->service->addAccess($groupId, [CategoryId::from(1)]);

            // Category 1 is already authorized, category 2 is not -- only
            // category 2 should actually reach the repository.
            $this->service->addAccess($groupId, [CategoryId::from(1), CategoryId::from(2)]);

            $ids = array_map(static fn (CategoryId $id): int => $id->value, $this->service->getAuthorizedCategoryIds($groupId));
            sort($ids);
            self::assertSame([1, 2], $ids);
        } finally {
            $this->repo->delete([$groupId]);
        }
    }

    public function test_duplicate_copies_members_and_logs_a_user_edit_activity_associated_with_the_source_group(): void
    {
        $sourceId = $this->service->create('p18-test-dup-source-' . bin2hex(random_bytes(4)), false);
        $this->service->addMembers($sourceId, [UserId::from(1), UserId::from(4)]);

        $copyId = null;
        try {
            $copyId = $this->service->duplicate($sourceId, 'p18-test-dup-copy-' . bin2hex(random_bytes(4)));

            $copyMembers = array_map(static fn (UserId $id): int => $id->value, $this->repo->findMemberUserIds($copyId));
            sort($copyMembers);
            self::assertSame([1, 4], $copyMembers);

            foreach ([1, 4] as $userId) {
                $details = $this->fetchUserEditActivityAssociatedWith($userId, $sourceId->value);
                self::assertIsString(
                    $details,
                    "Expected a user/{$userId}/edit activity row associated with source group {$sourceId->value}"
                );
            }
        } finally {
            $this->deleteUserEditActivityAssociatedWith($sourceId->value);
            $this->repo->delete(array_filter([$sourceId, $copyId]));
        }
    }

    public function test_merge_logs_a_user_edit_activity_associated_with_the_destination_group_for_each_moved_member(): void
    {
        $destinationId = $this->service->create('p18-test-merge-dest-' . bin2hex(random_bytes(4)), false);
        $sourceId = $this->service->create('p18-test-merge-source-' . bin2hex(random_bytes(4)), false);
        $this->service->addMembers($sourceId, [UserId::from(4)]);

        try {
            $result = $this->service->merge($destinationId, [$sourceId]);
            self::assertTrue($result);

            $destinationMembers = array_map(static fn (UserId $id): int => $id->value, $this->repo->findMemberUserIds($destinationId));
            self::assertSame([4], $destinationMembers);

            $details = $this->fetchUserEditActivityAssociatedWith(4, $destinationId->value);
            self::assertIsString(
                $details,
                "Expected a user/4/edit activity row associated with destination group {$destinationId->value}"
            );

            // merge() already deleted the source group itself -- confirm
            // it's really gone rather than just assuming.
            self::assertNull($this->repo->findName($sourceId));
        } finally {
            $this->deleteUserEditActivityAssociatedWith($destinationId->value);
            $this->repo->delete([$destinationId]);
        }
    }

    private function fetchUserEditActivityAssociatedWith(int $userId, int $associatedGroupId): ?string
    {
        // activity.details is a genuine json/jsonb column on both
        // platforms -- LIKE against it directly errors on Postgres
        // ("operator does not exist: jsonb ~~ unknown"), needs an
        // explicit ::text cast first (same real gap already fixed in
        // RequestBootstrapConnectTest).
        $detailsColumn = $this->dbDriver === 'pgsql' ? 'details::text' : 'details';
        $value = $this->conn->createQueryBuilder()
            ->select('details')
            ->from('activity')
            ->where('object = \'user\'')
            ->andWhere('object_id = :userId')
            ->andWhere('action = \'edit\'')
            ->andWhere($detailsColumn . ' LIKE :assoc')
            ->setParameter('userId', $userId)
            // MySQL's JSON column type canonicalizes its stored value on
            // read-back with a space after each colon ('"associated": 6',
            // not json_encode()'s compact '"associated":6') -- confirmed
            // live, without this the LIKE pattern never matches a real row.
            // PostgreSQL's own jsonb::text canonical output format uses
            // the same "colon space" convention, so the pattern itself
            // needs no further change.
            ->setParameter('assoc', '%"associated": ' . $associatedGroupId . '%')
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    private function deleteUserEditActivityAssociatedWith(int $associatedGroupId): void
    {
        $detailsColumn = $this->dbDriver === 'pgsql' ? 'details::text' : 'details';
        $this->conn->executeStatement(
            'DELETE FROM ' . 'activity' . " WHERE object = 'user' AND action = 'edit' AND {$detailsColumn} LIKE :assoc",
            ['assoc' => '%"associated": ' . $associatedGroupId . '%']
        );
    }
}
