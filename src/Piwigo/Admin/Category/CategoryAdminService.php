<?php

declare(strict_types=1);

namespace Piwigo\Admin\Category;

use Piwigo\Db\Tables;

/**
 * Admin-side category WRITE operations -- deliberately separate from
 * Piwigo\Category\CategoryService (P19), which is read-only (gallery
 * browsing). Matches the doc's own reference file inventory:
 * "Admin/Category/: CategoryAdminService, CreateCategoryResult".
 *
 * createVirtualCategory() wraps the existing create_virtual_category()
 * free function (admin/include/functions.php) rather than reimplementing
 * it -- that function is also called by the WS API
 * (include/ws_functions/pwg.categories.php), out of this phase's scope,
 * so it stays a shared free function; this service only adds a typed
 * return shape for the admin call sites migrated this phase.
 *
 * setCategoryOption()/setCategoryPermissions()/saveImageOrder() are real
 * new consolidations: getCategoriesRefDate() existed as two
 * byte-for-byte-identical copies (admin/cat_list.php and admin/albums.php,
 * confirmed via direct diff) and the other two replace inline
 * switch/if-chains that were never shared anywhere, moved here so
 * admin/cat_options.php, admin/cat_perm.php, and admin/element_set_ranks.php
 * (all three still legacy `include` page glue, called from
 * AlbumSubController/CatOptionsSubController) can call one typed method
 * each instead of repeating raw SQL/branching inline.
 */
final class CategoryAdminService
{
    /**
     * @param array{commentable?: bool, visible?: bool, status?: string, comment?: string, inherit?: bool} $options
     */
    public function createVirtualCategory(string $name, ?int $parentId = null, array $options = []): CreateCategoryResult
    {
        /** @var array{error?: string, info?: string, id?: int|string} $result */
        $result = create_virtual_category($name, $parentId, $options);

        if (isset($result['error'])) {
            return CreateCategoryResult::failure($result['error']);
        }

        return CreateCategoryResult::success(
            $result['info'] ?? '',
            isset($result['id']) ? (int) $result['id'] : 0
        );
    }

    /**
     * Deduplicated from two byte-for-byte-identical copies in
     * admin/cat_list.php and admin/albums.php.
     *
     * @param list<int|string> $ids
     * @return array<int|string, mixed>
     */
    public function getCategoriesRefDate(array $ids, string $field = 'date_available', string $minmax = 'max'): array
    {
        // we need to work on the whole tree under each category, even if we
        // don't want to sort sub categories
        $category_ids = get_subcat_ids($ids);

        $query = '
SELECT
    category_id,
    ' . $minmax . '(' . $field . ') as ref_date
  FROM ' . Tables::imageCategory() . '
    JOIN ' . Tables::images() . ' ON image_id = id
  WHERE category_id IN (' . implode(',', $category_ids) . ')
  GROUP BY category_id
;';
        $ref_dates = query2array($query, 'category_id', 'ref_date');

        $query = '
SELECT
    id,
    uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
        $uppercats_of = query2array($query, 'id', 'uppercats');

        foreach (array_keys($uppercats_of) as $cat_id) {
            $subcat_ids = [];

            foreach ($uppercats_of as $id => $uppercats) {
                if (! is_string($uppercats)) {
                    continue;
                }
                if ((bool) preg_match('/(^|,)' . $cat_id . '(,|$)/', $uppercats)) {
                    $subcat_ids[] = $id;
                }
            }

            $to_compare = [];
            foreach ($subcat_ids as $id) {
                if (isset($ref_dates[$id])) {
                    $to_compare[] = $ref_dates[$id];
                }
            }

            if (count($to_compare) > 0) {
                $ref_dates[$cat_id] = $minmax === 'max' ? max($to_compare) : min($to_compare);
            } else {
                $ref_dates[$cat_id] = null;
            }
        }

        $return = [];
        foreach ($ids as $id) {
            $return[$id] = $ref_dates[$id] ?? null;
        }

        return $return;
    }

    /**
     * Consolidates admin/cat_options.php's 8 switch-case branches
     * (comments/visible/status/representative x true/false) into one
     * parameterized method.
     *
     * @param list<int> $catIds
     */
    public function setCategoryOption(array $catIds, string $section, bool $value): void
    {
        if ($catIds === []) {
            return;
        }

        $idList = implode(',', $catIds);

        match ($section) {
            'comments' => pwg_query('
UPDATE ' . Tables::categories() . '
  SET commentable = \'' . ($value ? 'true' : 'false') . '\'
  WHERE id IN (' . $idList . ')
;'),
            'visible' => set_cat_visible($catIds, $value ? 'true' : 'false'),
            'status' => set_cat_status($catIds, $value ? 'public' : 'private'),
            'representative' => $value
                // theoretically, all categories in $catIds contain at least
                // one element when $value is true, so Piwigo can find a
                // representant (matches the original's own comment).
                ? set_random_representant($catIds)
                : pwg_query('
UPDATE ' . Tables::categories() . '
  SET representative_picture_id = NULL
  WHERE id IN (' . $idList . ')
;'),
            default => null,
        };

        pwg_activity('album', $catIds, 'edit', [
            'section' => $section,
            'action' => $value ? 'trueify' : 'falsify',
        ]);
    }

    /**
     * Consolidates admin/cat_perm.php's group/user permission-management
     * block (status change + group/user grant/deny).
     *
     * @param list<int> $groupIds
     * @param list<int> $userIds
     */
    public function setCategoryPermissions(int $catId, string $currentStatus, string $newStatus, bool $applyOnSub, array $groupIds, array $userIds): void
    {
        if ($currentStatus !== $newStatus || ($currentStatus !== 'public' && $applyOnSub)) {
            $catIdsForStatus = [$catId];
            if ($applyOnSub) {
                $catIdsForStatus = array_merge($catIdsForStatus, get_subcat_ids([$catId]));
            }
            set_cat_status($catIdsForStatus, $newStatus);
        }

        if ($newStatus !== 'private') {
            return;
        }

        // groups
        $groupsGranted = $this->numericColumn('
SELECT group_id
  FROM ' . Tables::groupAccess() . '
  WHERE cat_id = ' . $catId . '
;', 'group_id');

        $denyGroups = array_diff($groupsGranted, $groupIds);
        if (count($denyGroups) > 0) {
            // if you forbid access to an album, all sub-albums become
            // automatically forbidden
            pwg_query('
DELETE
  FROM ' . Tables::groupAccess() . '
  WHERE group_id IN (' . implode(',', $denyGroups) . ')
    AND cat_id IN (' . implode(',', get_subcat_ids([$catId])) . ')
;');
        }

        if (count($groupIds) > 0) {
            $catIdsForGrant = get_uppercat_ids([$catId]);
            if ($applyOnSub) {
                $catIdsForGrant = array_merge($catIdsForGrant, get_subcat_ids([$catId]));
            }

            $privateCats = query2array('
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $catIdsForGrant) . ')
    AND status = \'private\'
;', null, 'id');

            $inserts = [];
            foreach ($privateCats as $privateCatId) {
                foreach ($groupIds as $groupId) {
                    $inserts[] = [
                        'group_id' => $groupId,
                        'cat_id' => $privateCatId,
                    ];
                }
            }

            mass_inserts(
                Tables::groupAccess(),
                ['group_id', 'cat_id'],
                $inserts,
                [
                    'ignore' => true,
                ]
            );
        }

        // users
        $usersGranted = $this->numericColumn('
SELECT user_id
  FROM ' . Tables::userAccess() . '
  WHERE cat_id = ' . $catId . '
;', 'user_id');

        $denyUsers = array_diff($usersGranted, $userIds);
        if (count($denyUsers) > 0) {
            // if you forbid access to an album, all sub-album become
            // automatically forbidden
            pwg_query('
DELETE
  FROM ' . Tables::userAccess() . '
  WHERE user_id IN (' . implode(',', $denyUsers) . ')
    AND cat_id IN (' . implode(',', get_subcat_ids([$catId])) . ')
;');
        }

        if (count($userIds) > 0) {
            add_permission_on_category($catId, $userIds);
        }
    }

    /**
     * Consolidates admin/element_set_ranks.php's category image_order
     * UPDATE (own row + optionally every sub-album).
     */
    public function saveImageOrder(int $catId, ?string $imageOrder, bool $applySubcats): void
    {
        $orderValue = $imageOrder !== null ? '\'' . $imageOrder . '\'' : 'NULL';

        pwg_query('
UPDATE ' . Tables::categories() . '
  SET image_order = ' . $orderValue . '
  WHERE id=' . $catId);

        if (! $applySubcats) {
            return;
        }

        $catInfo = get_cat_info($catId);
        if (! is_array($catInfo) || ! is_string($catInfo['uppercats'] ?? null)) {
            page_not_found('Requested album does not exist');
        }

        pwg_query('
UPDATE ' . Tables::categories() . '
  SET image_order = ' . $orderValue . '
  WHERE uppercats LIKE \'' . $catInfo['uppercats'] . ',%\'');
    }

    /**
     * @return list<int>
     */
    private function numericColumn(string $query, string $column): array
    {
        $ids = [];
        foreach (query2array($query, null, $column) as $rawId) {
            if (is_numeric($rawId)) {
                $ids[] = (int) $rawId;
            }
        }
        return $ids;
    }
}
