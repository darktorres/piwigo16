<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

use Piwigo\Image\ImageType;

/**
 * Typed event for legacy `pwg_log_allowed` (dispatch).
 *
 * Dispatched from: src/Piwigo/Core/Util.php
 */
final readonly class PwgLogAllowed
{
    public function __construct(
        public bool $doLog,
        public int $imageId,
        public ?ImageType $imageType,
    ) {
    }
}
