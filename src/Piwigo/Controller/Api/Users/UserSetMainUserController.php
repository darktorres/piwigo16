<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\ConfigService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/users/{id}/actions/set-main-user` --
 * `pwg.users.setMainUser`'s real replacement, admin + CSRF, webmaster
 * only (a stricter gate than plain admin -- `AdminGuard` handles the
 * 401/403 admin split, `AccessControl::isWebmaster()` adds the extra
 * webmaster-only requirement on top). To become the main user, the
 * target must already have "webmaster" status.
 */
final readonly class UserSetMainUserController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private AccessControl $accessControl,
        private UserService $userService,
        private ConfigService $configService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        if (! $this->accessControl->isWebmaster()) {
            return ResponseFactory::problem('Forbidden', 403, 'You cannot perform this action.');
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $newMainUserId = is_string($rawId) ? (int) $rawId : 0;

        if (! $this->userService->getUsername(UserId::from($newMainUserId)) instanceof Username) {
            return ResponseFactory::problem('Not Found', 404, 'This user does not exist.');
        }

        $newMainUser = $this->userService->getUserData(UserId::from($newMainUserId));
        if (($newMainUser['status'] ?? null) !== 'webmaster') {
            return ResponseFactory::problem('Forbidden', 403, 'This user cannot become a main user because they are not a webmaster.');
        }

        $this->configService->confUpdateParam('webmaster_id', $newMainUserId);

        return ResponseFactory::noContent();
    }
}
