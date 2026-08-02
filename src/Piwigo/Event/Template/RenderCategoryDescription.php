<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for the legacy `render_category_description` filter.
 * `$categoryDescription` is nullable -- `Category\CategoryCatsRenderer.php`'s
 * own dispatch site really does pass `$category['comment'] ?? null`.
 */
final class RenderCategoryDescription
{
    public function __construct(
        public ?string $categoryDescription,
        public readonly string $context,
    ) {}
}
