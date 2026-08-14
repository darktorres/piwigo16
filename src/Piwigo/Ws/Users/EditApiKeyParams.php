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
 * `pwg.users.api_key.edit` input DTO. None has a 'default' key -- all mandatory, always present, no 'type' flag.
 */
final readonly class EditApiKeyParams implements WsParams
{
    public function __construct(
        public string $keyName,
        public string $pkid,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $keyName = $raw['key_name'] ?? null;
        $pkid = $raw['pkid'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            keyName: is_string($keyName) ? $keyName : '',
            pkid: is_string($pkid) ? $pkid : '',
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
