<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Session;

use Piwigo\Auth\LoginRateLimiterFactory;
use Piwigo\Core\PageState;
use Piwigo\Http\ApiKeyAuthRegistry;
use Piwigo\Http\RequestScheme;
use Piwigo\Session\Session;
use Piwigo\Users\AuthService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

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
        private LoginRateLimiterFactory $loginRateLimiters,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|true
    {
        if (ApiKeyAuthRegistry::isApiKeyAuth()) {
            return new PwgError(401, 'Cannot use this method with an api key');
        }
        try {
            $input = LoginParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(999, $e->getMessage());
        }
        if (!$this->loginRateLimiters->forIp(RequestScheme::clientIp())->consume()->isAccepted()
         || !$this->loginRateLimiters->forAccount($input->username)->consume()->isAccepted()) {
            return new PwgError(429, 'Too many login attempts; please retry shortly');
        }
        if (preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $input->username)) {
            if ($this->authService->authKeyLogin($input->username . ':' . $input->password)) {
                $this->session->connectedWith = 'ws_session_login_api_key';
                return true;
            }
        } elseif ($this->authService->tryLogUser($input->username, $input->password, false)) {
            $this->session->connectedWith = 'ws_session_login';
            return true;
        }
        if (PageState::current()->loginFailureReason === 'account_locked') {
            return new PwgError(429, 'Account temporarily locked due to repeated failed logins');
        }
        return new PwgError(999, 'Invalid username/password');
    }
}
