<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Group\GroupService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;

/**
 * Most of this file covers only the validation paths that fail before
 * GroupService reaches pwg_activity()/trigger_notify()/
 * Piwigo\Cache\PermissionCacheInvalidator -- those need the full legacy
 * request bootstrap (global $mysqli, $persistent_cache, $logger from
 * common.inc.php), which this lightweight DBAL-only Integration harness
 * deliberately doesn't load. The full create/update/duplicate/merge/
 * delete/addMembers/removeMembers success paths are live-verified via
 * /api/v1/groups against the running Apache instance instead (the WS
 * layer this docblock originally verified through is deleted, P27).
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
        $this->configService = new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get());
        $this->service = new GroupService($this->repo, new ActivityService(EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class)), new AuditService($auditRepo), $this->configService, CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());

        // Only addAccess()/duplicate()/merge() need this (see class docblock)
        // -- PermissionCacheInvalidator::invalidate() -> its own private
        // currentConfigService()->get()
        // would otherwise throw "not initialised" the moment any of their
        // real success paths run.
        CurrentConfigServiceTestFactory::get()->set(new ConfigService(EntityManagerFactory::build($this->conn)->getRepository(ConfigEntry::class), CurrentConfigTestFactory::get()));
    }

    public function testCreateRejectsAnAlreadyUsedName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('This name is already used by another group.');

        $this->service->create('Editors', false);
    }

    public function testCreateRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Name field must not be empty');

        $this->service->create('   ', false);
    }

    public function testDuplicateRejectsAnAlreadyUsedCopyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('This name is already used by another group.');

        $this->service->duplicate(GroupId::from(1), 'Editors');
    }

    public function testDuplicateRejectsAMissingSourceGroup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('This group does not exist.');

        $this->service->duplicate(GroupId::from(999999), 'p18-test-' . bin2hex(random_bytes(4)));
    }

    public function testUpdateRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Name field must not be empty');

        $this->service->update(GroupId::from(1), [
            'name' => '   ',
        ]);
    }

    public function testUpdateRejectsAMissingGroup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('This group does not exist.');

        $this->service->update(GroupId::from(999999), [
            'name' => 'Anything',
        ]);
    }

    public function testUpdateRejectsAnAlreadyUsedName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('This name is already used by another group.');

        $this->service->update(GroupId::from(1), [
            'name' => 'Reviewers',
        ]);
    }

    public function testAddMembersReturnsFalseForAMissingGroup(): void
    {
        self::assertFalse($this->service->addMembers(GroupId::from(999999), [UserId::from(1)]));
    }

    public function testRemoveMembersReturnsFalseForAMissingGroup(): void
    {
        self::assertFalse($this->service->removeMembers(GroupId::from(999999), [UserId::from(1)]));
    }

    public function testMergeReturnsFalseWhenAGroupIsMissing(): void
    {
        self::assertFalse($this->service->merge(GroupId::from(1), [GroupId::from(999999)]));
    }

    public function testDeleteReturnsEmptyArrayWhenNoIdsExist(): void
    {
        self::assertSame([], $this->service->delete([GroupId::from(999999)]));
    }

    public function testGetNameReturnsAFixtureGroupsName(): void
    {
        self::assertSame('Editors', $this->service->getName(GroupId::from(1)));
        self::assertNull($this->service->getName(GroupId::from(999999)));
    }

    public function testGetAuthorizedCategoryIdsReturnsFixtureAccess(): void
    {
        $ids = $this->service->getAuthorizedCategoryIds(GroupId::from(1));

        self::assertSame([1, 2], array_map(static fn (CategoryId $id): int => $id->value, $ids));
    }

    public function testGetAllBasicReturnsFixtureGroups(): void
    {
        $names = array_column($this->service->getAllBasic(), 'name');

        self::assertSame(['Editors', 'Guests', 'Reviewers'], $names);
    }

    public function testGetListWithMemberCountsReturnsFixtureGroups(): void
    {
        $rows = $this->service->getListWithMemberCounts();

        self::assertNotEmpty($rows);
    }

    public function testGetMemberUsernamesDelegatesToTheRepository(): void
    {
        $names = $this->service->getMemberUsernames(GroupId::from(1));

        self::assertSame(['fixture_admin', 'regular_user'], $names);
    }

    public function testDeleteWithNoIdsTriggersAWarningAndReturnsFalse(): void
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

    public function testDeleteFlipsEmailAdminOnNewUserToAllWhenTheConfiguredGroupIsAmongTheRequestedIds(): void
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

    public function testDeleteLeavesEmailAdminOnNewUserUntouchedWhenNoRequestedIdMatchesTheConfiguredGroup(): void
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

    public function testRemoveAccessRevokesOnlyTheRequestedCategorysAccess(): void
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

    public function testAddAccessAuthorizesNewCategoriesThenIsANoopOnceAllAreAlreadyAuthorized(): void
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

    public function testAddAccessAuthorizesOnlyTheNotYetAuthorizedCategoriesFromAMixedRequest(): void
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

    public function testDuplicateCopiesMembersAndLogsAUserEditActivityAssociatedWithTheSourceGroup(): void
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

    public function testMergeLogsAUserEditActivityAssociatedWithTheDestinationGroupForEachMovedMember(): void
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
            "DELETE FROM activity WHERE object = 'user' AND action = 'edit' AND {$detailsColumn} LIKE :assoc",
            [
                'assoc' => '%"associated": ' . $associatedGroupId . '%',
            ]
        );
    }
}
