<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `picture_pictures_data` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/PictureController.php
 */
final readonly class PicturePicturesData
{
    /**
     * @param array<mixed> $picture
     */
    public function __construct(
        public array $picture,
    ) {
    }
}
