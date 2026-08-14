<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\History;

use Piwigo\Ws\WsParams;

/**
 * `pwg.history.log` input DTO. `image_id`: `WsParamType::ID`, no
 * 'default' key -- mandatory, always int. `cat_id`: `WsParamType::ID`,
 * null default -- int|null. `section`/`tags_string`: no type flag, null
 * default -- string|null. `is_download`: `WsParamType::BOOL`, default
 * false (non-null) -- always bool.
 *
 * @since 13
 */
final readonly class LogParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public ?int $catId,
        public ?string $section,
        public ?string $tagsString,
        public bool $isDownload,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $imageId = $raw['image_id'] ?? null;
        $catId = $raw['cat_id'] ?? null;
        $section = $raw['section'] ?? null;
        $tagsString = $raw['tags_string'] ?? null;
        $isDownload = $raw['is_download'] ?? null;

        return new self(
            imageId: is_int($imageId) ? $imageId : 0,
            catId: is_int($catId) ? $catId : null,
            section: is_string($section) && $section !== '' ? $section : null,
            tagsString: is_string($tagsString) && $tagsString !== '' ? $tagsString : null,
            isDownload: is_bool($isDownload) ? $isDownload : false,
        );
    }
}
