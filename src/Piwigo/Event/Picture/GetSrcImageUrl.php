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
final class GetSrcImageUrl
{
    public function __construct(
        public string $url,
        public readonly \Piwigo\Image\SrcImage $value,
    ) {
    }
}
