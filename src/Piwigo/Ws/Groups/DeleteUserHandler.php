<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Groups;

use Override;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Group\GroupService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.groups.deleteUser` -- removes one or more users from a group.
 */
final readonly class DeleteUserHandler implements WsAction
{
    public function __construct(
        private GroupService $groupService,
        private WsCsrfGuard $wsCsrfGuard,
        private GetListHandler $getListHandler,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{paging: NamedStruct, groups: NamedArray}
     *   the result of GetListHandler::resolve(), called directly (P25
     *   Stage 1's recursive-dispatch removal)
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = DeleteUserParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $removed = $this->groupService->removeMembers(GroupId::from($input->groupId), array_map(UserId::from(...), $input->userIds));
        if (! $removed) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'This group does not exist.');
        }

        return $this->getListHandler->resolve([
            'group_id' => [$input->groupId],
        ]);
    }
}
