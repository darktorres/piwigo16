<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Override;
use Piwigo\Auth\AuthService;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\ConnectedWith;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/session` -- `pwg.session.login`'s real replacement. No
 * CSRF check, matching `Ws\Session\LoginHandler`'s own shape -- there's
 * no session-scoped token to present before a session exists. Returns
 * the resulting session status (`SessionStatusPresenter`, shared with
 * `GET`/`DELETE /api/v1/session`) on success, matching REST's own
 * "return the resource after mutating it" convention rather than a bare
 * acknowledgment.
 */
final readonly class SessionLoginController implements ControllerInterface
{
    public function __construct(
        private ApiKeyRequestFlag $apiKeyRequestFlag,
        private AuthService $authService,
        private ConnectedWithSession $connectedWithSession,
        private SessionStatusPresenter $sessionStatusPresenter,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->apiKeyRequestFlag->isActive()) {
            return ResponseFactory::problem('Unauthorized', 401, 'Cannot use this method with an api key.');
        }

        $input = SessionLoginInput::fromArray(JsonBody::decode($request));

        if (preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $input->username) === 1) {
            // The combined "username:password" string must match
            // authKeyLogin()'s own strict [a-z0-9]-only regex to be
            // considered valid at all, so it never needs escaping.
            $authenticated = $this->authService->authKeyLogin($input->username . ':' . $input->password);
            if ($authenticated) {
                $this->connectedWithSession->set(ConnectedWith::ApiSessionLoginApiKey);

                return ResponseFactory::json($this->sessionStatusPresenter->present());
            }
        } elseif ($this->authService->tryLogUser($input->username, $input->password, false)) {
            $this->connectedWithSession->set(ConnectedWith::ApiSessionLogin);

            return ResponseFactory::json($this->sessionStatusPresenter->present());
        }

        return ResponseFactory::problem('Unauthorized', 401, 'Invalid username/password.');
    }
}
