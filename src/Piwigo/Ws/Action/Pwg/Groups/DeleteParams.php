<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.groups.delete` input DTO.
 *
 * `group_id` may come in as a scalar (single id) or an array (bulk
 * delete). `groupIds` carries the normalized list shape that
 * UserAdminService::deleteGroups() can consume directly.
 */
final readonly class DeleteParams implements WsParams
{
    /** @param list<int> $groupIds */
    public function __construct(
        public string $pwgToken,
        public array $groupIds,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        if (!is_string($pwgToken)) {
            throw new WsParamException('Missing pwg_token');
        }
        $rawGroup = $raw['group_id'] ?? null;
        if (is_array($rawGroup)) {
            $groupIds = array_values(array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                $rawGroup,
            ));
        } elseif (is_numeric($rawGroup)) {
            $groupIds = [(int) $rawGroup];
        } else {
            $groupIds = [];
        }
        return new self(pwgToken: $pwgToken, groupIds: $groupIds);
    }
}
