<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for the legacy `render_category_name` filter. No handler is
 * registered for it anywhere today -- a pure information carrier, not a
 * behavior change (nothing filters this value either way).
 */
final readonly class RenderCategoryName
{
    /**
     * @param string|array<string, mixed> $context Caller-identifying tag (e.g. 'admin_cat_list') OR the full category row.
     */
    public function __construct(
        public string $categoryName,
        public string|array $context,
    ) {}
}
