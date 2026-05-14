<?php

declare(strict_types=1);

namespace Piwigo\Admin\Users;

use Doctrine\DBAL\Connection;
use Piwigo\Cache\PersistentCacheRegistry;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\Util;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

final readonly class UserAdminService
{
    public function __construct(
        private Connection $conn,
        private ConfigService $configService,
        private GroupRepository $groupRepository,
        private PermissionRepository $permissionRepository,
        private SessionService $sessionService,
        private UserRepository $userRepository,
        private UserService $userService,
        private Util $util,
    ) {
    }

    public function deleteUser(int $userId): void
    {
        $uRepo = $this->userRepository;
        $uRepo->deleteAllRelatedData($userId);
        $this->sessionService->deleteUserSessions($userId);
        $uRepo->deleteByUserId($userId, Tables::users(), Config::userFields()['id']);
        EventDispatcher::notify('delete_user', $userId);
        $this->util->pwgActivity('user', $userId, 'delete');
    }

    public function syncUsers(): void
    {
        $baseUsers = array_map(
            fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->conn->executeQuery(
                'SELECT ' . Config::userFields()['id'] . ' AS id FROM ' . Tables::users()
            )->fetchAllAssociative(), 'id')
        );

        $infosUsers = array_map(
            fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->conn->executeQuery(
                'SELECT user_id FROM ' . Tables::userInfos()
            )->fetchAllAssociative(), 'user_id')
        );

        $toCreate = array_diff($baseUsers, $infosUsers);
        if (count($toCreate) > 0) {
            $this->userService->createUserInfos($toCreate);
        }

        $tables = [
            Tables::userMailNotification(), Tables::userFeed(), Tables::userInfos(),
            Tables::userAccess(), Tables::userCache(), Tables::userCacheCategories(), Tables::userGroup(),
        ];

        foreach ($tables as $table) {
            $toDelete = array_diff(
                array_map(
                    fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                    array_column($this->conn->executeQuery('SELECT DISTINCT user_id FROM ' . $table)->fetchAllAssociative(), 'user_id')
                ),
                $baseUsers
            );
            if (count($toDelete) > 0) {
                $this->userRepository->deleteOrphanedFromTable($table, array_values($toDelete));
            }
        }
    }

    /** @return array<int,string> */
    public function getUserAccessLevelHtmlOptions(int $minLevelAccess = AccessLevel::Free, int $maxLevelAccess = AccessLevel::Closed): array
    {
        $options = [];
        for ($level = $minLevelAccess; $level <= $maxLevelAccess; $level++) {
            $options[$level] = Lang::t(sprintf('ACCESS_%d', $level));
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
        $raw = array_column($this->conn->executeQuery(
            'SELECT user_id FROM ' . Tables::userInfos() . " WHERE status IN ('" . implode("','", $statusList) . "')"
        )->fetchAllAssociative(), 'user_id');
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $raw);
    }

    public function getGroupname(int $groupId): string|false
    {
        $name = $this->groupRepository->findNameById($groupId);
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
                    $this->configService->confUpdateParam('email_admin_on_new_user', 'all', true);
                }
            }
        }
        $groupIdString = implode(',', $groupIds);
        $permRepo      = $this->permissionRepository;
        $groupRepo     = $this->groupRepository;
        $permRepo->deleteGroupAccessByGroups($groupIds);
        $groupRepo->deleteUserGroupByGroupIds($groupIds);
        $groupList = array_column($this->conn->executeQuery(
            'SELECT id, name FROM `' . Tables::groups() . '` WHERE id IN (' . $groupIdString . ')'
        )->fetchAllAssociative(), 'name', 'id');
        $groupids = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_keys($groupList));
        $groupRepo->deleteByIds($groupIds);
        EventDispatcher::notify('delete_group', $groupids);
        $this->util->pwgActivity('group', $groupids, 'delete');
        return $groupList;
    }

    public function getUsername(int $userId): false|string
    {
        $userFields = Config::userFields();
        $username   = $this->userRepository->findUsernameById(
            $userFields['id'],
            $userFields['username'],
            Tables::users(),
            $userId
        );
        return $username !== null ? stripslashes($username) : false;
    }

    public function invalidateUserCache(bool $full = true): void
    {
        $persistentCache = PersistentCacheRegistry::current();
        if (LoggerRegistry::isInitialized()) {
            LoggerRegistry::current()->info('invalidate_user_cache called');
        }
        $userRepo = $this->userRepository;
        if ($full) {
            $userRepo->truncateCategoryCache();
            $userRepo->truncateUserCache();
        } else {
            $userRepo->markAllCachesForUpdate();
        }
        $persistentCache->purge(true);
        $this->configService->confDeleteParam('count_orphans');
        EventDispatcher::notify('invalidate_user_cache', $full);
    }

    public function invalidateUserCacheNbTags(): void
    {
        if (isset($GLOBALS['user']) && is_array($GLOBALS['user'])) {
            unset($GLOBALS['user']['nb_available_tags']);
        }
        $this->userRepository->clearNbAvailableTags();
    }

    public function catAdminAccess(int $categoryId): bool
    {
        $user      = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $forbidden = is_scalar($user['forbidden_categories'] ?? null) ? (string) $user['forbidden_categories'] : '';
        return !in_array($categoryId, explode(',', $forbidden));
    }
}
