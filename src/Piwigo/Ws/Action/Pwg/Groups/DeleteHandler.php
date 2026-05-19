<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.groups.delete` — remove groups (users/photos untouched). */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private UserAdminService $userAdminService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|PwgNamedArray
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $groupIdInt   = is_numeric($params['group_id']) ? (int) $params['group_id'] : (is_array($params['group_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['group_id']) : 0);
        $deleteResult = $this->userAdminService->deleteGroups($groupIdInt);
        $groupnames   = array_values($deleteResult);
        $this->userAdminService->invalidateUserCache();
        return new PwgNamedArray($groupnames, 'group_deleted');
    }
}
