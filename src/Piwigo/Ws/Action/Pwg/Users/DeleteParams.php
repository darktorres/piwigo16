<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.users.delete` input DTO. */
final readonly class DeleteParams implements WsParams
{
    /** @param list<int> $userIds */
    public function __construct(
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
            userIds: array_values(array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                is_array($raw['user_id'] ?? null) ? $raw['user_id'] : [],
            )),
            pwgToken: $pwgToken,
        );
    }
}
