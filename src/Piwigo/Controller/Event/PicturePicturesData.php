<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

/**
 * Typed event for the legacy `picture_pictures_data` filter. No handler
 * is registered for it anywhere today. No context -- every real call
 * site passes only the picture data.
 */
final class PicturePicturesData
{
    /**
     * @param array<mixed> $picture
     */
    public function __construct(
        public array $picture,
    ) {}
}
