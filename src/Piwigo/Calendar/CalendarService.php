<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Piwigo\Db\Tables;
use Piwigo\Permission\PermissionService;

/**
 * The DB-query-building half of `initialize_calendar()`
 * (`include/functions_calendar.inc.php`) -- deciding the FROM/JOIN/WHERE
 * fragment for the requested category/section context. The rest of that
 * function (chronology style/view resolution, calendar object
 * construction, the view-switcher UI links) is entirely `$page`/
 * `$template`-coupled and stays in the free-function delegate, same split
 * as every other P19 domain.
 */
final class CalendarService
{
    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}

    /**
     * Null return means "nothing to do" -- mirrors initialize_calendar()'s
     * own early `return;` for an empty sub-category set or an empty
     * non-category item list.
     *
     * $hasCategoryContext ($page['category'] isset in the original) and
     * $categoryId (its 'id' field, once validated) are deliberately kept
     * as two separate signals, not collapsed into "$categoryId !== null"
     * -- a $page['category'] present but malformed (no valid int|string
     * 'id') must still take the "specific category" branch (and its own
     * empty-sub_ids early return), not silently fall through to the
     * "browse everything visible" branch.
     *
     * @param  list<bool|float|int|string>  $items  used only when $section !== 'categories'
     */
    public function buildInnerSql(string $section, bool $hasCategoryContext, int|string|null $categoryId, string $forbiddenCategories, array $items): ?string
    {
        $sql = ' FROM ' . Tables::images();

        if ($section === 'categories') {
            $sql .= "\nINNER JOIN " . Tables::imageCategory() . ' ON id = image_id';

            if ($hasCategoryContext) {
                $subIds = $categoryId === null ? [] : array_diff(get_subcat_ids([$categoryId]), explode(',', $forbiddenCategories));
                if ($subIds === []) {
                    return null;
                }

                $sql .= "\nWHERE category_id IN (" . implode(',', $subIds) . ')';
                $sql .= "\n    " . $this->permissionService->getSqlConditionFandF([
                    'visible_images' => 'id',
                ], 'AND', false);
            } else {
                $sql .= "\n    " . $this->permissionService->getSqlConditionFandF([
                    'forbidden_categories' => 'category_id',
                    'visible_categories' => 'category_id',
                    'visible_images' => 'id',
                ], 'WHERE', true);
            }

            return $sql;
        }

        if ($items === []) {
            return null;
        }

        $sql .= "\nWHERE id IN (" . implode(',', $items) . ')';

        return $sql;
    }
}
