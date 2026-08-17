<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

use InvalidArgumentException;
use Override;
use Piwigo\Audit\AuditService;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Group\GroupService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/groups` -- `pwg.groups.add`'s real replacement, admin +
 * CSRF. [SEC-57]: the security-audit-trail `AuditService::record()` call
 * is external to `GroupService::create()` itself (unlike its own
 * activity-log entry).
 */
final readonly class GroupCreateController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private GroupService $groupService,
        private CurrentUser $currentUser,
        private AuditService $auditService,
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

        $input = GroupCreateInput::fromArray(JsonBody::decode($request));
        $name = strip_tags($input->name);

        try {
            $insertedId = $this->groupService->create($name, $input->isDefault);
        } catch (InvalidArgumentException $e) {
            return ResponseFactory::problem('Unprocessable Entity', 422, $e->getMessage());
        }

        $actorId = $this->currentUser->get()
            ->id;
        $this->auditService->record($actorId, 'create', 'group', $insertedId->value, null, [
            'name' => $name,
        ]);

        $rows = $this->groupService->getListWithMemberCounts([GroupId::from($insertedId->value)]);

        return ResponseFactory::json(GroupPresenter::toArray($rows[0]), 201);
    }
}
