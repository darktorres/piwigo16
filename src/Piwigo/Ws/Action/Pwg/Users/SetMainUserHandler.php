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
use Piwigo\Ws\WsParamException;

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
        try {
            $input = SetMainUserParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $mainUserId = $input->userId;
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
