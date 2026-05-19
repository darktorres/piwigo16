<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.users.getAuthKey` input DTO. */
final readonly class GetAuthKeyParams implements WsParams
{
    public function __construct(
        public int $userId,
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
            userId:   is_numeric($raw['user_id'] ?? null) ? (int) $raw['user_id'] : 0,
            pwgToken: $pwgToken,
        );
    }
}
