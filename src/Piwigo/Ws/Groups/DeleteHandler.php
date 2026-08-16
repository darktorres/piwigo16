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
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Group\GroupService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.groups.delete` -- deletes one or more groups. Users and photos are not deleted.
 */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private GroupService $groupService,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|NamedArray
    {
        $input = DeleteParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $deleted_groups = $this->groupService->delete(array_map(GroupId::from(...), $input->groupIds));
        if ($deleted_groups === false) {
            return new WsErrorResponse(500, 'There is no group to delete');
        }
        $groupnames = array_values($deleted_groups);

        PermissionCacheInvalidator::invalidate();

        return new NamedArray($groupnames, 'group_deleted');
    }
}
