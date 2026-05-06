<?php

declare(strict_types=1);

namespace Piwigo\Page\Context;

/**
 * Page context for the search form page (SearchController).
 *
 * SearchController builds a saved-search record from POST params and
 * redirects to GalleryController for results display.  This context
 * covers the form itself: current query string, active filter values,
 * and any quick-search results rendered inline before the redirect.
 *
 * Consumed by #23 Wave 0 Latte partials:
 *   search_filters.inc.php → _partials/search-filters.latte
 */
final readonly class SearchPageContext
{
    /**
     * @param array<string,mixed> $filters    Active filter values keyed by filter name.
     * @param list<int>           $results    Image IDs from a quick-search preview (may be empty).
     * @param array<string,mixed> $pagination Navigation-bar data when previewing results inline.
     */
    public function __construct(
        public string $query,
        public array  $filters,
        public array  $results,
        public array  $pagination,
    ) {}
}
