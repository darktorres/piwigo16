<?php

declare(strict_types=1);

namespace Piwigo\Image\Event;

use Piwigo\Image\SrcImage;

/**
 * Typed event for the legacy `get_high_url` filter -- distinct from
 * `GetDerivativeUrl` (which fires for every resized derivative): this
 * one covers only the original/full-resolution download link
 * (`ImageUrlBuilder::stdGetUrls()`'s `download_url`), matching the
 * legacy hook's own narrower scope (a plugin swapping in an
 * access-controlled download URL for the original file, leaving
 * thumbnails/other derivative sizes untouched). Mutable on `$url`;
 * `$srcImage` stays context.
 */
final class GetHighUrl
{
    public function __construct(
        public ?string $url,
        public readonly SrcImage $srcImage,
    ) {}
}
