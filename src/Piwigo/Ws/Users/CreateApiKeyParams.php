<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Piwigo\Ws\WsParams;

/**
 * `pwg.users.api_key.create` input DTO. None has a 'default' key -- all
 * mandatory, always present; `duration`: `WsParamType::INT |
 * WsParamType::POSITIVE` guarantees a plain int.
 */
final readonly class CreateApiKeyParams implements WsParams
{
    public function __construct(
        public string $keyName,
        public int $duration,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $keyName = $raw['key_name'] ?? null;
        $duration = $raw['duration'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            keyName: is_string($keyName) ? $keyName : '',
            duration: is_int($duration) ? $duration : 0,
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
