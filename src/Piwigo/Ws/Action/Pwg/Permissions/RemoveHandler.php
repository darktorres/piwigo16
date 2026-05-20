<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Permissions;

use Piwigo\Category\CategoryService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

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
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        try {
            $input = RemoveParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $catIds = $this->categoryService->getSubcatIds($input->categoryIds);
        if ($input->groupIds !== []) {
            $this->permissionRepository->deleteGroupAccess($input->groupIds, $catIds);
        }
        if ($input->userIds !== []) {
            $this->permissionRepository->deleteUserAccess($input->userIds, $catIds);
        }
        return $server->invoke('pwg.permissions.getList', ['cat_id' => $input->categoryIds]);
    }
}
