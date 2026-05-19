<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Csrf\CsrfService;
use Piwigo\Users\AuthService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsParamException;

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
        try {
            $input = GetAuthKeyParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $authkey = $this->authService->createUserAuthKey($input->userId);
        if ($authkey === false) {
            return new PwgError(WsError::InvalidParam->value, 'invalid user_id');
        }
        return $authkey;
    }
}
