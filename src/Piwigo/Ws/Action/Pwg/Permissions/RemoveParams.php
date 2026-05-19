<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Permissions;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.permissions.remove` input DTO. */
final readonly class RemoveParams implements WsParams
{
    /**
     * @param list<int> $categoryIds
     * @param list<int> $groupIds
     * @param list<int> $userIds
     */
    public function __construct(
        public array $categoryIds,
        public array $groupIds,
        public array $userIds,
        public string $pwgToken,
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
        return new self(
            categoryIds: array_values(array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                is_array($raw['cat_id'] ?? null) ? $raw['cat_id'] : [],
            )),
            groupIds: array_values(array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                is_array($raw['group_id'] ?? null) ? $raw['group_id'] : [],
            )),
            userIds: array_values(array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                is_array($raw['user_id'] ?? null) ? $raw['user_id'] : [],
            )),
            pwgToken: $pwgToken,
        );
    }
}
