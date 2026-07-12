<?php

declare(strict_types=1);

namespace Piwigo\Filter;

/**
 * Applies the current request's recent-content filter (see
 * include/filter.inc.php: $filter['enabled']/$filter['categories'], built
 * from the "start-recent-N" filter or restored from session) onto a list
 * of category rows freshly loaded from the DB, overwriting their
 * aggregate columns with the filtered equivalents.
 */
final class FilterService
{
    /**
     * Updates data of categories with filtered values.
     *
     * @param array<int, array<string, mixed>> $cats
     */
    public function updateCatsWithFilteredData(array &$cats): void
    {
        /** @var array<string, mixed> $filter */
        global $filter;

        if (! (bool) ($filter['enabled'] ?? false)) {
            return;
        }

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
