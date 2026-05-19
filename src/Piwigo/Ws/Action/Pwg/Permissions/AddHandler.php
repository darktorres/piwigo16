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
use Piwigo\Ws\WsParamException;

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
        try {
            $input = AddParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($input->groupIds !== []) {
            $catIds = $this->categoryAdminService->getUppercatIds($input->categoryIds);
            if ($input->recursive) {
                $catIds = array_merge($catIds, $this->categoryService->getSubcatIds($input->categoryIds));
            }
            $catIdsInt   = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catIds);
            $privateCats = $this->categoryRepository->findPrivateByIds($catIdsInt);
            $inserts     = [];
            foreach ($privateCats as $catId) {
                foreach ($input->groupIds as $groupId) {
                    $inserts[] = ['group_id' => $groupId, 'cat_id' => $catId];
                }
            }
            $this->permissionRepository->insertGroupAccessIgnoreDuplicates($inserts);
        }
        if ($input->userIds !== []) {
            if ($input->recursive) {
                $_POST['apply_on_sub'] = true;
            }
            $this->categoryAdminService->addPermissionOnCategory($input->categoryIds, $input->userIds);
        }
        return $server->invoke('pwg.permissions.getList', ['cat_id' => $input->categoryIds]);
    }
}
