<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Csrf\CsrfService;
use Piwigo\Users\AuthService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.users.getAuthKey` — issue a one-shot auth key for a non-admin user. */
final readonly class GetAuthKeyHandler implements WsAction
{
    public function __construct(
        private AuthService $authService,
        private CsrfService $csrfService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $authkey = $this->authService->createUserAuthKey(is_numeric($params['user_id']) ? (int) $params['user_id'] : 0);
        if ($authkey === false) {
            return new PwgError(WsError::InvalidParam->value, 'invalid user_id');
        }
        return $authkey;
    }
}
