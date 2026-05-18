<?php

declare(strict_types=1);

namespace Piwigo\Common\Enum;

/**
 * Top-level gallery section identifier — the `section` slot in
 * `SectionContext` and the `?section=...` query parameter.
 *
 * Each case represents a distinct rendering mode for the index page;
 * `SectionInitializer` dispatches on this value to populate the page
 * with categories, tags, search results, etc.
 *
 * `ListView` is the `'list'` section (display an explicit list of image
 * IDs). The case name avoids the `list` PHP reserved word; the backing
 * value remains `'list'` so URL/storage round-trips are unchanged.
 */
enum Section: string
{
    case Categories  = 'categories';
    case Tags        = 'tags';
    case Search      = 'search';
    case Favorites   = 'favorites';
    case RecentPics  = 'recent_pics';
    case RecentCats  = 'recent_cats';
    case MostVisited = 'most_visited';
    case BestRated   = 'best_rated';
    case ListView    = 'list';
}
