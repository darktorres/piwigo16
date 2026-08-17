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
 * `POST /api/v1/groups/{id}/actions/duplicate` -- `pwg.groups.duplicate`'s
 * real replacement, admin + CSRF. `{id}` is route-constrained to `\d+`,
 * so an unmatched id 404s at the routing layer before this controller
 * ever runs.
 */
final readonly class GroupDuplicateController implements ControllerInterface
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

        $input = GroupDuplicateInput::fromArray(JsonBody::decode($request));

        try {
            $newId = $this->groupService->duplicate(GroupId::from($groupId), $input->name);
        } catch (InvalidArgumentException $e) {
            $status = $e->getMessage() === 'This group does not exist.' ? 404 : 422;
            $title = $status === 404 ? 'Not Found' : 'Unprocessable Entity';

            return ResponseFactory::problem($title, $status, $e->getMessage());
        }

        $rows = $this->groupService->getListWithMemberCounts([GroupId::from($newId->value)]);

        return ResponseFactory::json(GroupPresenter::toArray($rows[0]), 201);
    }
}
