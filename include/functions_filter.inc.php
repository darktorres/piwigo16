<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Updates data of categories with filtered values
 * @param array<int, array<string, mixed>> $cats
 */
function update_cats_with_filtered_data(array &$cats): void
{
    /** @var array<string, mixed> $filter */
    global $filter;

    if ($filter['enabled']) {
        $upd_fields = ['date_last', 'max_date_last', 'count_images', 'count_categories', 'nb_images'];

        // $filter['categories'] is populated either from
        // get_computed_categories() (returns array<int|string, array<string,
        // mixed>>) or from unserialize() of a previously stored copy of that
        // same data (see include/filter.inc.php) -- unserialize() is not
        // provably array-shaped to PHPStan, so narrow defensively at runtime.
        $filter_categories = $filter['categories'] ?? null;
        if (! is_array($filter_categories)) {
            return;
        }

        foreach ($cats as $cat_id => $category) {
            $ref_cat_id = $category['id'] ?? null;
            if (! is_int($ref_cat_id) && ! is_string($ref_cat_id)) {
                continue;
            }

            $filter_category = $filter_categories[$ref_cat_id] ?? null;
            if (! is_array($filter_category)) {
                continue;
            }

            foreach ($upd_fields as $upd_field) {
                $cats[$cat_id][$upd_field] = $filter_category[$upd_field] ?? null;
            }
        }
    }
}
