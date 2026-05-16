<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for legacy `render_category_literal_description` (dispatch).
 *
 * Dispatched from: src/Piwigo/Category/CategoryCatsRenderer.php
 */
final class RenderCategoryLiteralDescription
{
    public function __construct(
        public string $description,
    ) {
    }
}
