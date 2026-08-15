<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Override;
use Piwigo\Auth\AuthService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.getAuthKey` -- admin only. Get a new authentication key for a user.
 */
final readonly class GetAuthKeyHandler implements WsAction
{
    public function __construct(
        private AuthService $authService,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{auth_key: string, user_id: int, created_on: string, duration: int, expired_on: string, key_type: string, auth_key_id: string}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = GetAuthKeyParams::fromArray($params);

        if (new CsrfService($this->currentConfig)->getToken() !== $input->pwgToken) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $authkey = $this->authService->createUserAuthKey($input->userId);

        if ($authkey === false) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'invalid user_id');
        }

        return $authkey;
    }
}
