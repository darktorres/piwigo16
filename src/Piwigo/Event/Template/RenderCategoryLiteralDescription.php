<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for legacy `render_category_literal_description` (dispatch).
 *
 * Dispatched from: src/Piwigo/Category/CategoryCatsRenderer.php
 *
 * Not present in tools/triggers_list.php — caught during B5 multi-line
 * dispatch audit. B3's earlier sweep incorrectly tagged this as "dead"
 * because the dispatch site spans multiple lines and the regex used
 * matched only single-line patterns.
 *
 * An internal listener is registered for this event in
 * CommonBootstrap.php (the function-name string callback
 * `'render_category_literal_description'`), so this event stays on the
 * legacy dispatcher until B6 migrates the listener to a typed
 * subscriber.
 */
final readonly class RenderCategoryLiteralDescription
{
    public function __construct(
        public string $description,
    ) {
    }

    public function withDescription(string $description): self
    {
        return new self($description);
    }
}
