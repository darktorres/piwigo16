<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for legacy `render_page_banner` (dispatch).
 *
 * Dispatched from: src/Piwigo/Page/PageHeaderRenderer.php
 *
 * Not present in tools/triggers_list.php — caught during B5 multi-line
 * dispatch audit. B3's earlier sweep incorrectly tagged this as "dead"
 * because the dispatch site spans multiple lines and the regex used
 * matched only single-line patterns.
 */
final readonly class RenderPageBanner
{
    public function __construct(
        public string $banner,
    ) {
    }

    public function withBanner(string $banner): self
    {
        return new self($banner);
    }
}
