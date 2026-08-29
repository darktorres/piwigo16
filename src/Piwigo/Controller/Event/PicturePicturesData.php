<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

use Piwigo\Controller\Projection\PictureElement;

/**
 * Typed event for the legacy `picture_pictures_data` filter. No handler
 * is registered for it anywhere today. No context -- every real call
 * site passes only the picture data.
 *
 * Keyed by navigation slot: `first`, `previous`, `current`, `next`,
 * `last`, each present only when that slot exists for the photo being
 * viewed. `current` always is.
 */
final class PicturePicturesData
{
    /**
     * @param array<string, PictureElement> $picture
     */
    public function __construct(
        public array $picture,
    ) {}
}
