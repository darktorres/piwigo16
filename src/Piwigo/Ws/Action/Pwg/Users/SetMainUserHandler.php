<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\ConfigService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.users.setMainUser` — promote a webmaster to gallery main user. */
final readonly class SetMainUserHandler implements WsAction
{
    public function __construct(
        private ConfigService $configService,
        private CsrfService $csrfService,
        private PermissionService $permissionService,
        private UserAdminService $userAdminService,
        private UserService $userService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|string
    {
        if (!$this->permissionService->isWebmaster()) {
            return new PwgError(403, 'You cannot perform this action');
        }
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $mainUserId = is_numeric($params['user_id']) ? (int) $params['user_id'] : 0;
        if ($this->userAdminService->getUsername($mainUserId) === false) {
            return new PwgError(WsError::InvalidParam->value, 'This user does not exist.');
        }
        $newMainUser = $this->userService->getuserdata($mainUserId);
        if ($newMainUser === false) {
            return new PwgError(404, 'User not found');
        }
        if ($newMainUser['status'] !== 'webmaster') {
            return new PwgError(403, 'This user cannot become a main user because he is not a webmaster.');
        }
        $this->configService->confUpdateParam('webmaster_id', $mainUserId);
        return 'The main user has been changed.';
    }
}
