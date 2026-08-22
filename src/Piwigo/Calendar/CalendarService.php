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
    public function buildInnerSql(string $section, bool $hasCategoryContext, ?int $categoryId, string $forbiddenCategories, array $items): ?CalendarQueryScope
    {
        $sql = <<<SQL
             FROM images
            SQL;

        if ($section === 'categories') {
            $sql .= <<<SQL

                INNER JOIN image_category ON id = image_id
                SQL;

            if ($hasCategoryContext) {
                $subIds = $categoryId === null ? [] : array_diff($this->categoryService->getSubcatIds([$categoryId]), explode(',', $forbiddenCategories));
                if ($subIds === []) {
                    return null;
                }

                $subIdsInt = array_values(array_map(intval(...), $subIds));

                $criteria = $this->permissionService->getPermissionCriteria();
                // visible_images's own old fallthrough into forbidden_images
                // (fieldName 'id' -> the images-table's own level check) --
                // see PermissionCriteria's own docblock.
                $rawSqlWhere = SqlCondition::combine(
                    'AND',
                    SqlCondition::fromRawSql('category_id IN (:innerSubIds)', [
                        'innerSubIds' => $subIdsInt,
                    ], [
                        'innerSubIds' => ArrayParameterType::INTEGER,
                    ]),
                    $criteria->visibleImagesCondition('id'),
                    $criteria->maxLevelCondition('level'),
                );

                $dqlPermissionCondition = SqlCondition::combine(
                    'AND',
                    $criteria->visibleImagesCondition('i.id'),
                    $criteria->maxLevelCondition('i.level'),
                );
                $dqlWhere = SqlCondition::combine(
                    'AND',
                    SqlCondition::fromRawSql('ic.category IN (:innerSubIds)', [
                        'innerSubIds' => $subIdsInt,
                    ], [
                        'innerSubIds' => ArrayParameterType::INTEGER,
                    ]),
                    $dqlPermissionCondition,
                );
            } else {
                $criteria = $this->permissionService->getPermissionCriteria();
                // An empty criteria (no real restriction for this user)
                // renders as no WHERE at all rather than a `1 = 1` stand-in.
                $rawSqlWhere = SqlCondition::combine(
                    'AND',
                    $criteria->forbiddenCategoriesCondition('category_id'),
                    $criteria->visibleCategoriesCondition('category_id'),
                    $criteria->visibleImagesCondition('id'),
                    $criteria->maxLevelCondition('level'),
                );

                $dqlWhere = SqlCondition::combine(
                    'AND',
                    $criteria->forbiddenCategoriesCondition('ic.category'),
                    $criteria->visibleCategoriesCondition('ic.category'),
                    $criteria->visibleImagesCondition('i.id'),
                    $criteria->maxLevelCondition('i.level'),
                );
            }

            return new CalendarQueryScope($sql, true, $rawSqlWhere, $dqlWhere);
        }

        if ($items === []) {
            return null;
        }

        // $items is caller-supplied and only ever filtered through
        // is_scalar() upstream, so it can genuinely carry a bool/float
        // alongside real numeric ids -- is_numeric($v) ? (int) $v : 0 maps
        // a non-numeric value to 0 (a safe no-match id) rather than letting
        // an int cast coerce it into an accidental real match (e.g.
        // (int) true === 1). Both branches below now bind the identical
        // conversion as a real INTEGER parameter -- previously the raw-SQL
        // side bound plain strings and relied on MySQL's own implicit
        // coercion, while only the DQL side (i.id is a mapped `integer`
        // property, which type-checks the bound value) was honest about it.
        $innerItemIds = array_map(static fn (bool|float|int|string $v): int => is_numeric($v) ? (int) $v : 0, $items);

        return new CalendarQueryScope(
            $sql,
            false,
            SqlCondition::fromRawSql('id IN (:innerItems)', [
                'innerItems' => $innerItemIds,
            ], [
                'innerItems' => ArrayParameterType::INTEGER,
            ]),
            SqlCondition::fromRawSql('i.id IN (:innerItems)', [
                'innerItems' => $innerItemIds,
            ], [
                'innerItems' => ArrayParameterType::INTEGER,
            ]),
        );
    }
}
