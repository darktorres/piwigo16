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
use Piwigo\Ws\WsParamException;

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
        try {
            $input = EditApiKeyParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }
        if (!preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $input->pkid)) {
            return new PwgError(403, Lang::t('Invalid pkid format'));
        }
        $editedKey = $this->userService->editApiKey($userId, $input->pkid, $input->keyName);
        if (true !== $editedKey) {
            return new PwgError(403, $editedKey);
        }
        $logger->info('[api_key][user_id=' . $userId . '][action=edit][pkid=' . $input->pkid . '][new_name=' . $input->keyName . ']');
        return Lang::t('API Key has been successfully edited.');
    }
}
