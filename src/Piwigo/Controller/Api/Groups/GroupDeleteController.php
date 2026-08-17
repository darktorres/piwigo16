<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

use Override;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Group\GroupService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/v1/groups/{id}` -- `pwg.groups.delete`'s real replacement,
 * admin + CSRF, one group per call (a REST single-resource delete, same
 * shape decision as `TagDeleteController`). Users and photos are not
 * deleted. `GroupService::delete()` records its own activity entry and
 * [SEC-57] audit trail internally, but deliberately not the permission
 * cache invalidation -- its own docblock says callers do that, matching
 * `Ws\Groups\DeleteHandler`'s own explicit `PermissionCacheInvalidator::
 * invalidate()` call.
 */
final readonly class GroupDeleteController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private GroupService $groupService,
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
        $groupId = is_string($rawId) ? (int) $rawId : 0;

        $deleted = $this->groupService->delete([GroupId::from($groupId)]);
        if ($deleted === false || $deleted === []) {
            return ResponseFactory::problem('Not Found', 404, 'This group does not exist.');
        }

        PermissionCacheInvalidator::invalidate();

        return ResponseFactory::json([
            'id' => $groupId,
        ]);
    }
}
