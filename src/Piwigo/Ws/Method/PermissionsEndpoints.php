<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsError;

final readonly class PermissionsEndpoints
{
    public function __construct(
        private Connection $conn,
        private CategoryAdminService $categoryAdminService,
        private CategoryService $categoryService,
        private PermissionRepository $permissionRepository,
        private CsrfService $csrfService,
    ) {
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function getList(array $params, PwgServer &$service): PwgError|array
    {
        $myParams = array_intersect(array_keys($params), ['cat_id', 'group_id', 'user_id']);
        if (count($myParams) > 1) {
            return new PwgError(WsError::InvalidParam->value, 'Too many parameters, provide cat_id OR user_id OR group_id');
        }
        $permRepo    = $this->permissionRepository;
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
                $groupIdArrStr = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $groupIdArr);
                $catGroupsStr  = array_map(fn (mixed $v): string => (string) $v, $cat['groups'] ?? []);
                if (empty($cat['groups']) || count(array_intersect($catGroupsStr, $groupIdArrStr)) === 0) {
                    unset($perms[$catId]);
                    continue;
                }
            }
            if (isset($params['user_id'])) {
                $userIdArr             = is_array($params['user_id']) ? $params['user_id'] : [];
                $userIdArrStr          = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $userIdArr);
                $catUsersIndirectStr   = array_map(fn (mixed $v): string => (string) $v, $cat['users_indirect'] ?? []);
                $catUsersStr           = array_map(fn (mixed $v): string => (string) $v, $cat['users'] ?? []);
                if ((empty($cat['users_indirect']) || count(array_intersect($catUsersIndirectStr, $userIdArrStr)) === 0) && (empty($cat['users']) || count(array_intersect($catUsersStr, $userIdArrStr)) === 0)) {
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

    /** @param array<mixed> $params */
    public function add(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!empty($params['group_id'])) {
            $catIdParamInt = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['cat_id']) ? $params['cat_id'] : []);
            $catIds        = $this->categoryAdminService->getUppercatIds($catIdParamInt);
            if ($params['recursive']) {
                $catIds = array_merge($catIds, $this->categoryService->getSubcatIds($catIdParamInt));
            }
            $catIdsStr = array_map(fn (mixed $v): string => (string) $v, $catIds);
            $query     = 'SELECT id FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $catIdsStr) . ") AND status = 'private';";
            $privateCats = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id');
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
            $this->categoryAdminService->addPermissionOnCategory($catIdParam2Int, $userIdParamInt);
        }
        return $service->invoke('pwg.permissions.getList', ['cat_id' => $params['cat_id']]);
    }

    /** @param array<mixed> $params */
    public function remove(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $catIdParam3Int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['cat_id']) ? $params['cat_id'] : []);
        $catIds         = $this->categoryService->getSubcatIds($catIdParam3Int);
        $catIdsStr      = array_map(fn (mixed $v): string => (string) $v, $catIds);
        $permRepo2      = $this->permissionRepository;
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
