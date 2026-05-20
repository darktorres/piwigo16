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

/** `pwg.users.revokeApiKey` — invalidate a personal API key. */
final readonly class RevokeApiKeyHandler implements WsAction
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
            $input = RevokeApiKeyParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }
        $revokedKey = $this->userService->revokeApiKey($userId, $input->pkid);
        if (true !== $revokedKey) {
            return new PwgError(403, is_string($revokedKey) ? $revokedKey : '');
        }
        $logger->info('[api_key][user_id=' . $userId . '][action=revoke][pkid=' . $input->pkid . ']');
        return Lang::t('API Key has been successfully revoked.');
    }
}
