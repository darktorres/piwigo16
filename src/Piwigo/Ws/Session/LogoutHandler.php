<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Session;

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthService;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.session.logout` -- ends the current session.
 */
final readonly class LogoutHandler implements WsAction
{
    public function __construct(
        private AuthService $authService,
        private AccessControl $accessControl,
        private ApiKeyRequestFlag $apiKeyRequestFlag,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|true
    {
        if ($this->apiKeyRequestFlag->isActive()) {
            return new WsErrorResponse(401, 'Cannot use this method with an api key');
        }

        if (! $this->accessControl->isAGuest()) {
            $this->authService->logoutUser();
        }
        return true;
    }
}
