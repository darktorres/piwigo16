<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

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
 * `POST /api/v1/groups/actions/merge` -- `pwg.groups.merge`'s real
 * replacement, admin + CSRF. The merge-source groups' rows are fetched
 * *before* calling `GroupService::merge()` -- it deletes them as part of
 * the merge, same ordering `Ws\Groups\MergeHandler` uses for its own
 * `deleted_group` field.
 */
final readonly class GroupMergeController implements ControllerInterface
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

        $input = GroupMergeInput::fromArray(JsonBody::decode($request));
        $mergeGroupIds = array_map(GroupId::from(...), $input->mergeGroupIds);

        $deletedGroups = array_map(
            GroupPresenter::toArray(...),
            $this->groupService->getListWithMemberCounts($mergeGroupIds)
        );

        $merged = $this->groupService->merge(GroupId::from($input->destinationGroupId), $mergeGroupIds);
        if (! $merged) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'All groups does not exist.');
        }

        $destinationRows = $this->groupService->getListWithMemberCounts([GroupId::from($input->destinationGroupId)]);

        return ResponseFactory::json([
            'destinationGroup' => GroupPresenter::toArray($destinationRows[0]),
            'deletedGroups' => $deletedGroups,
        ]);
    }
}
