<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `ws_images_uploadCompleted` (notify).
 *
 * New in 12
 *
 * Dispatched from: src/Piwigo/Ws/Method/ImagesEndpoints.php
 */
final readonly class WsImagesUploadCompleted
{
    /**
     * @param array<mixed> $uploadData
     */
    public function __construct(
        public array $uploadData,
    ) {
    }
}
