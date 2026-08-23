<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Override;
use Piwigo\Auth\AuthService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/users/{id}/actions/get-auth-key` --
 * `pwg.users.getAuthKey`'s real replacement, admin + CSRF. Combined with
 * `AuthService::createUserAuthKey()`'s own target-side restriction (only
 * works for normal/generic-status accounts, never admins/webmasters), the
 * real feature is an admin generating a magic-login link for a normal
 * user -- an account-recovery/support tool, not a general-purpose "any
 * user can log in as any other user" capability.
 */
final readonly class UserGetAuthKeyController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private AuthService $authService,
        private CsrfGuard $csrfGuard,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
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
            'authKey' => $authKey->authKey,
            'authKeyId' => $authKey->authKeyId,
            'userId' => $authKey->userId,
            'createdOn' => $authKey->createdOn,
            'duration' => $authKey->duration,
            'expiredOn' => $authKey->expiredOn,
            'keyType' => $authKey->keyType,
        ]);
    }
}
