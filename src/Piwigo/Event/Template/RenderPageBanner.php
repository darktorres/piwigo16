<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for the legacy `render_page_banner` filter. No handler is
 * registered for it anywhere today. No context -- every real call site
 * passes only the banner text.
 */
final class RenderPageBanner
{
    public function __construct(
        public string $banner,
    ) {}
}
