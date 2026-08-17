<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Override;
use Piwigo\Auth\AuthService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\ConnectedWith;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/session` -- `pwg.session.login`'s real replacement. No
 * CSRF check -- there's no session-scoped token to present before a
 * session exists. Returns
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
        private CurrentUser $currentUser,
        private UserService $userService,
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
                $this->refreshCurrentUser();

                return ResponseFactory::json($this->sessionStatusPresenter->present());
            }
        } elseif ($this->authService->tryLogUser($input->username, $input->password, false)) {
            $this->connectedWithSession->set(ConnectedWith::ApiSessionLogin);
            $this->refreshCurrentUser();

            return ResponseFactory::json($this->sessionStatusPresenter->present());
        }

        return ResponseFactory::problem('Unauthorized', 401, 'Invalid username/password.');
    }

    /**
     * AuthService::logUser() (the real effect behind both branches above)
     * only ever writes $_SESSION['pwg_uid'] -- it never updates
     * CurrentUser, the per-request singleton SessionStatusPresenter reads.
     * Without this, this endpoint's own response would report the
     * pre-login (guest) status even though the session itself is now
     * correctly authenticated -- confirmed live: a follow-up GET
     * /api/v1/session on the same cookie already returns the right
     * status, only this response's own body was stale. Mirrors
     * Bootstrap\UserBootstrap::initialize()'s own buildUser()+set()
     * pattern, the only other real place a user id gets turned into a
     * synced CurrentUser.
     */
    private function refreshCurrentUser(): void
    {
        $userId = $_SESSION['pwg_uid'] ?? null;
        if (! is_numeric($userId)) {
            return;
        }

        $user = $this->userService->buildUser(UserId::from((int) $userId));
        $this->currentUser->set(User::fromUserArray($user));
    }
}
