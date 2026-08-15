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
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.groups.merge` -- merge groups into one other group.
 *
 * Both return values are `$server->invoke('pwg.groups.getList', ...)`'s
 * own result -- same by-name-dispatcher rationale as `AddHandler`/
 * `SetInfoHandler`.
 */
final readonly class MergeHandler implements WsAction
{
    public function __construct(
        private GroupService $groupService,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{destination_group: mixed, deleted_group: mixed}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = MergeParams::fromArray($params);

        if (new CsrfService($this->currentConfig)->getToken() !== $input->pwgToken) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $merge_group_object = $server->invoke('pwg.groups.getList', [
            'group_id' => $input->mergeGroupIds,
        ]);

        $merged = $this->groupService->merge(GroupId::from($input->destinationGroupId), array_map(GroupId::from(...), $input->mergeGroupIds));
        if (! $merged) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'All groups does not exist.');
        }

        return [
            'destination_group' => $server->invoke('pwg.groups.getList', [
                'group_id' => $input->destinationGroupId,
            ]),
            'deleted_group' => $merge_group_object,
        ];
    }
}
