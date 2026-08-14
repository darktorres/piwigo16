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
 * `pwg.users.favorites.add` input DTO. No 'default' key -- mandatory, always present, `WsParamType::ID` guarantees a plain int.
 */
final readonly class FavoritesAddParams implements WsParams
{
    public function __construct(
        public int $imageId,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $imageId = $raw['image_id'] ?? null;

        return new self(
            imageId: is_int($imageId) ? $imageId : 0,
        );
    }
}
