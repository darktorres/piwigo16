<?php

declare(strict_types=1);

namespace Piwigo\Image\Event;

use Piwigo\Image\SrcImage;

/**
 * Typed event for the legacy `get_src_image_url` filter. Registered
 * (`HtmlService::getSrcImageUrlProtectionHandler()`, conditionally wired
 * from `RequestBootstrap.php`) -- mutable on `$url`. Lives under
 * `Piwigo\Image\Event\`, not `Piwigo\Event\Picture\`, since it carries a
 * real `Piwigo\Image\SrcImage` instance -- deptrac's L0Data layer may
 * depend on nothing.
 */
final class GetSrcImageUrl
{
    public function __construct(
        public string $url,
        public readonly SrcImage $value,
    ) {}
}
