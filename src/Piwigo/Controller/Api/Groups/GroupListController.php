<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

use Override;
use Piwigo\Group\GroupService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/groups` -- `pwg.groups.getList`'s real replacement, admin
 * only. No filter/pagination params yet (matches `TagListController`'s
 * own current scope) -- `Ws\Groups\GetListHandler`'s `group_id`/`name`/
 * `per_page`/`page`/`order` params can be added once this family's own
 * pagination design lands, same deferral the REST design section already
 * calls out for the whole surface.
 */
final readonly class GroupListController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private GroupService $groupService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $groups = array_map(
            GroupPresenter::toArray(...),
            $this->groupService->getListWithMemberCounts()
        );

        return ResponseFactory::json([
            'groups' => $groups,
        ]);
    }
}
