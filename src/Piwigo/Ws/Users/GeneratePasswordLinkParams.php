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
 * `pwg.users.generatePasswordLink` input DTO. `user_id`/`pwg_token`: no
 * 'default' key -- mandatory, always present, `WsParamType::ID`
 * guarantees a plain int for `user_id`. `send_by_mail`: non-null bool
 * default, always present.
 */
final readonly class GeneratePasswordLinkParams implements WsParams
{
    public function __construct(
        public int $userId,
        public string $pwgToken,
        public bool $sendByMail,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $userId = $raw['user_id'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;
        $sendByMail = $raw['send_by_mail'] ?? null;

        return new self(
            userId: is_int($userId) ? $userId : 0,
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
            sendByMail: is_bool($sendByMail) ? $sendByMail : false,
        );
    }
}
