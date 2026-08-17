<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

use Override;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Group\GroupService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/groups/{id}/actions/remove-user` --
 * `pwg.groups.deleteUser`'s real replacement, admin + CSRF. `{id}` is
 * route-constrained to `\d+`, so an unmatched id 404s at the routing
 * layer before this controller ever runs.
 */
final readonly class GroupRemoveUserController implements ControllerInterface
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

        $input = GroupUserIdsInput::fromArray(JsonBody::decode($request));

        $removed = $this->groupService->removeMembers(
            GroupId::from($groupId),
            array_map(UserId::from(...), $input->userIds)
        );
        if (! $removed) {
            return ResponseFactory::problem('Not Found', 404, 'This group does not exist.');
        }

        $rows = $this->groupService->getListWithMemberCounts([GroupId::from($groupId)]);

        return ResponseFactory::json(GroupPresenter::toArray($rows[0]));
    }
}
