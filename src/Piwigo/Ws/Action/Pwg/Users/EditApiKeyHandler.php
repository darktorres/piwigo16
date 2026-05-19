<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Csrf\CsrfService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.users.editApiKey` — rename a personal API key. */
final readonly class EditApiKeyHandler implements WsAction
{
    public function __construct(
        private AuthService $authService,
        private CsrfService $csrfService,
        private PermissionService $permissionService,
        private UserService $userService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $logger = LoggerRegistry::current();
        $userId = CurrentUser::get()->id;
        if ($this->permissionService->isAGuest() || !$this->authService->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }
        $editPkid = is_string($params['pkid'] ?? null) ? $params['pkid'] : '';
        if (!preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $editPkid)) {
            return new PwgError(403, Lang::t('Invalid pkid format'));
        }
        $keyName   = is_string($params['key_name'] ?? null) ? $params['key_name'] : '';
        $editedKey = $this->userService->editApiKey($userId, $editPkid, $keyName);
        if (true !== $editedKey) {
            return new PwgError(403, $editedKey);
        }
        $logger->info('[api_key][user_id=' . $userId . '][action=edit][pkid=' . $editPkid . '][new_name=' . $keyName . ']');
        return Lang::t('API Key has been successfully edited.');
    }
}
