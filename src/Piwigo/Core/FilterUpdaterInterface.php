<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8f-1: `Piwigo\Filter\FilterService` is L2bExtendedDomain, but
 * its own free-function delegate `update_cats_with_filtered_data()`
 * (formerly include/functions_filter.inc.php) has real L2aCoreDomain
 * callers (`Category\CategoryService`) that deptrac's ruleset forbids from
 * depending upward on L2b directly. Lives in `Piwigo\Core` (L1Infrastructure,
 * same direction as `ActivityLoggerInterface`) so those classes can depend
 * downward on this instead of the concrete class. `FilterService`
 * implements it; bound in `config/container.php`.
 */
interface FilterUpdaterInterface
{
    /**
     * $cats' rows are CategoryTreeCache::getForUser()'s own row shape
     * (FilterState::categories() itself stays deliberately loose -- see
     * its own docblock) further extended per call site with
     * template-display fields (e.g.
     * CategoryService::getCategoriesMenu()'s NAME/TITLE/URL/LEVEL/
     * SELECTED/IS_UPPERCAT/icon_ts, itself mixed via EventDispatcher) --
     * only date_last/max_date_last/count_images/count_categories/
     * nb_images are ever read or written here, but the container can't be
     * narrowed to one single shape across every real per-site extension.
     *
     * @param array<int, array<string, mixed>> $cats
     */
    public function updateCatsWithFilteredData(array &$cats): void;
}
