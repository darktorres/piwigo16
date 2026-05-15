<?php

declare(strict_types=1);

namespace Piwigo\Event\Lifecycle;

/**
 * Typed event for legacy `load_image_library` (notify).
 *
 * Dispatched from: src/Piwigo/Admin/Image/PwgImage.php (PwgImage::__construct)
 */
final readonly class LoadImageLibrary
{
    public function __construct(
        public object $value,
    ) {
    }
}
