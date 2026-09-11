<?php

declare(strict_types=1);

namespace Piwigo\Menu;

/**
 * Stable, non-translated discriminator for each of the menubar's
 * "Specials" block entries (`MenubarRenderer::render()`'s own 7 real
 * construction sites). Before this existed, `MenubarSpecialRow` had no way
 * to tell "Most Visited" apart from "Best Rated" other than string-matching
 * `$title`/`$name`, which are real, translated user-facing strings -- not a
 * safe thing for a theme (P29.6, `modus`) to match against to single out
 * one specific special link for its own "Most Visited"/"Best Rated"
 * hoisting UX.
 */
enum MenubarSpecialKind: string
{
    case Favorites = 'favorites';
    case MostVisited = 'most_visited';
    case BestRated = 'best_rated';
    case RecentPics = 'recent_pics';
    case RecentCats = 'recent_cats';
    case Random = 'random';
    case Calendar = 'calendar';
}
