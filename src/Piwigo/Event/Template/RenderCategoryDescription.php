<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for legacy `render_category_description` (dispatch).
 *
 * Dispatched from: src/Piwigo/Category/CategoryCatsRenderer.php, src/Piwigo/Section/SectionInitializer.php, src/Piwigo/Ws/Method/CategoriesEndpoints.php
 */
final readonly class RenderCategoryDescription
{
    public function __construct(
        public string $categoryDescription,
        public string $action,
    ) {
    }

    public function withCategoryDescription(string $categoryDescription): self
    {
        return new self($categoryDescription, $this->action);
    }
}
