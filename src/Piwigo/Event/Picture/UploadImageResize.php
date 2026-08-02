<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `upload_image_resize` filter. No reference
 * class exists for this one (the reference confirms it dropped along with
 * the whole legacy asset-resize feature this event belonged to) -- falls
 * back to `Piwigo\Event\Picture\`, matching sibling `UploadFile`. The
 * registered callable ('pwg_image_resize') doesn't exist as a function
 * anywhere in this codebase and this event is never actually dispatched
 * -- confirmed pre-existing dead-but-harmless registration, unchanged by
 * this typed conversion. Shape mirrors `UploadFile`'s own, the closest
 * real sibling, since nothing ever constructs or reads a real instance to
 * confirm the shape against.
 */
final class UploadImageResize
{
    public function __construct(
        public ?string $representativeExt,
        public readonly string $filePath,
    ) {}
}
