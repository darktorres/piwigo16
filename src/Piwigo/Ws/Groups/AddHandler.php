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
use Piwigo\Audit\AuditService;
use Piwigo\Core\WsError;
use Piwigo\Group\GroupService;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.groups.add` -- creates a group and returns the new group record.
 */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private GroupService $groupService,
        private CurrentUser $currentUser,
        private AuditService $auditService,
        private WsHelper $wsHelper,
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
        $input = AddParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $name = strip_tags($input->name);

        try {
            $inserted_id = $this->groupService->create($name, $input->isDefault);
        } catch (InvalidArgumentException $e) {
            return new WsErrorResponse(WsError::INVALID_PARAM, $e->getMessage());
        }

        // [SEC-57]
        $actor_id = $this->currentUser->get()
            ->id->value;
        $this->auditService
            ->record($actor_id, 'create', 'group', $inserted_id->value, null, [
                'name' => $name,
            ]);

        return $this->getListHandler->resolve([
            'group_id' => [$inserted_id->value],
        ]);
    }
}
