<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `get_src_image_url` (dispatch).
 *
 * New in 2.4
 *
 * Dispatched from: src/Piwigo/Image/SrcImage.php
 */
final readonly class GetSrcImageUrl
{
    public function __construct(
        public string $url,
        public \Piwigo\Image\SrcImage $value,
    ) {
    }
}
