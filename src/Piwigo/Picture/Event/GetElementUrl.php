<?php

declare(strict_types=1);

namespace Piwigo\Picture\Event;

/**
 * Typed event for the legacy `get_element_url` filter. Registered
 * (`HtmlService::getElementUrlProtectionHandler()`, conditionally wired
 * from `RequestBootstrap.php`) -- mutable on `$url`. No production
 * dispatch site exists (the sibling `get_src_image_url` is the one
 * actually wired into a real request path) -- only a direct Integration
 * test call. Co-located here from `Piwigo\Event\Picture\GetElementUrl` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class GetElementUrl
{
    /**
     * @param array<string, mixed> $elementInfo
     */
    public function __construct(
        public string $url,
        public readonly array $elementInfo,
    ) {}
}
