<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.users.setInfo` input DTO — wraps the raw payload that
 * UserService::checkAndSaveUserInfos() consumes.
 */
final readonly class SetInfoParams implements WsParams
{
    /** @param array<int|string, mixed> $payload */
    public function __construct(
        public string $pwgToken,
        public array $payload,
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
        return new self(pwgToken: $pwgToken, payload: $raw);
    }
}
