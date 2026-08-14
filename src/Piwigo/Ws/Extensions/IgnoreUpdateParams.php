<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Extensions;

use Piwigo\Ws\WsParams;

/**
 * `pwg.extensions.ignoreUpdate` input DTO. `type`/`id`: null default, no
 * 'type' flag -- always present, string|null. `reset`: non-null bool
 * default, `WsParamType::BOOL` -- always present. `pwg_token`: no
 * 'default' key -- mandatory, always present.
 */
final readonly class IgnoreUpdateParams implements WsParams
{
    public function __construct(
        public ?string $type,
        public ?string $id,
        public bool $reset,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $type = $raw['type'] ?? null;
        $id = $raw['id'] ?? null;
        $reset = $raw['reset'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            type: is_string($type) ? $type : null,
            id: is_string($id) ? $id : null,
            reset: is_bool($reset) ? $reset : false,
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
