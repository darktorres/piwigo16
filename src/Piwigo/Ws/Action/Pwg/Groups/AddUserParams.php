<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.groups.addUser` input DTO. */
final readonly class AddUserParams implements WsParams
{
    /** @param list<int> $userIds */
    public function __construct(
        public string $pwgToken,
        public int $groupId,
        public array $userIds,
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
        $userIdRaw = is_array($raw['user_id'] ?? null) ? $raw['user_id'] : [];
        $userIds   = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $userIdRaw,
        ));
        return new self(
            pwgToken: $pwgToken,
            groupId:  is_numeric($raw['group_id'] ?? null) ? (int) $raw['group_id'] : 0,
            userIds:  $userIds,
        );
    }
}
