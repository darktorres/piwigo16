<?php

declare(strict_types=1);

namespace Piwigo\Page\Context;

/**
 * Page context for gallery / album browse pages (GalleryController).
 *
 * Covers all section variants: a specific category, favorites, recent,
 * best-rated, most-visited, recent-albums, tags, search, and list.
 * When `$category` is null the page is a virtual section (not an album).
 *
 * Consumed by #23 Wave 0 Latte partials:
 *   category_cats.inc.php → _partials/category-cats.latte
 *   category_default.inc.php → _partials/category-default.latte
 *   search_filters.inc.php → _partials/search-filters.latte
 */
final readonly class AlbumPageContext
{
    /**
     * @param array<string,mixed>|null  $category   Current album row, or null for virtual sections.
     * @param list<array<string,mixed>> $subAlbums  Direct sub-album rows (empty when not applicable).
     * @param list<int>                 $photos     Image IDs in current page slice.
     * @param array<string,mixed>       $pagination Navigation-bar data from PaginationService::createNavigationBar().
     */
    public function __construct(
        public array|null $category,
        public array      $subAlbums,
        public array      $photos,
        public array      $pagination,
        public string     $baseUrl,
        public string     $section,
    ) {
    }
}
