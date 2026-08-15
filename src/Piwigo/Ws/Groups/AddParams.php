<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Groups;

use Piwigo\Ws\WsParams;

/**
 * `pwg.groups.add` input DTO. `name` has no 'default' key -- mandatory,
 * always present. `is_default`: non-null bool default, `WsParamType::BOOL`
 * -- always present. `pwg_token` has no 'default' key either -- mandatory,
 * always present, matching every sibling Groups mutation (DeleteParams,
 * SetInfoParams).
 */
final readonly class AddParams implements WsParams
{
    public function __construct(
        public string $name,
        public bool $isDefault,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $name = $raw['name'] ?? null;
        $isDefault = $raw['is_default'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            name: is_string($name) ? $name : '',
            isDefault: is_bool($isDefault) ? $isDefault : false,
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
