<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Permissions;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.permissions.add` — grant per-album access to users/groups. */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private CsrfService $csrfService,
        private PermissionRepository $permissionRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
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
            $catIdsInt   = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catIds);
            $privateCats = $this->categoryRepository->findPrivateByIds($catIdsInt);
            $inserts     = [];
            $groupIdParam = is_array($params['group_id']) ? $params['group_id'] : [];
            foreach ($privateCats as $catId) {
                foreach ($groupIdParam as $groupId) {
                    $inserts[] = ['group_id' => is_numeric($groupId) ? (int) $groupId : 0, 'cat_id' => $catId];
                }
            }
            $this->permissionRepository->insertGroupAccessIgnoreDuplicates($inserts);
        }
        if (!empty($params['user_id'])) {
            if ($params['recursive']) {
                $_POST['apply_on_sub'] = true;
            }
            $catIdParam2Int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['cat_id']) ? $params['cat_id'] : []);
            $userIdParamInt = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['user_id']) ? $params['user_id'] : []);
            $this->categoryAdminService->addPermissionOnCategory($catIdParam2Int, $userIdParamInt);
        }
        return $server->invoke('pwg.permissions.getList', ['cat_id' => $params['cat_id']]);
    }
}
