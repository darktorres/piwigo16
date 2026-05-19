<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Session;

use Piwigo\Http\ApiKeyAuthRegistry;
use Piwigo\Users\AuthService;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/**
 * `pwg.session.logout` — end the current session (for guest already
 * logged out, no-op). Rejects the call when authenticated via an API
 * key — those credentials have no session to end.
 */
final readonly class LogoutHandler implements WsAction
{
    public function __construct(
        private AuthService $authService,
        private PermissionService $permissionService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|true
    {
        if (ApiKeyAuthRegistry::isApiKeyAuth()) {
            return new PwgError(401, 'Cannot use this method with an api key');
        }
        if (!$this->permissionService->isAGuest()) {
            $this->authService->logoutUser();
        }
        return true;
    }
}
