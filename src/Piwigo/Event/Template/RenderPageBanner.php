<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for legacy `render_page_banner` (dispatch).
 *
 * Dispatched from: src/Piwigo/Page/PageHeaderRenderer.php
 */
final readonly class RenderPageBanner
{
    public function __construct(
        public string $banner,
    ) {
    }
}
