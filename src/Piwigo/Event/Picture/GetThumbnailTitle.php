<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `get_thumbnail_title` filter. No handler is
 * registered for it anywhere today -- a pure information carrier.
 * Mutable on `$title`; `$info` stays context.
 */
final class GetThumbnailTitle
{
    /**
     * @param array<mixed> $info
     */
    public function __construct(
        public string $title,
        public readonly array $info,
    ) {}
}
