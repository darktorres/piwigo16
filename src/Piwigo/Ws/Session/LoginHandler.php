<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Session;

use Piwigo\Auth\AuthService;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.session.login` -- tries to login the user.
 */
final readonly class LoginHandler implements WsAction
{
    public function __construct(
        private AuthService $authService,
        private ApiKeyRequestFlag $apiKeyRequestFlag,
    ) {}

    /**
     * @param array<mixed> $params
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|true
    {
        if ($this->apiKeyRequestFlag->isActive()) {
            return new WsErrorResponse(401, 'Cannot use this method with an api key');
        }

        $input = LoginParams::fromArray($params);

        if ((bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $input->username)) {
            // The combined "username:password" string must match
            // authKeyLogin()'s own strict [a-z0-9]-only regex to be
            // considered valid at all, so it never needs SQL escaping.
            $authenticate = $this->authService->authKeyLogin($input->username . ':' . $input->password);
            if ($authenticate) {
                $_SESSION['connected_with'] = 'ws_session_login_api_key';
                return true;
            }
        } elseif ($this->authService->tryLogUser($input->username, $input->password, false)) {
            $_SESSION['connected_with'] = 'ws_session_login';
            return true;
        }
        return new WsErrorResponse(999, 'Invalid username/password');
    }
}
