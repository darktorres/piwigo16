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
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.groups.setInfo` -- updates a group. Leave a field blank to keep the current value.
 */
final readonly class SetInfoHandler implements WsAction
{
    public function __construct(
        private GroupService $groupService,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<array-key, mixed> WsErrorResponse, or the result of
     *   the pwg.groups.getList invocation
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = SetInfoParams::fromArray($params);

        if (new CsrfService($this->currentConfig)->getToken() !== $input->pwgToken) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $updates = [];
        if ($input->name !== null) {
            $updates['name'] = strip_tags(stripslashes($input->name));
        }

        if ($input->isDefault !== null) {
            $updates['is_default'] = $input->isDefault;
        }

        try {
            $this->groupService->update(GroupId::from($input->groupId), $updates);
        } catch (InvalidArgumentException $e) {
            return new WsErrorResponse(WsError::INVALID_PARAM, $e->getMessage());
        }

        return $this->narrowGetListResult($server->invoke('pwg.groups.getList', [
            'group_id' => $input->groupId,
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
