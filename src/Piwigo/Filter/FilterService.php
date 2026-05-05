<?php

declare(strict_types=1);

namespace Piwigo\Filter;

final class FilterService
{
    /** @param array<array<string, mixed>> $cats */
    public function updateCategoriesWithFilteredData(array &$cats): void
    {
        $filter = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];

        if (!empty($filter['enabled'])) {
            $upd_fields = ['date_last', 'max_date_last', 'count_images', 'count_categories', 'nb_images'];

            $filter_categories = is_array($filter['categories']) ? $filter['categories'] : [];
            foreach ($cats as $cat_id => &$category) {
                $cat_id_val = is_numeric($category['id']) ? (int) $category['id'] : (is_scalar($category['id']) ? (string) $category['id'] : '');
                $raw_cat_data = $filter_categories[$cat_id_val] ?? null;
                $cat_data = is_array($raw_cat_data) ? $raw_cat_data : [];
                foreach ($upd_fields as $upd_field) {
                    $category[$upd_field] = $cat_data[$upd_field] ?? null;
                }
            }
            unset($category);
        }
    }
}
