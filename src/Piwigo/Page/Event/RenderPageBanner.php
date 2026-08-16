<?php

declare(strict_types=1);

namespace Piwigo\Page\Event;

/**
 * Typed event for the legacy `render_page_banner` filter. No handler is
 * registered for it anywhere today. No context -- every real call site
 * passes only the banner text. Co-located here from `Piwigo\Event\Template\RenderPageBanner` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class RenderPageBanner
{
    public function __construct(
        public string $banner,
    ) {}
}
