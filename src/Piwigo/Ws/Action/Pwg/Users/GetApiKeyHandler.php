<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Core\Lang;
use Piwigo\Csrf\CsrfService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

/** `pwg.users.getApiKey` — list all API keys for the session user. */
final readonly class GetApiKeyHandler implements WsAction
{
    public function __construct(
        private AuthService $authService,
        private CsrfService $csrfService,
        private PermissionService $permissionService,
        private UserService $userService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if ($this->permissionService->isAGuest() || !$this->authService->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }
        try {
            $input = GetApiKeyParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $apiKeys = $this->userService->getApiKey((string) CurrentUser::get()->id);
        return ($apiKeys !== false && count($apiKeys) > 0) ? $apiKeys : new PwgError(404, Lang::t('No API key found'));
    }
}
