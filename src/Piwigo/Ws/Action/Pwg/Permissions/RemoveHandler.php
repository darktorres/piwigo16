<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Permissions;

use Piwigo\Category\CategoryService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.permissions.remove` — revoke per-album access from users/groups. */
final readonly class RemoveHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private CsrfService $csrfService,
        private PermissionRepository $permissionRepository,
    ) {
    }

    /** @param array<mixed> $params */
    public function __invoke(array $params, PwgServer $server): mixed
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
        return $server->invoke('pwg.permissions.getList', ['cat_id' => $params['cat_id']]);
    }
}
