<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

use InvalidArgumentException;
use Override;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Group\GroupService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PATCH /api/v1/groups/{id}` -- `pwg.groups.setInfo`'s real replacement,
 * admin + CSRF. `{id}` is route-constrained to `\d+`, so an unmatched id
 * 404s at the routing layer before this controller ever runs.
 */
final readonly class GroupUpdateController implements ControllerInterface
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

        if ($this->groupService->getName(GroupId::from($groupId)) === null) {
            return ResponseFactory::problem('Not Found', 404, 'This group does not exist.');
        }

        $input = GroupUpdateInput::fromArray(JsonBody::decode($request));

        $updates = [];
        if ($input->name !== null) {
            $updates['name'] = strip_tags($input->name);
        }
        if ($input->isDefault !== null) {
            $updates['is_default'] = $input->isDefault;
        }

        try {
            $this->groupService->update(GroupId::from($groupId), $updates);
        } catch (InvalidArgumentException $e) {
            return ResponseFactory::problem('Unprocessable Entity', 422, $e->getMessage());
        }

        $rows = $this->groupService->getListWithMemberCounts([GroupId::from($groupId)]);

        return ResponseFactory::json(GroupPresenter::toArray($rows[0]));
    }
}
