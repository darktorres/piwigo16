<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload\Event;

/**
 * Typed event for the legacy `loc_end_add_uploaded_file` notification.
 * No handler is registered for it anywhere today. `$imageInfos` is a
 * plain array, not the reference's typed `Piwigo\Image\Entity\Image` --
 * that type doesn't exist on this branch yet; the real dispatch site
 * (`UploadService::addUploadedFile()`) passes
 * `ImageService::getImageRow()`'s own real (here non-null, already
 * guarded) `array<string, mixed>` return shape instead.
 */
final readonly class UploadedFileAdded
{
    /**
     * @param array<string, mixed> $imageInfos
     */
    public function __construct(
        public array $imageInfos,
    ) {}
}
