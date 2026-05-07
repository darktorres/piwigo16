<?php

declare(strict_types=1);

namespace Piwigo\Admin\Users;

use Piwigo\Cache\PersistentCacheRegistry;
use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Core\AccessLevel;
use Piwigo\Db\Tables;

final class UserAdminService
{
    public function deleteUser(mixed $userId): void
    {
        $uid   = is_numeric($userId) ? (int) $userId : 0;
        $uRepo = ServiceLocator::get(UserRepository::class);
        $uRepo->deleteAllRelatedData($uid);
        delete_user_sessions($uid);
        $uRepo->deleteByUserId($uid, Tables::users(), Config::userFields()['id']);
        EventDispatcher::notify('delete_user', $userId);
        pwg_activity('user', is_numeric($userId) ? (int) $userId : (is_scalar($userId) ? (string) $userId : 0), 'delete');
    }

    public function syncUsers(): void
    {
        $baseUsers = array_map(
            fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column(DbConnection::get()->executeQuery(
                'SELECT ' . Config::userFields()['id'] . ' AS id FROM ' . Tables::users()
            )->fetchAllAssociative(), 'id')
        );

        $infosUsers = array_map(
            fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column(DbConnection::get()->executeQuery(
                'SELECT user_id FROM ' . Tables::userInfos()
            )->fetchAllAssociative(), 'user_id')
        );

        $toCreate = array_diff($baseUsers, $infosUsers);
        if (count($toCreate) > 0) {
            UserService::get()->createUserInfos($toCreate);
        }

        $tables = [
            Tables::userMailNotification(), Tables::userFeed(), Tables::userInfos(),
            Tables::userAccess(), Tables::userCache(), Tables::userCacheCategories(), Tables::userGroup(),
        ];

        foreach ($tables as $table) {
            $toDelete = array_diff(
                array_map(
                    fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                    array_column(DbConnection::get()->executeQuery('SELECT DISTINCT user_id FROM ' . $table)->fetchAllAssociative(), 'user_id')
                ),
                $baseUsers
            );
            if (count($toDelete) > 0) {
                ServiceLocator::get(UserRepository::class)->deleteOrphanedFromTable($table, array_values($toDelete));
            }
        }
    }

    /** @return array<int,string> */
    public function getUserAccessLevelHtmlOptions(int $minLevelAccess = AccessLevel::Free, int $maxLevelAccess = AccessLevel::Closed): array
    {
        $options = [];
        for ($level = $minLevelAccess; $level <= $maxLevelAccess; $level++) {
            $options[$level] = l10n(sprintf('ACCESS_%d', $level));
        }
        return $options;
    }

    /** @return int[] */
    public function getAdmins(bool $includeWebmaster = true): array
    {
        $statusList = ['admin'];
        if ($includeWebmaster) {
            $statusList[] = 'webmaster';
        }
        $raw = array_column(DbConnection::get()->executeQuery(
            'SELECT user_id FROM ' . Tables::userInfos() . " WHERE status IN ('" . implode("','", $statusList) . "')"
        )->fetchAllAssociative(), 'user_id');
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $raw);
    }

    public function getGroupname(mixed $groupId): string|false
    {
        $name = ServiceLocator::get(GroupRepository::class)->findNameById(is_numeric($groupId) ? (int) $groupId : 0);
        return $name ?? false;
    }

    /**
     * @param int[]|int $groupIds
     * @return array<int|string, mixed>|false
     */
    public function deleteGroups(array|int $groupIds): false|array
    {
        if (!is_array($groupIds)) {
            $groupIds = [$groupIds];
        }
        if (count($groupIds) === 0) {
            trigger_error('There is no group to delete', E_USER_WARNING);
            return false;
        }
        if (preg_match('/^group:(\d+)$/', Config::emailAdminOnNewUser(), $matches)) {
            foreach ($groupIds as $groupId) {
                if ($groupId == $matches[1]) {
                    conf_update_param('email_admin_on_new_user', 'all', true);
                }
            }
        }
        $groupIdString = implode(',', $groupIds);
        $permRepo      = ServiceLocator::get(PermissionRepository::class);
        $groupRepo     = ServiceLocator::get(GroupRepository::class);
        $permRepo->deleteGroupAccessByGroups($groupIds);
        $groupRepo->deleteUserGroupByGroupIds($groupIds);
        $groupList = array_column(DbConnection::get()->executeQuery(
            'SELECT id, name FROM `' . Tables::groups() . '` WHERE id IN (' . $groupIdString . ')'
        )->fetchAllAssociative(), 'name', 'id');
        $groupids = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_keys($groupList));
        $groupRepo->deleteByIds($groupIds);
        EventDispatcher::notify('delete_group', $groupids);
        pwg_activity('group', $groupids, 'delete');
        return $groupList;
    }

    public function getUsername(mixed $userId): false|string
    {
        $userFields = Config::userFields();
        $username   = ServiceLocator::get(UserRepository::class)->findUsernameById(
            $userFields['id'],
            $userFields['username'],
            Tables::users(),
            is_numeric($userId) ? (int) $userId : 0
        );
        return $username !== null ? stripslashes((string) $username) : false;
    }

    public function invalidateUserCache(bool $full = true): void
    {
        $persistentCache = PersistentCacheRegistry::current();
        if (LoggerRegistry::isInitialized()) {
            LoggerRegistry::current()->info('invalidate_user_cache called');
        }
        $userRepo = ServiceLocator::get(UserRepository::class);
        if ($full) {
            $userRepo->truncateCategoryCache();
            $userRepo->truncateUserCache();
        } else {
            $userRepo->markAllCachesForUpdate();
        }
        $persistentCache->purge(true);
        conf_delete_param('count_orphans');
        EventDispatcher::notify('invalidate_user_cache', $full);
    }

    public function invalidateUserCacheNbTags(): void
    {
        if (isset($GLOBALS['user']) && is_array($GLOBALS['user'])) {
            unset($GLOBALS['user']['nb_available_tags']);
        }
        ServiceLocator::get(UserRepository::class)->clearNbAvailableTags();
    }

    public function catAdminAccess(mixed $categoryId): bool
    {
        $user      = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $forbidden = is_scalar($user['forbidden_categories'] ?? null) ? (string) $user['forbidden_categories'] : '';
        return !in_array($categoryId, explode(',', $forbidden));
    }
}
