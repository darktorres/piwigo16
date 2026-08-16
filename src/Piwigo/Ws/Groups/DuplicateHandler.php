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
use Piwigo\Group\GroupService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.groups.duplicate` -- creates a copy of a group.
 */
final readonly class DuplicateHandler implements WsAction
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
        $input = DuplicateParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        try {
            $inserted_id = $this->groupService->duplicate(GroupId::from($input->groupId), $input->copyName);
        } catch (InvalidArgumentException $e) {
            return new WsErrorResponse(WsError::InvalidParam->value, $e->getMessage());
        }

        return $this->getListHandler->resolve([
            'group_id' => [$inserted_id->value],
        ]);
    }
}
