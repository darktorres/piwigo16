<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for the legacy `render_category_literal_description` filter.
 * `$description` is nullable -- its one real dispatch site
 * (`Category\CategoryCatsRenderer.php`) wraps `RenderCategoryDescription`'s
 * own (also nullable) result.
 */
final class RenderCategoryLiteralDescription
{
    public function __construct(
        public ?string $description,
    ) {}
}
