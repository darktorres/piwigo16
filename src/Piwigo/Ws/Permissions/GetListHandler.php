<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Permissions;

use Piwigo\Core\WsError;
use Piwigo\Permission\PermissionService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.permissions.getList` -- per-album direct/indirect access matrix.
 */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private PermissionService $permissionService,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{categories: NamedArray}
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $providedCount = (array_key_exists('cat_id', $params) ? 1 : 0)
            + (array_key_exists('group_id', $params) ? 1 : 0)
            + (array_key_exists('user_id', $params) ? 1 : 0);
        if ($providedCount > 1) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Too many parameters, provide cat_id OR user_id OR group_id');
        }

        $input = GetListParams::fromArray($params);
        $cat_ids_filter = $input->catIds;

        $perms = [];

        // direct users
        foreach ($this->permissionService->getDirectUserAccessRows($cat_ids_filter) as $row) {
            $cat_id = $row->catId;
            if (! isset($perms[$cat_id])) {
                $perms[$cat_id]['id'] = $cat_id;
            }
            $perms[$cat_id]['users'][] = $row->userId;
        }

        // indirect users
        foreach ($this->permissionService->getIndirectUserAccessRows($cat_ids_filter) as $row) {
            $cat_id = $row->catId;
            if (! isset($perms[$cat_id])) {
                $perms[$cat_id]['id'] = $cat_id;
            }
            $perms[$cat_id]['users_indirect'][] = $row->userId;
        }

        // groups
        foreach ($this->permissionService->getGroupAccessRows($cat_ids_filter) as $row) {
            $cat_id = $row->catId;
            if (! isset($perms[$cat_id])) {
                $perms[$cat_id]['id'] = $cat_id;
            }
            $perms[$cat_id]['groups'][] = $row->groupId;
        }

        // filter by group and user
        foreach ($perms as $cat_id => &$cat) {
            if ($input->groupIdSet) {
                if (! isset($cat['groups']) or count(array_intersect($cat['groups'], $input->groupIds)) === 0) {
                    unset($perms[$cat_id]);
                    continue;
                }
            }
            if ($input->userIdSet) {
                if (
                    (! isset($cat['users_indirect']) or count(array_intersect($cat['users_indirect'], $input->userIds)) === 0)
                    and (! isset($cat['users']) or count(array_intersect($cat['users'], $input->userIds)) === 0)
                ) {
                    unset($perms[$cat_id]);
                    continue;
                }
            }

            $cat['groups'] = isset($cat['groups']) ? array_values(array_unique($cat['groups'])) : [];
            $cat['users'] = isset($cat['users']) ? array_values(array_unique($cat['users'])) : [];
            $cat['users_indirect'] = isset($cat['users_indirect']) ? array_values(array_unique($cat['users_indirect'])) : [];
        }
        unset($cat);

        return [
            'categories' => new NamedArray(
                array_values($perms),
                'category',
                ['id']
            ),
        ];
    }
}
