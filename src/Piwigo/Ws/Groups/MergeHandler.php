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
use Piwigo\Core\WsError;
use Piwigo\Group\GroupService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.groups.merge` -- merge groups into one other group.
 *
 * Both return values are `GetListHandler::resolve()`'s own result,
 * called directly (P25 Stage 1's recursive-dispatch removal) rather than
 * through `Server::invoke()`'s string-keyed dispatch.
 */
final readonly class MergeHandler implements WsAction
{
    public function __construct(
        private GroupService $groupService,
        private WsCsrfGuard $wsCsrfGuard,
        private GetListHandler $getListHandler,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{destination_group: mixed, deleted_group: mixed}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = MergeParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $merge_group_object = $this->getListHandler->resolve([
            'group_id' => $input->mergeGroupIds,
        ]);

        $merged = $this->groupService->merge(GroupId::from($input->destinationGroupId), array_map(GroupId::from(...), $input->mergeGroupIds));
        if (! $merged) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'All groups does not exist.');
        }

        return [
            'destination_group' => $this->getListHandler->resolve([
                'group_id' => [$input->destinationGroupId],
            ]),
            'deleted_group' => $merge_group_object,
        ];
    }
}
