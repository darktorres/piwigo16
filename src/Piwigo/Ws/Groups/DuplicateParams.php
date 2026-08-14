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
 * `pwg.groups.duplicate` input DTO. None has a 'default' key -- all
 * mandatory, always present, `WsParamType::ID` guarantees a plain int
 * for `group_id`.
 */
final readonly class DuplicateParams implements WsParams
{
    public function __construct(
        public int $groupId,
        public string $copyName,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $groupId = $raw['group_id'] ?? null;
        $copyName = $raw['copy_name'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            groupId: is_int($groupId) ? $groupId : 0,
            copyName: is_string($copyName) ? $copyName : '',
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
