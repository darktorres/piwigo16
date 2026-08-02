<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `upload_thumbnail_resize` filter. See
 * `UploadImageResize`'s own docblock -- same dead-but-harmless
 * registration, same fallback reasoning.
 */
final class UploadThumbnailResize
{
    public function __construct(
        public ?string $representativeExt,
        public readonly string $filePath,
    ) {}
}
