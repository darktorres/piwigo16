<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `get_element_metadata_available` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/PictureController.php
 */
final readonly class GetElementMetadataAvailable
{
    /**
     * @param array<mixed> $currentPicture
     */
    public function __construct(
        public bool $available,
        public array $currentPicture,
    ) {
    }
}
