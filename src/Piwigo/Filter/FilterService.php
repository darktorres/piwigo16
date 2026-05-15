<?php

declare(strict_types=1);

namespace Piwigo\Filter;

use Piwigo\Config\Config;
use Piwigo\Core\StringUtil;

final class FilterService
{
    /** @param array<array<string, mixed>> $cats */
    public function updateCategoriesWithFilteredData(array &$cats): void
    {
        $filter = FilterContextRegistry::current();

        if ($filter->enabled) {
            $upd_fields = ['date_last', 'max_date_last', 'count_images', 'count_categories', 'nb_images'];

            foreach ($cats as $cat_id => &$category) {
                $catIdRaw = $category['id'];
                $cat_id_val = is_numeric($catIdRaw) ? (int) $catIdRaw : (is_string($catIdRaw) ? $catIdRaw : '');
                $raw_cat_data = $filter->categories[$cat_id_val] ?? null;
                $cat_data = is_array($raw_cat_data) ? $raw_cat_data : [];
                foreach ($upd_fields as $upd_field) {
                    $category[$upd_field] = $cat_data[$upd_field] ?? null;
                }
            }
            unset($category);
        }
    }

    /**
     * Look up a per-page filter configuration value. The current page is
     * keyed by script basename; entries in `$conf['filter_pages']['default']`
     * apply as a fallback.
     */
    public function getFilterPageValue(string $valueName): mixed
    {
        $pageName = StringUtil::scriptBasename();
        /** @var array<string,array<string,mixed>> $filterPages */
        $filterPages = Config::filterPages();
        return $filterPages[$pageName][$valueName] ?? $filterPages['default'][$valueName] ?? null;
    }
}
