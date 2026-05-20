<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.users.createApiKey` input DTO.
 *
 * `rawDuration` is the original value for the range check; `duration`
 * is the normalized positive int (0 → 1) for the createApiKey call.
 */
final readonly class CreateApiKeyParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
        public mixed $rawDuration,
        public int $duration,
        public string $keyName,
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
        $durationRaw = $raw['duration'] ?? null;
        $duration    = is_numeric($durationRaw) ? (0 == (int) $durationRaw ? 1 : (int) $durationRaw) : 1;
        $keyNameIn   = $raw['key_name'] ?? null;
        return new self(
            pwgToken:    $pwgToken,
            rawDuration: $durationRaw,
            duration:    $duration,
            keyName:     is_string($keyNameIn) ? $keyNameIn : '',
        );
    }
}
