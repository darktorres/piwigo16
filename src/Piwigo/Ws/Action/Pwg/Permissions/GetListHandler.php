<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Permissions;

use Piwigo\Permission\PermissionRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.permissions.getList` — per-album direct/indirect access matrix. */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private PermissionRepository $permissionRepository,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $myParams = array_intersect(array_keys($params), ['cat_id', 'group_id', 'user_id']);
        if (count($myParams) > 1) {
            return new PwgError(WsError::InvalidParam->value, 'Too many parameters, provide cat_id OR user_id OR group_id');
        }
        $input        = GetListParams::fromArray($params);
        $permRepo     = $this->permissionRepository;
        $catIdsFilter = $input->catIds;
        $perms        = [];
        foreach ($permRepo->findUserCategoryAccess($catIdsFilter) as $row) {
            $catId = is_numeric($row['cat_id']) ? (int) $row['cat_id'] : 0;
            if (!isset($perms[$catId])) {
                $perms[$catId]['id'] = $catId;
            }
            $perms[$catId]['users'][] = is_numeric($row['user_id']) ? (int) $row['user_id'] : 0;
        }
        foreach ($permRepo->findGroupUserCategoryAccess($catIdsFilter) as $row) {
            $catId = is_numeric($row['cat_id']) ? (int) $row['cat_id'] : 0;
            if (!isset($perms[$catId])) {
                $perms[$catId]['id'] = $catId;
            }
            $perms[$catId]['users_indirect'][] = is_numeric($row['user_id']) ? (int) $row['user_id'] : 0;
        }
        foreach ($permRepo->findGroupCategoryAccess($catIdsFilter) as $row) {
            $catId = is_numeric($row['cat_id']) ? (int) $row['cat_id'] : 0;
            if (!isset($perms[$catId])) {
                $perms[$catId]['id'] = $catId;
            }
            $perms[$catId]['groups'][] = is_numeric($row['group_id']) ? (int) $row['group_id'] : 0;
        }
        foreach ($perms as $catId => &$cat) {
            if ($input->groupIdsSet) {
                $catGroupsStr = array_map(fn (mixed $v): string => (string) $v, $cat['groups'] ?? []);
                if (empty($cat['groups']) || count(array_intersect($catGroupsStr, $input->groupIds)) === 0) {
                    unset($perms[$catId]);
                    continue;
                }
            }
            if ($input->userIdsSet) {
                $catUsersIndirectStr = array_map(fn (mixed $v): string => (string) $v, $cat['users_indirect'] ?? []);
                $catUsersStr         = array_map(fn (mixed $v): string => (string) $v, $cat['users'] ?? []);
                if ((empty($cat['users_indirect']) || count(array_intersect($catUsersIndirectStr, $input->userIds)) === 0) && (empty($cat['users']) || count(array_intersect($catUsersStr, $input->userIds)) === 0)) {
                    unset($perms[$catId]);
                    continue;
                }
            }
            $cat['groups']         = !empty($cat['groups']) ? array_values(array_unique($cat['groups'])) : [];
            $cat['users']          = array_values(array_unique($cat['users'] ?? []));
            $cat['users_indirect'] = !empty($cat['users_indirect']) ? array_values(array_unique($cat['users_indirect'])) : [];
        }
        unset($cat);
        return ['categories' => new PwgNamedArray(array_values($perms), 'category', ['id'])];
    }
}
