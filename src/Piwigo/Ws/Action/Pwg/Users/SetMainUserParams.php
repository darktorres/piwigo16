<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.users.setMainUser` input DTO. */
final readonly class SetMainUserParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
        public int $userId,
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
            pwgToken: $pwgToken,
            userId:   is_numeric($raw['user_id'] ?? null) ? (int) $raw['user_id'] : 0,
        );
    }
}
