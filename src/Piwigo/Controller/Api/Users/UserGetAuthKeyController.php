<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/users/{id}/actions/get-auth-key` --
 * `pwg.users.getAuthKey`'s real replacement. Any signed-in (non-guest)
 * caller, not admin-gated -- `Ws\Users\GetAuthKeyHandler`'s own
 * registration is `requiresAuth: true` (any authenticated session), not
 * an admin-only method; the real safety boundary is
 * `AuthService::createUserAuthKey()` itself, which only works for
 * normal/generic-status target accounts (its own docblock), not
 * admins/webmasters.
 */
final readonly class UserGetAuthKeyController implements ControllerInterface
{
    public function __construct(
        private AccessControl $accessControl,
        private AuthService $authService,
        private CsrfGuard $csrfGuard,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->accessControl->isAGuest()) {
            return ResponseFactory::problem('Unauthorized', 401, 'Access denied.');
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $userId = is_string($rawId) ? (int) $rawId : 0;

        $authKey = $this->authService->createUserAuthKey($userId);
        if ($authKey === false) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid user id.');
        }

        return ResponseFactory::json([
            'authKey' => $authKey['auth_key'] ?? null,
            'authKeyId' => $authKey['auth_key_id'] ?? null,
            'userId' => $authKey['user_id'] ?? null,
            'createdOn' => $authKey['created_on'] ?? null,
            'duration' => $authKey['duration'] ?? null,
            'expiredOn' => $authKey['expired_on'] ?? null,
            'keyType' => $authKey['key_type'] ?? null,
        ]);
    }
}
