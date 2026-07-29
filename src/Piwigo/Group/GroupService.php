<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Piwigo\Audit\AuditService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivityLoggerInterface;

/**
 * Group domain business logic: creation/rename/deletion, membership
 * management, merge/duplicate. Constructor-injects GroupRepository (plain
 * constructor injection, same shape as PermalinkService) and
 * ActivityLoggerInterface (P23 batch 8d -- pwg_activity()'s real target,
 * Piwigo\Activity\ActivityService, is L2bExtendedDomain; this class is
 * L2aCoreDomain, so it depends on the L1Infrastructure interface instead,
 * same shape as MailerInterface -- see ActivityLoggerInterface's own
 * docblock), and calls Piwigo\Cache\PermissionCacheInvalidator (L1Infrastructure,
 * P23 batch 8d) directly for cache invalidation -- a real class dependency,
 * always allowed (L2a may depend on L1). Also constructor-injects
 * ConfigService (L1Infrastructure, Phase 5 Legacy Coupling Retirement) for
 * delete()'s email_admin_on_new_user consistency write.
 *
 * Ids are typed VOs throughout this class's own public surface, matching
 * GroupRepository. `ActivityLoggerInterface`/`AuditService`/`EventDispatcher`
 * aren't being converted in this pass, so every call into them unwraps
 * `->value` right before the call.
 */
final readonly class GroupService
{
    public function __construct(
        private GroupRepository $repo,
        private ActivityLoggerInterface $activityLogger,
        private AuditService $auditService,
        private ConfigService $configService,
    ) {}

    /**
     * @return list<\Piwigo\Group\Projection\Group>
     */
    public function getAllBasic(): array
    {
        return $this->repo->findAllBasic();
    }

    /**
     * @param list<int> $groupIds
     * @return list<array<string, mixed>>
     */
    public function getMembersByGroupIds(array $groupIds): array
    {
        return $this->repo->findMembersByGroupIds($groupIds);
    }

    /**
     * @param  list<int>  $groupIds
     * @return array<int, string>
     */
    public function getNamesByIds(array $groupIds): array
    {
        return $this->repo->findNamesByIds($groupIds);
    }

    public function countAll(): int
    {
        return $this->repo->countAll();
    }

    /**
     * @return list<int>
     */
    public function getIdsByNameLike(string $namePattern): array
    {
        return $this->repo->findIdsByNameLike($namePattern);
    }

    /**
     * @param  list<int>  $userIds
     * @return list<array<string, mixed>>
     */
    public function getMembershipsForUserIds(array $userIds): array
    {
        return $this->repo->findMembershipsForUserIds($userIds);
    }

    /**
     * @return list<string>
     */
    public function getMemberUsernames(GroupId $groupId, string $usernameColumn, string $idColumn): array
    {
        return $this->repo->findMemberUsernames($groupId, $usernameColumn, $idColumn);
    }

    /**
     * @param list<GroupId> $groupIds
     * @return list<array{id: int, name: string, is_default: bool, lastmodified: string, nb_users: int}>
     */
    public function getListWithMemberCounts(
        array $groupIds = [],
        ?string $nameLike = null,
        string $order = 'name ASC',
        int $perPage = 9999999,
        int $page = 0
    ): array {
        return $this->repo->findWithMemberCounts($groupIds, $nameLike, $order, $perPage, $page);
    }

    /**
     * Creates a group. Throws InvalidArgumentException, message matching
     * one of ws_groups_add()'s two distinct validation failures in the
     * same order (name-already-used checked before empty-name) -- callers
     * translate the message into their own error shape (e.g. PwgError).
     */
    public function create(string $name, bool $isDefault): GroupId
    {
        if ($this->repo->nameExists($name)) {
            throw new \InvalidArgumentException('This name is already used by another group.');
        }

        if (str_replace(' ', '', $name) === '') {
            throw new \InvalidArgumentException('Name field must not be empty');
        }

        $id = $this->repo->insert($name, $isDefault);
        $this->activityLogger->record('group', $id->value, 'add');

        return $id;
    }

    /**
     * Creates a copy of an existing group (same is_default, same members),
     * under a new name. Throws InvalidArgumentException matching
     * ws_groups_duplicate()'s two validation failures -- note: unlike
     * create(), there is no separate empty-name check here, matching the
     * original.
     */
    public function duplicate(GroupId $groupId, string $copyName): GroupId
    {
        if ($this->repo->nameExists($copyName)) {
            throw new \InvalidArgumentException('This name is already used by another group.');
        }

        if (! $this->repo->exists($groupId)) {
            throw new \InvalidArgumentException('This group does not exist.');
        }

        $newId = $this->repo->insert($copyName, $this->repo->isDefault($groupId));
        $this->activityLogger->record('group', $newId->value, 'add');

        $memberIds = $this->repo->findMemberUserIds($groupId);
        $this->repo->addMembers($newId, $memberIds);
        PermissionCacheInvalidator::invalidate();

        // Matches the original ws_groups_duplicate()'s own (likely
        // accidental, but faithfully preserved) choice of 'associated' id:
        // the *source* group being copied from, not the newly created copy.
        foreach ($memberIds as $userId) {
            $this->activityLogger->record('user', $userId->value, 'edit', [
                'associated' => $groupId->value,
            ]);
        }

        return $newId;
    }

    /**
     * Renames and/or flips is_default for a group. Throws
     * InvalidArgumentException matching ws_groups_setInfo()'s three
     * validation failures, in the same order: empty name, group missing,
     * name already used.
     *
     * @param array{name?: string, is_default?: bool} $updates
     */
    public function update(GroupId $groupId, array $updates): void
    {
        if (isset($updates['name']) && str_replace(' ', '', $updates['name']) === '') {
            throw new \InvalidArgumentException('Name field must not be empty');
        }

        if (! $this->repo->exists($groupId)) {
            throw new \InvalidArgumentException('This group does not exist.');
        }

        // Matches the original ws_groups_setInfo()'s own `! empty(...)`
        // guard exactly: a "0" name is treated as absent too, not just "".
        $hasName = isset($updates['name']) && $updates['name'] !== '' && $updates['name'] !== '0';
        if ($hasName && $this->repo->nameExists($updates['name'], $groupId)) {
            throw new \InvalidArgumentException('This name is already used by another group.');
        }

        $this->repo->update($groupId, $updates);
        $this->activityLogger->record('group', $groupId->value, 'edit');
    }

    /**
     * Adds users to a group. Returns false when the group doesn't exist.
     *
     * @param list<UserId> $userIds
     */
    public function addMembers(GroupId $groupId, array $userIds): bool
    {
        if (! $this->repo->exists($groupId)) {
            return false;
        }

        $this->repo->addMembers($groupId, $userIds);
        PermissionCacheInvalidator::invalidate();

        $this->activityLogger->record('group', $groupId->value, 'edit');
        $this->activityLogger->record('user', array_map(static fn (UserId $id): int => $id->value, $userIds), 'edit');

        return true;
    }

    /**
     * Removes users from a group. Returns false when the group doesn't exist.
     *
     * @param list<UserId> $userIds
     */
    public function removeMembers(GroupId $groupId, array $userIds): bool
    {
        if (! $this->repo->exists($groupId)) {
            return false;
        }

        $this->repo->removeMembers($groupId, $userIds);
        PermissionCacheInvalidator::invalidate();

        $this->activityLogger->record('group', $groupId->value, 'edit');
        $this->activityLogger->record('user', array_map(static fn (UserId $id): int => $id->value, $userIds), 'edit');

        return true;
    }

    /**
     * Merges $mergeGroupIds into $destinationGroupId: every member of a
     * merged group who isn't already in the destination gets added to it,
     * then the merged groups are deleted. Returns false when any of the
     * involved groups (destination + merge sources) don't exist.
     *
     * array_unique()/array_diff() below compare GroupId/UserId objects via
     * their Stringable form (PHP's default SORT_STRING comparison) --
     * correct here since each VO's __toString() is its canonical numeric
     * value, same identity the original int comparison relied on.
     *
     * @param list<GroupId> $mergeGroupIds
     */
    public function merge(GroupId $destinationGroupId, array $mergeGroupIds): bool
    {
        $allGroupIds = array_values(array_unique([...$mergeGroupIds, $destinationGroupId]));
        if (count($this->repo->findExistingIds($allGroupIds)) !== count($allGroupIds)) {
            return false;
        }

        $mergeSourceIds = array_values(array_diff($mergeGroupIds, [$destinationGroupId]));

        $membersInMergeGroups = [];
        foreach ($mergeSourceIds as $mergeGroupId) {
            $membersInMergeGroups = array_merge($membersInMergeGroups, $this->repo->findMemberUserIds($mergeGroupId));
        }
        $membersInMergeGroups = array_unique($membersInMergeGroups);

        $membersInDestination = $this->repo->findMemberUserIds($destinationGroupId);
        $membersToAdd = array_values(array_diff($membersInMergeGroups, $membersInDestination));

        $this->repo->addMembers($destinationGroupId, $membersToAdd);
        // Unconditional, matching the original ws_groups_merge(): the
        // cache invalidation and the destination group's own 'edit'
        // activity fire even when no members actually moved.
        PermissionCacheInvalidator::invalidate();
        $this->activityLogger->record('group', $destinationGroupId->value, 'edit');

        foreach ($membersToAdd as $userId) {
            $this->activityLogger->record('user', $userId->value, 'edit', [
                'associated' => $destinationGroupId->value,
            ]);
        }

        $this->delete($mergeSourceIds);

        return true;
    }

    /**
     * Deletes the given groups. Returns id => name of every group actually
     * deleted (empty array when none of the ids existed), or false when
     * $groupIds is empty. Absorbs the former delete_groups() free
     * function's own extra orchestration (P23 batch 8d): the
     * email_admin_on_new_user config-consistency check and the [SEC-57]
     * per-group audit trail. Does not itself invalidate the user cache --
     * callers that need it (ws_groups_delete()) call
     * Piwigo\Cache\PermissionCacheInvalidator::invalidate() themselves
     * afterward, same as before.
     *
     * @param list<GroupId> $groupIds
     * @return false|array<int, string>
     */
    public function delete(array $groupIds): false|array
    {
        if (count($groupIds) === 0) {
            trigger_error('There is no group to delete', E_USER_WARNING);
            return false;
        }

        $emailAdminOnNewUser = \Piwigo\Config\CurrentConfig::emailAdminOnNewUser();
        if ((bool) preg_match('/^group:(\d+)$/', $emailAdminOnNewUser, $matches)) {
            foreach ($groupIds as $groupId) {
                if ($groupId->value === (int) $matches[1]) {
                    $this->configService->confUpdateParam('email_admin_on_new_user', 'all', true);
                }
            }
        }

        $deleted = $this->repo->delete($groupIds);
        if ($deleted === []) {
            return [];
        }

        $ids = array_keys($deleted);
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('delete_group', $ids);
        $this->activityLogger->record('group', $ids, 'delete');

        // [SEC-57] one row per group actually deleted
        $actorId = \Piwigo\Users\CurrentUser::get()->id->value;
        $audit = $this->auditService;
        foreach ($deleted as $deletedId => $deletedName) {
            $audit->record($actorId, 'delete', 'group', $deletedId, [
                'name' => $deletedName,
            ], null);
        }

        return $deleted;
    }

    public function getName(GroupId $groupId): ?string
    {
        return $this->repo->findName($groupId);
    }

    /**
     * @return list<CategoryId>
     */
    public function getAuthorizedCategoryIds(GroupId $groupId): array
    {
        return $this->repo->getAuthorizedCategoryIds($groupId);
    }

    /**
     * Forbids access to the given categories for a group.
     *
     * @param list<CategoryId> $catIds
     */
    public function removeAccess(GroupId $groupId, array $catIds): void
    {
        $this->repo->removeAccess($groupId, $catIds);
    }

    /**
     * Authorizes access to the given categories for a group, skipping ones
     * already authorized.
     *
     * @param list<CategoryId> $catIds
     */
    public function addAccess(GroupId $groupId, array $catIds): void
    {
        $alreadyAuthorized = $this->repo->getAuthorizedCategoryIds($groupId);
        $toAuthorize = array_values(array_diff($catIds, $alreadyAuthorized));
        if ($toAuthorize === []) {
            return;
        }

        $this->repo->addAccess($groupId, $toAuthorize);
        PermissionCacheInvalidator::invalidate();
    }
}
