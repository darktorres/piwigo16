<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;

final class PermissionsEndpoints
{
    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function getList(array $params, PwgServer &$service): PwgError|array
    {
        $myParams = array_intersect(array_keys($params), ['cat_id', 'group_id', 'user_id']);
        if (count($myParams) > 1) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Too many parameters, provide cat_id OR user_id OR group_id');
        }
        $permRepo    = ServiceLocator::get(PermissionRepository::class);
        $catIdsFilter = !empty($params['cat_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['cat_id']) ? $params['cat_id'] : []) : null;
        $perms       = [];
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
            if (isset($params['group_id'])) {
                $groupIdArr    = is_array($params['group_id']) ? $params['group_id'] : [];
                $groupIdArrStr = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $groupIdArr);
                $catGroupsStr  = array_map(fn (mixed $v): string => (string) $v, $cat['groups'] ?? []);
                if (empty($cat['groups']) || count(array_intersect($catGroupsStr, $groupIdArrStr)) === 0) {
                    unset($perms[$catId]);
                    continue;
                }
            }
            if (isset($params['user_id'])) {
                $userIdArr             = is_array($params['user_id']) ? $params['user_id'] : [];
                $userIdArrStr          = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $userIdArr);
                $catUsersIndirectStr   = array_map(fn (mixed $v): string => (string) $v, $cat['users_indirect'] ?? []);
                $catUsersStr           = array_map(fn (mixed $v): string => (string) $v, $cat['users'] ?? []);
                if ((empty($cat['users_indirect']) || count(array_intersect($catUsersIndirectStr, $userIdArrStr)) === 0) && (empty($cat['users']) || count(array_intersect($catUsersStr, $userIdArrStr)) === 0)) {
                    unset($perms[$catId]);
                    continue;
                }
            }
            $cat['groups']         = !empty($cat['groups']) ? array_values(array_unique($cat['groups'])) : [];
            $cat['users']          = !empty($cat['users']) ? array_values(array_unique($cat['users'])) : [];
            $cat['users_indirect'] = !empty($cat['users_indirect']) ? array_values(array_unique($cat['users_indirect'])) : [];
        }
        unset($cat);
        return ['categories' => new PwgNamedArray(array_values($perms), 'category', ['id'])];
    }

    /** @param array<mixed> $params */
    public function add(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!empty($params['group_id'])) {
            $catIdParamInt = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['cat_id']) ? $params['cat_id'] : []);
            $catIds        = ServiceLocator::get(CategoryAdminService::class)->getUppercatIds($catIdParamInt);
            if ($params['recursive']) {
                $catIds = array_merge($catIds, get_subcat_ids($catIdParamInt));
            }
            $catIdsStr = array_map(fn (mixed $v): string => (string) $v, $catIds);
            $query     = 'SELECT id FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $catIdsStr) . ") AND status = 'private';";
            $privateCats = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'id');
            $inserts     = [];
            $groupIdParam = is_array($params['group_id']) ? $params['group_id'] : [];
            foreach ($privateCats as $catId) {
                foreach ($groupIdParam as $groupId) {
                    $inserts[] = ['group_id' => $groupId, 'cat_id' => $catId];
                }
            }
            Dml::massInserts(Tables::groupAccess(), ['group_id', 'cat_id'], $inserts, ['ignore' => true]);
        }
        if (!empty($params['user_id'])) {
            if ($params['recursive']) {
                $_POST['apply_on_sub'] = true;
            }
            $catIdParam2Int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['cat_id']) ? $params['cat_id'] : []);
            $userIdParamInt = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['user_id']) ? $params['user_id'] : []);
            ServiceLocator::get(CategoryAdminService::class)->addPermissionOnCategory($catIdParam2Int, $userIdParamInt);
        }
        return $service->invoke('pwg.permissions.getList', ['cat_id' => $params['cat_id']]);
    }

    /** @param array<mixed> $params */
    public function remove(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $catIdParam3Int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['cat_id']) ? $params['cat_id'] : []);
        $catIds         = get_subcat_ids($catIdParam3Int);
        $catIdsStr      = array_map(fn (mixed $v): string => (string) $v, $catIds);
        $permRepo2      = ServiceLocator::get(PermissionRepository::class);
        $catIdsInt      = array_map(fn (string $v): int => (int) $v, $catIdsStr);
        if (!empty($params['group_id'])) {
            $groupIdRem = is_array($params['group_id']) ? $params['group_id'] : [];
            $permRepo2->deleteGroupAccess(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $groupIdRem), $catIdsInt);
        }
        if (!empty($params['user_id'])) {
            $userIdRem = is_array($params['user_id']) ? $params['user_id'] : [];
            $permRepo2->deleteUserAccess(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $userIdRem), $catIdsInt);
        }
        return $service->invoke('pwg.permissions.getList', ['cat_id' => $params['cat_id']]);
    }
}
