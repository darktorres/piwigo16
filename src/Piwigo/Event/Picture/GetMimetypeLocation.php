<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `get_mimetype_location` (dispatch).
 *
 * Dispatched from: src/Piwigo/Image/SrcImage.php
 */
final readonly class GetMimetypeLocation
{
    public function __construct(
        public string $url,
        public string $ext,
    ) {
    }
}
