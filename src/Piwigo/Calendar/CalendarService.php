<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Category\CategoryService;
use Piwigo\Db\Tables;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;

/**
 * The DB-query-building half of `CalendarRenderer::render()`
 * -- deciding the FROM/JOIN/WHERE
 * fragment for the requested category/section context. The rest of that
 * function (chronology style/view resolution, calendar object
 * construction, the view-switcher UI links) is entirely `$page`/
 * `$template`-coupled and stays in {@see CalendarRenderer::render()}
 * itself -- P23 batch 8c ported the free-function delegate this doc used
 * to describe (`initialize_calendar()`) into that method and deleted it.
 */
final readonly class CalendarService
{
    public function __construct(
        private PermissionService $permissionService,
        private CategoryService $categoryService,
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
    public function buildInnerSql(string $section, bool $hasCategoryContext, int|string|null $categoryId, string $forbiddenCategories, array $items): ?SqlCondition
    {
        $imagesTable = Tables::images();
        $sql = " FROM {$imagesTable}";

        if ($section === 'categories') {
            $imageCategoryTable = Tables::imageCategory();
            $sql .= "\nINNER JOIN {$imageCategoryTable} ON id = image_id";

            if ($hasCategoryContext) {
                $subIds = $categoryId === null ? [] : array_diff($this->categoryService->getSubcatIds([$categoryId]), explode(',', $forbiddenCategories));
                if ($subIds === []) {
                    return null;
                }

                $sql .= "\nWHERE category_id IN (:innerSubIds)";
                $params = [
                    'innerSubIds' => array_values(array_map(intval(...), $subIds)),
                ];
                $types = [
                    'innerSubIds' => ArrayParameterType::INTEGER,
                ];

                $permissionCondition = $this->permissionService->getSqlConditionFandFAsCondition([
                    'visible_images' => 'id',
                ]);
                if (! $permissionCondition->isEmpty()) {
                    $sql .= "\n    AND {$permissionCondition->sql}";
                    $params = [...$params, ...$permissionCondition->parameters];
                    $types = [...$types, ...$permissionCondition->types];
                }
            } else {
                $permissionCondition = $this->permissionService->getSqlConditionFandFAsCondition([
                    'forbidden_categories' => 'category_id',
                    'visible_categories' => 'category_id',
                    'visible_images' => 'id',
                ], true);
                $sql .= "\n    WHERE {$permissionCondition->sql}";
                $params = $permissionCondition->parameters;
                $types = $permissionCondition->types;
            }

            return new SqlCondition($sql, $params, $types);
        }

        if ($items === []) {
            return null;
        }

        $sql .= "\nWHERE id IN (:innerItems)";

        return new SqlCondition($sql, [
            'innerItems' => array_map(strval(...), $items),
        ], [
            'innerItems' => ArrayParameterType::STRING,
        ]);
    }
}
