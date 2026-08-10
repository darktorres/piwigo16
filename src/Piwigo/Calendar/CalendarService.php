<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Category\CategoryService;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;

/**
 * The DB-query-building half of `CalendarRenderer::render()`
 * -- deciding the FROM/JOIN/WHERE
 * fragment for the requested category/section context. The rest of that
 * function (chronology style/view resolution, calendar object
 * construction, the view-switcher UI links) is entirely `$page`/
 * `$template`-coupled and stays in {@see CalendarRenderer::render()}
 * itself.
 */
final readonly class CalendarService
{
    public function __construct(
        private PermissionService $permissionService,
        private CategoryService $categoryService,
    ) {}

    /**
     * Null return means "nothing to do", for an empty sub-category set or
     * an empty non-category item list.
     *
     * $hasCategoryContext and $categoryId (its 'id' field, once validated)
     * are deliberately kept as two separate signals, not collapsed into
     * "$categoryId !== null" -- a category context that's present but
     * malformed (no valid int|string 'id') must still take the "specific
     * category" branch (and its own empty-sub_ids early return), not
     * silently fall through to the "browse everything visible" branch.
     *
     * Returns a {@see CalendarQueryScope} rather than a single opaque
     * `?SqlCondition`: {@see \Piwigo\Calendar\CalendarRepository::findImageIds()}
     * alone stays on raw DBAL (see that method's own docblock), while
     * every other `CalendarRepository` method is real DQL. Both
     * representations are built from the same subcategory-id resolution
     * and `PermissionCriteria` calls, computed once; only the final
     * field-path strings differ per target syntax (raw SQL column names
     * vs. DQL property paths).
     *
     * @param  list<bool|float|int|string>  $items  used only when $section !== 'categories'
     */
    public function buildInnerSql(string $section, bool $hasCategoryContext, int|string|null $categoryId, string $forbiddenCategories, array $items): ?CalendarQueryScope
    {
        $imagesTable = 'images';
        $sql = " FROM {$imagesTable}";

        if ($section === 'categories') {
            $imageCategoryTable = 'image_category';
            $sql .= "\nINNER JOIN {$imageCategoryTable} ON id = image_id";

            if ($hasCategoryContext) {
                $subIds = $categoryId === null ? [] : array_diff($this->categoryService->getSubcatIds([$categoryId]), explode(',', $forbiddenCategories));
                if ($subIds === []) {
                    return null;
                }

                $subIdsInt = array_values(array_map(intval(...), $subIds));

                $sql .= "\nWHERE category_id IN (:innerSubIds)";
                $params = [
                    'innerSubIds' => $subIdsInt,
                ];
                $types = [
                    'innerSubIds' => ArrayParameterType::INTEGER,
                ];

                $criteria = $this->permissionService->getPermissionCriteria();
                // visible_images's own old fallthrough into forbidden_images
                // (fieldName 'id' -> the images-table's own level check) --
                // see PermissionCriteria's own docblock.
                $permissionCondition = SqlCondition::combine(
                    'AND',
                    $criteria->visibleImagesCondition('id'),
                    $criteria->maxLevelCondition('level'),
                );
                if (! $permissionCondition->isEmpty()) {
                    $sql .= "\n    AND {$permissionCondition->sql}";
                    $params = [...$params, ...$permissionCondition->parameters];
                    $types = [...$types, ...$permissionCondition->types];
                }

                $dqlPermissionCondition = SqlCondition::combine(
                    'AND',
                    $criteria->visibleImagesCondition('i.id'),
                    $criteria->maxLevelCondition('i.level'),
                );
                $dqlWhere = SqlCondition::combine(
                    'AND',
                    new SqlCondition('ic.categoryId IN (:innerSubIds)', [
                        'innerSubIds' => $subIdsInt,
                    ], [
                        'innerSubIds' => ArrayParameterType::INTEGER,
                    ]),
                    $dqlPermissionCondition,
                );
            } else {
                $criteria = $this->permissionService->getPermissionCriteria();
                $permissionCondition = SqlCondition::combine(
                    'AND',
                    $criteria->forbiddenCategoriesCondition('category_id'),
                    $criteria->visibleCategoriesCondition('category_id'),
                    $criteria->visibleImagesCondition('id'),
                    $criteria->maxLevelCondition('level'),
                );
                // The old $forceOneCondition: true guaranteed a non-empty
                // fragment here -- this WHERE clause is spliced unconditionally
                // below (no isEmpty() guard around it), so an empty criteria
                // (no real restriction for this user) would otherwise produce
                // a bare "WHERE" with nothing after it, a real SQL syntax error.
                $whereSql = $permissionCondition->isEmpty() ? '1 = 1' : $permissionCondition->sql;
                $sql .= "\n    WHERE {$whereSql}";
                $params = $permissionCondition->parameters;
                $types = $permissionCondition->types;

                // No '1 = 1' fallback needed on the DQL side: CalendarRepository's
                // own applyCondition() helper already skips an empty SqlCondition
                // (no andWhere() call at all) rather than requiring non-empty text.
                $dqlWhere = SqlCondition::combine(
                    'AND',
                    $criteria->forbiddenCategoriesCondition('ic.categoryId'),
                    $criteria->visibleCategoriesCondition('ic.categoryId'),
                    $criteria->visibleImagesCondition('i.id'),
                    $criteria->maxLevelCondition('i.level'),
                );
            }

            return new CalendarQueryScope(new SqlCondition($sql, $params, $types), true, $dqlWhere);
        }

        if ($items === []) {
            return null;
        }

        $sql .= "\nWHERE id IN (:innerItems)";

        return new CalendarQueryScope(
            new SqlCondition($sql, [
                'innerItems' => array_map(strval(...), $items),
            ], [
                'innerItems' => ArrayParameterType::STRING,
            ]),
            false,
            // i.id is a mapped `integer` property (unlike the raw-SQL side
            // above, which compares against the column as a string and
            // relies on MySQL's own implicit coercion) -- bound as real ints
            // here so DQL's own type checking against the mapped column
            // type stays honest.
            new SqlCondition('i.id IN (:innerItems)', [
                'innerItems' => array_map(static fn (bool|float|int|string $v): int => is_numeric($v) ? (int) $v : 0, $items),
            ], [
                'innerItems' => ArrayParameterType::INTEGER,
            ]),
        );
    }
}
