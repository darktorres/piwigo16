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
 * `pwg.users.api_key.get` input DTO. No 'default' key -- mandatory, always present, no 'type' flag.
 */
final readonly class GetApiKeyParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
