<?php

declare(strict_types=1);

namespace Piwigo\Admin\Users;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Db\Tables;
use Piwigo\Event\User\DeleteGroup;
use Piwigo\Event\User\DeleteUser;
use Piwigo\Event\User\InvalidateUserCache;
use Piwigo\Group\GroupRepository;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class UserAdminService
{
    public function __construct(
        private ConfigService $configService,
        private GroupRepository $groupRepository,
        private SessionService $sessionService,
        private UserRepository $userRepository,
        private UserService $userService,
        private ActivityLogger $activityLogger,
        private CacheItemPoolInterface $pool,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function deleteUser(int $userId): void
    {
        // piwigo_sessions has no FK on user_id (session-id keyed); manual
        // cleanup stays before the user row delete. FK CASCADE handles
        // user_access, user_mail_notification, user_feed, user_cache,
        // user_cache_categories, user_group, favorites, caddie, user_infos,
        // user_auth_keys; FK SET NULL: comments.author_id, images.added_by.
        $this->sessionService->deleteUserSessions($userId);
        $this->userRepository->deleteByUserId($userId, Tables::users(), Config::userFields()['id']);
        $this->dispatcher->dispatch(new DeleteUser($userId));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $userId, 'delete'));
    }

    public function syncUsers(): void
    {
        $baseUsers  = $this->userRepository->findAllUserIdsFromUsers(Config::userFields()['id'], Tables::users());
        $infosUsers = $this->userRepository->findUserIdsFromUserInfos();

        $toCreate = array_values(array_diff($baseUsers, $infosUsers));
        if (count($toCreate) > 0) {
            $this->userService->createUserInfos($toCreate);
        }

        $tables = [
            Tables::userMailNotification(), Tables::userFeed(), Tables::userInfos(),
            Tables::userAccess(), Tables::userCache(), Tables::userCacheCategories(), Tables::userGroup(),
        ];

        foreach ($tables as $table) {
            $toDelete = array_values(array_diff($this->userRepository->findDistinctUserIdsFromTable($table), $baseUsers));
            if (count($toDelete) > 0) {
                $this->userRepository->deleteOrphanedFromTable($table, $toDelete);
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
        return $this->userRepository->findUserIdsByStatuses($statusList);
    }

    public function getGroupname(int $groupId): string|false
    {
        $name = $this->groupRepository->findNameById($groupId);
        return $name ?? false;
    }

    /**
     * @param int[]|int $groupIds
     * @return array<int|string, mixed>
     */
    public function deleteGroups(array|int $groupIds): array
    {
        if (!is_array($groupIds)) {
            $groupIds = [$groupIds];
        }
        if (count($groupIds) === 0) {
            throw new \InvalidArgumentException('There is no group to delete');
        }
        $groupIdsInt = array_values($groupIds);
        $groupList   = $this->groupRepository->findIdNameMapByIds($groupIdsInt);
        $groupids    = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_keys($groupList));
        if (preg_match('/^group:(\d+)$/', Config::emailAdminOnNewUser(), $matches)) {
            foreach ($groupIds as $groupId) {
                if ($groupId == $matches[1]) {
                    $this->configService->confUpdateParam('email_admin_on_new_user', 'all', true);
                }
            }
        }
        $this->groupRepository->deleteByIds($groupIdsInt);
        // FK CASCADE clears group_access.group_id + user_group.group_id.
        $this->dispatcher->dispatch(new DeleteGroup($groupids));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $groupids, 'delete'));
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
        $this->pool->clear();
        $this->configService->confDeleteParam('count_orphans');
        $this->dispatcher->dispatch(new InvalidateUserCache($full));
    }

    public function invalidateUserCacheNbTags(): void
    {
        if (CurrentUser::isInitialized()) {
            CurrentUser::update(static fn (User $u): User => $u->withoutRawAttribute('nb_available_tags'));
        }
        $this->userRepository->clearNbAvailableTags();
    }

    public function catAdminAccess(int $categoryId): bool
    {
        $user      = CurrentUser::isInitialized() ? CurrentUser::get()->rawAttributes : [];
        $forbidden = $user['forbidden_categories'] ?? [];
        return !is_array($forbidden) || !in_array($categoryId, $forbidden, true);
    }
}
