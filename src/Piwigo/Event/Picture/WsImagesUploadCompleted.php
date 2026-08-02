<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `ws_images_uploadCompleted` notification. No
 * handler is registered for it anywhere today.
 */
final readonly class WsImagesUploadCompleted
{
    /**
     * @param array<mixed> $uploadData
     */
    public function __construct(
        public array $uploadData,
    ) {}
}
