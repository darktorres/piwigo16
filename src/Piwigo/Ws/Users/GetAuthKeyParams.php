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
 * `pwg.users.getAuthKey` input DTO. Neither has a 'default' key -- both
 * mandatory, always present. `user_id`: `WsParamType::ID`, not
 * `FORCE_ARRAY` here -- a plain int.
 */
final readonly class GetAuthKeyParams implements WsParams
{
    public function __construct(
        public int $userId,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $userId = $raw['user_id'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            userId: is_int($userId) ? $userId : 0,
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
