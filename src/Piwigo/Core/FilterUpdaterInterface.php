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
     * @param array<int, array<string, mixed>> $cats
     */
    public function updateCatsWithFilteredData(array &$cats): void;
}
