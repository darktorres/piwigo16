<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Groups;

use InvalidArgumentException;
use Override;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Core\WsError;
use Piwigo\Group\GroupService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.groups.setInfo` -- updates a group. Leave a field blank to keep the current value.
 */
final readonly class SetInfoHandler implements WsAction
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
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = SetInfoParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $updates = [];
        if ($input->name !== null) {
            $updates['name'] = strip_tags($input->name);
        }

        if ($input->isDefault !== null) {
            $updates['is_default'] = $input->isDefault;
        }

        try {
            $this->groupService->update(GroupId::from($input->groupId), $updates);
        } catch (InvalidArgumentException $e) {
            return new WsErrorResponse(WsError::InvalidParam->value, $e->getMessage());
        }

        return $this->getListHandler->resolve([
            'group_id' => [$input->groupId],
        ]);
    }
}
