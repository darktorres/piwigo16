<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for legacy `render_category_name` (dispatch).
 *
 * Dispatched from: src/Piwigo/Category/CategoryService.php, src/Piwigo/Controller/Admin/AlbumController.php, src/Piwigo/Ws/Method/CategoriesEndpoints.php
 */
final readonly class RenderCategoryName
{
    /**
     * @param string|array<string, mixed> $location Context label (e.g. 'admin_cat_list') OR the full category row.
     */
    public function __construct(
        public string $categoryName,
        public string|array $location,
    ) {
    }

    public function withCategoryName(string $categoryName): self
    {
        return new self($categoryName, $this->location);
    }
}
