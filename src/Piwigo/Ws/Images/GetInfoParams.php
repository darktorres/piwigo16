<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Piwigo\Ws\WsParams;

/**
 * `pwg.images.getInfo` input DTO. `image_id`: `WsParamType::ID`, no
 * 'default' key -- mandatory, always int. `comments_page`/
 * `comments_per_page`: `WsParamType::INT|POSITIVE`, non-null defaults
 * (0 / the configured comments-per-page) -- always present, always int.
 */
final readonly class GetInfoParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public int $commentsPage,
        public int $commentsPerPage,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $imageId = $raw['image_id'] ?? null;
        $commentsPage = $raw['comments_page'] ?? null;
        $commentsPerPage = $raw['comments_per_page'] ?? null;

        return new self(
            imageId: is_int($imageId) ? $imageId : 0,
            commentsPage: is_int($commentsPage) ? $commentsPage : 0,
            commentsPerPage: is_int($commentsPerPage) ? $commentsPerPage : 0,
        );
    }
}
