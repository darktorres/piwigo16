<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Session;

use Piwigo\Http\ApiKeyAuthRegistry;
use Piwigo\Session\Session;
use Piwigo\Users\AuthService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/**
 * `pwg.session.login` — log the current request's user in by
 * username/password, or via a personal API key (`pkid-…`) as
 * username + secret as password. Stores the connection-origin tag in
 * the request-scoped Session VO so other endpoints can read it back.
 */
final readonly class LoginHandler implements WsAction
{
    public function __construct(
        private AuthService $authService,
        private Session $session,
    ) {
    }

    /** @param array<mixed> $params */
    public function __invoke(array $params, PwgServer $server): PwgError|true
    {
        if (ApiKeyAuthRegistry::isApiKeyAuth()) {
            return new PwgError(401, 'Cannot use this method with an api key');
        }
        $username = is_string($params['username'] ?? null) ? $params['username'] : '';
        $password = is_string($params['password'] ?? null) ? $params['password'] : '';
        if (preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $username)) {
            if ($this->authService->authKeyLogin($username . ':' . $password)) {
                $this->session->connectedWith = 'ws_session_login_api_key';
                return true;
            }
        } elseif ($this->authService->tryLogUser($username, $password, false)) {
            $this->session->connectedWith = 'ws_session_login';
            return true;
        }
        return new PwgError(999, 'Invalid username/password');
    }
}
