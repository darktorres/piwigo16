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
 * `pwg.images.checkFiles` input DTO. `image_id`: `WsParamType::ID`, no
 * 'default' key -- mandatory, always int. `file_sum`/`thumbnail_sum`/
 * `high_sum`: no type flag, null default -- string|null (`isset()` on
 * the original's own `$params[...]` reads treats a present-but-null
 * value the same as absent, matching this DTO's null-when-absent-or-null
 * fields).
 */
final readonly class CheckFilesParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public ?string $fileSum,
        public ?string $thumbnailSum,
        public ?string $highSum,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $imageId = $raw['image_id'] ?? null;
        $fileSum = $raw['file_sum'] ?? null;
        $thumbnailSum = $raw['thumbnail_sum'] ?? null;
        $highSum = $raw['high_sum'] ?? null;

        return new self(
            imageId: is_int($imageId) ? $imageId : 0,
            fileSum: is_string($fileSum) ? $fileSum : null,
            thumbnailSum: is_string($thumbnailSum) ? $thumbnailSum : null,
            highSum: is_string($highSum) ? $highSum : null,
        );
    }
}
