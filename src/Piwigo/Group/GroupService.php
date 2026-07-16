<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Piwigo\Audit\AuditRepository;
use Piwigo\Audit\AuditService;
use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Db\DbConnection;

/**
 * Group domain business logic: creation/rename/deletion, membership
 * management, merge/duplicate. Constructor-injects GroupRepository (plain
 * constructor injection, same shape as PermalinkService) and
 * ActivityLoggerInterface (P23 batch 8d -- pwg_activity()'s real target,
 * Piwigo\Activity\ActivityService, is L2bExtendedDomain; this class is
 * L2aCoreDomain, so it depends on the L1Infrastructure interface instead,
 * same shape as MailerInterface -- see ActivityLoggerInterface's own
 * docblock), and calls Piwigo\Cache\UserCacheInvalidator (L1Infrastructure,
 * P23 batch 8d) directly for cache invalidation -- a real class dependency,
 * always allowed (L2a may depend on L1).
 */
final class GroupService
{
    public function __construct(
        private readonly GroupRepository $repo,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {}

    /**
     * @return list<array{id: int, name: string, is_default: bool}>
     */
    public function getAllBasic(): array
    {
        return $this->repo->findAllBasic();
    }

    /**
     * @return list<string>
     */
    public function getMemberUsernames(int $groupId, string $usernameColumn, string $idColumn): array
    {
        return $this->repo->findMemberUsernames($groupId, $usernameColumn, $idColumn);
    }

    /**
     * @param array<int, int> $groupIds
     * @return list<array<string, mixed>>
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
    public function create(string $name, bool $isDefault): int
    {
        if ($this->repo->nameExists($name)) {
            throw new \InvalidArgumentException('This name is already used by another group.');
        }

        if (str_replace(' ', '', $name) === '') {
            throw new \InvalidArgumentException('Name field must not be empty');
        }

        $id = $this->repo->insert($name, $isDefault);
        $this->activityLogger->record('group', $id, 'add');

        return $id;
    }

    /**
     * Creates a copy of an existing group (same is_default, same members),
     * under a new name. Throws InvalidArgumentException matching
     * ws_groups_duplicate()'s two validation failures -- note: unlike
     * create(), there is no separate empty-name check here, matching the
     * original.
     */
    public function duplicate(int $groupId, string $copyName): int
    {
        if ($this->repo->nameExists($copyName)) {
            throw new \InvalidArgumentException('This name is already used by another group.');
        }

        if (! $this->repo->exists($groupId)) {
            throw new \InvalidArgumentException('This group does not exist.');
        }

        $newId = $this->repo->insert($copyName, $this->repo->isDefault($groupId));
        $this->activityLogger->record('group', $newId, 'add');

        $memberIds = $this->repo->findMemberUserIds($groupId);
        $this->repo->addMembers($newId, $memberIds);
        UserCacheInvalidator::invalidate();

        // Matches the original ws_groups_duplicate()'s own (likely
        // accidental, but faithfully preserved) choice of 'associated' id:
        // the *source* group being copied from, not the newly created copy.
        foreach ($memberIds as $userId) {
            $this->activityLogger->record('user', $userId, 'edit', [
                'associated' => $groupId,
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
    public function update(int $groupId, array $updates): void
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
        $this->activityLogger->record('group', $groupId, 'edit');
    }

    /**
     * Adds users to a group. Returns false when the group doesn't exist.
     *
     * @param array<int, int> $userIds
     */
    public function addMembers(int $groupId, array $userIds): bool
    {
        if (! $this->repo->exists($groupId)) {
            return false;
        }

        $this->repo->addMembers($groupId, $userIds);
        UserCacheInvalidator::invalidate();

        $this->activityLogger->record('group', $groupId, 'edit');
        $this->activityLogger->record('user', $userIds, 'edit');

        return true;
    }

    /**
     * Removes users from a group. Returns false when the group doesn't exist.
     *
     * @param array<int, int> $userIds
     */
    public function removeMembers(int $groupId, array $userIds): bool
    {
        if (! $this->repo->exists($groupId)) {
            return false;
        }

        $this->repo->removeMembers($groupId, $userIds);
        UserCacheInvalidator::invalidate();

        $this->activityLogger->record('group', $groupId, 'edit');
        $this->activityLogger->record('user', $userIds, 'edit');

        return true;
    }

    /**
     * Merges $mergeGroupIds into $destinationGroupId: every member of a
     * merged group who isn't already in the destination gets added to it,
     * then the merged groups are deleted. Returns false when any of the
     * involved groups (destination + merge sources) don't exist.
     *
     * @param array<int, int> $mergeGroupIds
     */
    public function merge(int $destinationGroupId, array $mergeGroupIds): bool
    {
        $allGroupIds = array_unique([...$mergeGroupIds, $destinationGroupId]);
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
        UserCacheInvalidator::invalidate();
        $this->activityLogger->record('group', $destinationGroupId, 'edit');

        foreach ($membersToAdd as $userId) {
            $this->activityLogger->record('user', $userId, 'edit', [
                'associated' => $destinationGroupId,
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
     * Piwigo\Cache\UserCacheInvalidator::invalidate() themselves
     * afterward, same as before.
     *
     * @param array<int, int> $groupIds
     * @return false|array<int, string>
     */
    public function delete(array $groupIds): false|array
    {
        /** @var array<string, mixed> $user */
        global $user;

        if (count($groupIds) === 0) {
            trigger_error('There is no group to delete', E_USER_WARNING);
            return false;
        }

        $emailAdminOnNewUser = conf_get_param('email_admin_on_new_user', 'undefined');
        $emailAdminOnNewUser = is_scalar($emailAdminOnNewUser) ? (string) $emailAdminOnNewUser : 'undefined';
        if ((bool) preg_match('/^group:(\d+)$/', $emailAdminOnNewUser, $matches)) {
            foreach ($groupIds as $groupId) {
                if ($groupId === (int) $matches[1]) {
                    conf_update_param('email_admin_on_new_user', 'all', true);
                }
            }
        }

        $deleted = $this->repo->delete($groupIds);
        if ($deleted === []) {
            return [];
        }

        $ids = array_keys($deleted);
        trigger_notify('delete_group', $ids);
        $this->activityLogger->record('group', $ids, 'delete');

        // [SEC-57] one row per group actually deleted
        $actorId = $user['id'] ?? null;
        $actorId = is_numeric($actorId) ? (int) $actorId : null;
        $audit = new AuditService(new AuditRepository(DbConnection::build()));
        foreach ($deleted as $deletedId => $deletedName) {
            $audit->record($actorId, 'delete', 'group', $deletedId, [
                'name' => $deletedName,
            ], null);
        }

        return $deleted;
    }

    public function getName(int $groupId): ?string
    {
        return $this->repo->findName($groupId);
    }

    /**
     * @return list<int>
     */
    public function getAuthorizedCategoryIds(int $groupId): array
    {
        return $this->repo->getAuthorizedCategoryIds($groupId);
    }

    /**
     * Forbids access to the given categories for a group.
     *
     * @param array<int, int> $catIds
     */
    public function removeAccess(int $groupId, array $catIds): void
    {
        $this->repo->removeAccess($groupId, $catIds);
    }

    /**
     * Authorizes access to the given categories for a group, skipping ones
     * already authorized.
     *
     * @param array<int, int> $catIds
     */
    public function addAccess(int $groupId, array $catIds): void
    {
        $alreadyAuthorized = $this->repo->getAuthorizedCategoryIds($groupId);
        $toAuthorize = array_values(array_diff($catIds, $alreadyAuthorized));
        if ($toAuthorize === []) {
            return;
        }

        $this->repo->addAccess($groupId, $toAuthorize);
        UserCacheInvalidator::invalidate();
    }
}
