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
 * `pwg.groups.setInfo` input DTO. `group_id`/`pwg_token`: no 'default'
 * key -- mandatory, always present, `WsParamType::ID` guarantees a plain
 * int for `group_id`. `name`/`is_default`: `OPTIONAL` with no 'default'
 * key -- null when absent, matching the original's `isset($params[...])`
 * guards.
 */
final readonly class SetInfoParams implements WsParams
{
    public function __construct(
        public int $groupId,
        public ?string $name,
        public ?bool $isDefault,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $groupId = $raw['group_id'] ?? null;
        $name = $raw['name'] ?? null;
        $isDefault = $raw['is_default'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            groupId: is_int($groupId) ? $groupId : 0,
            name: is_string($name) ? $name : null,
            isDefault: is_bool($isDefault) ? $isDefault : null,
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
