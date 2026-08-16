<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

/**
 * Typed event for the legacy `render_category_literal_description` filter.
 * `$description` is nullable -- its one real dispatch site
 * (`Category\CategoryCatsRenderer.php`) wraps `RenderCategoryDescription`'s
 * own (also nullable) result. Co-located here from `Piwigo\Event\Template\RenderCategoryLiteralDescription` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class RenderCategoryLiteralDescription
{
    public function __construct(
        public ?string $description,
    ) {}
}
