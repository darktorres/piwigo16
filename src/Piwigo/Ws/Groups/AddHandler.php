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
use LogicException;
use Piwigo\Audit\AuditService;
use Piwigo\Core\WsError;
use Piwigo\Group\GroupService;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.groups.add` -- creates a group and returns the new group record.
 */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private GroupService $groupService,
        private CurrentUser $currentUser,
        private AuditService $auditService,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<array-key, mixed> WsErrorResponse, or the result of
     *   the pwg.groups.getList invocation
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = AddParams::fromArray($params);
        $name = strip_tags(stripslashes($input->name));

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

        return $this->narrowGetListResult($server->invoke('pwg.groups.getList', [
            'group_id' => $inserted_id->value,
        ]));
    }

    /**
     * $server->invoke() is a genuine string-keyed dynamic dispatcher (see
     * Server's own class docblock) -- its declared return type is
     * `mixed` by design. This narrows it to the real shape this specific
     * sub-invocation (always 'pwg.groups.getList', which itself really
     * does return WsErrorResponse|array{paging: NamedStruct, groups: NamedArray})
     * is known to return, the same "resolve, narrow, or throw" idiom
     * already used throughout this codebase for other statically-
     * unknowable-but-really-fixed-shape values.
     *
     * @return WsErrorResponse|array<array-key, mixed>
     */
    private function narrowGetListResult(mixed $result): WsErrorResponse|array
    {
        if (! $result instanceof WsErrorResponse && ! is_array($result)) {
            throw new LogicException('pwg.groups.getList returned an unexpected type');
        }

        return $result;
    }
}
