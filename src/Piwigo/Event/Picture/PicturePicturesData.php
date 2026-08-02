<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `picture_pictures_data` filter. No handler
 * is registered for it anywhere today.
 */
final readonly class PicturePicturesData
{
    /**
     * @param array<mixed> $picture
     */
    public function __construct(
        public array $picture,
    ) {}
}
