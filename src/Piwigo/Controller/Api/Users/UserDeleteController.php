<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Override;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/v1/users/{id}` -- `pwg.users.delete`'s real replacement,
 * admin + CSRF, one user per call (a REST single-resource delete, same
 * shape decision as the Tags/Groups/Categories families). Photos owned
 * by this user are not deleted. Protects the caller's own account, the
 * guest/default/webmaster system accounts, and (for a plain admin,
 * not webmaster) every other admin account.
 */
final readonly class UserDeleteController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private UserService $userService,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
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

        if (! $this->userService->getUsername(UserId::from($userId)) instanceof Username) {
            return ResponseFactory::problem('Not Found', 404, 'This user does not exist.');
        }

        $currentUser = $this->currentUser->get();

        $protectedUsers = [
            $currentUser->id->value,
            $this->currentConfig->guestId,
            $this->currentConfig->defaultUserId,
            $this->currentConfig->webmasterId,
        ];
        if ($currentUser->status === UserStatus::Admin) {
            $protectedUsers = array_merge($protectedUsers, $this->userService->getAdminIds());
        }

        if (in_array($userId, $protectedUsers, true)) {
            return ResponseFactory::problem('Forbidden', 403, 'This user cannot be deleted.');
        }

        $this->userService->deleteUser(UserId::from($userId));

        return ResponseFactory::json([
            'id' => $userId,
        ]);
    }
}
