<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Tags;

use Piwigo\Ws\WsParams;

/**
 * `pwg.tags.duplicate` input DTO. None has a 'default' key -- all
 * mandatory, always present, `WsParamType::ID` guarantees a plain int
 * for `tag_id`.
 */
final readonly class DuplicateParams implements WsParams
{
    public function __construct(
        public int $tagId,
        public string $copyName,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $tagId = $raw['tag_id'] ?? null;
        $copyName = $raw['copy_name'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            tagId: is_int($tagId) ? $tagId : 0,
            copyName: is_string($copyName) ? $copyName : '',
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
