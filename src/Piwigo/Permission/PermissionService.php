<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Piwigo\Group\GroupRepository;

/**
 * Forbidden-categories computation. Constructor-injects both
 * PermissionRepository and GroupRepository, plain constructor injection
 * (same shape as PermalinkService) -- the group-based access check
 * (calculate_permissions()'s user_group JOIN group_access query) is why
 * Group had to land in this same layer (L2aCoreDomain), see deptrac.yaml's
 * own comment on that namespace.
 */
final class PermissionService
{
    public function __construct(
        private readonly PermissionRepository $repo,
        private readonly GroupRepository $groupRepo,
    ) {}

    /**
     * Calculates the list of forbidden categories for a given user.
     *
     * Calculation is based on private categories minus categories authorized
     * to the groups the user belongs to minus the categories directly
     * authorized to the user. The list contains at least 0 to be compliant
     * with queries such as "WHERE category_id NOT IN ($forbidden_categories)"
     *
     * @return string comma separated ids
     */
    public function getForbiddenCategories(int $userId, string $userStatus): string
    {
        $privateIds = $this->repo->findPrivateCategoryIds();

        $authorizedIds = array_merge(
            $this->repo->findDirectlyAuthorizedCategoryIds($userId),
            $this->groupRepo->getAccessibleCategoryIdsForUser($userId)
        );

        // uniquify ids : some private categories might be authorized for the
        // groups and for the user
        $authorizedIds = array_unique($authorizedIds);

        // only unauthorized private categories are forbidden
        $forbiddenIds = array_diff($privateIds, $authorizedIds);

        // if user is not an admin, locked categories are forbidden
        if (! is_admin($userStatus)) {
            $forbiddenIds = array_unique(array_merge($forbiddenIds, $this->repo->findLockedCategoryIds()));
        }

        if ($forbiddenIds === []) {
            // at least, the list contains 0 value. This category does not
            // exist, so where clauses such as "WHERE category_id NOT IN(0)"
            // will always be true.
            $forbiddenIds[] = 0;
        }

        return implode(',', $forbiddenIds);
    }

    /**
     * Returns a SQL condition string filtering by forbidden/visible
     * categories and images, from the request-scoped $user/$filter globals
     * -- same "reads session/request globals directly" shape as
     * AuditService's $_SERVER['REMOTE_ADDR'] read; a pure string builder
     * with no DB access of its own.
     *
     * @param array<string, string> $conditionFields condition name
     *   (forbidden_categories|visible_categories|visible_images|
     *   forbidden_images) => SQL field/table.column to filter on
     * @param string|null $prefixCondition e.g. "\n  AND" -- only prepended
     *   when the built condition is non-empty
     */
    public function getSqlConditionFandF(
        array $conditionFields,
        ?string $prefixCondition = null,
        bool $forceOneCondition = false,
    ): string {
        /**
         * @var array<string, mixed> $user
         * @var array<string, mixed> $filter
         */
        global $user, $filter;

        // forbidden_categories/image_access_list are comma-separated id
        // lists built with implode(',', ...) in getuserdata(), level is a
        // raw DB fetch value (string|null); image_access_type is the
        // literal string 'NOT IN' (see getuserdata()) -- all always
        // scalar/string-castable.
        $userForbiddenCategories = $user['forbidden_categories'] ?? null;
        $userForbiddenCategories = is_scalar($userForbiddenCategories) ? (string) $userForbiddenCategories : '';
        $filterVisibleCategories = $filter['visible_categories'] ?? null;
        $filterVisibleCategories = is_scalar($filterVisibleCategories) ? (string) $filterVisibleCategories : '';
        $filterVisibleImages = $filter['visible_images'] ?? null;
        $filterVisibleImages = is_scalar($filterVisibleImages) ? (string) $filterVisibleImages : '';
        $userLevel = $user['level'] ?? null;
        $userLevel = is_scalar($userLevel) ? (string) $userLevel : '';
        $userImageAccessType = $user['image_access_type'] ?? null;
        $userImageAccessType = is_scalar($userImageAccessType) ? (string) $userImageAccessType : '';
        $userImageAccessList = $user['image_access_list'] ?? null;
        $userImageAccessList = is_scalar($userImageAccessList) ? (string) $userImageAccessList : '';

        $sqlList = [];

        foreach ($conditionFields as $condition => $fieldName) {
            switch ($condition) {
                case 'forbidden_categories':
                    if ($userForbiddenCategories !== '') {
                        $sqlList[] = $fieldName . ' NOT IN (' . $userForbiddenCategories . ')';
                    }

                    break;

                case 'visible_categories':
                    if ($filterVisibleCategories !== '') {
                        $sqlList[] = $fieldName . ' IN (' . $filterVisibleCategories . ')';
                    }

                    break;

                case 'visible_images':
                    if ($filterVisibleImages !== '') {
                        $sqlList[] = $fieldName . ' IN (' . $filterVisibleImages . ')';
                    }

                    // note there is no break - visible include forbidden
                    // no break
                case 'forbidden_images':
                    if ($userImageAccessList !== '' || $userImageAccessType !== 'NOT IN') {
                        $tablePrefix = null;
                        if ($fieldName === 'id') {
                            $tablePrefix = '';
                        } elseif ($fieldName === 'i.id') {
                            $tablePrefix = 'i.';
                        }

                        if ($tablePrefix !== null) {
                            $sqlList[] = $tablePrefix . 'level<=' . $userLevel;
                        } elseif ($userImageAccessList !== '' && $userImageAccessType !== '') {
                            $sqlList[] = $fieldName . ' ' . $userImageAccessType
                                . ' (' . $userImageAccessList . ')';
                        }
                    }

                    break;

                default:
                    throw new \InvalidArgumentException('Unknown condition: ' . $condition);
            }
        }

        if ($sqlList !== []) {
            $sql = '(' . implode(' AND ', $sqlList) . ')';
        } else {
            $sql = $forceOneCondition ? '1 = 1' : '';
        }

        if ($prefixCondition !== null && $sql !== '') {
            $sql = $prefixCondition . ' ' . $sql;
        }

        return $sql;
    }

    /**
     * Revokes direct user-category access. Ported from
     * admin/user_perm.php's own inline `DELETE FROM user_access` (P21
     * Users batch) -- same "if you forbid access to a category, all
     * sub-categories become automatically forbidden" contract as the
     * original (caller passes get_subcat_ids()'s own expansion).
     *
     * @param list<int> $catIds
     */
    public function removeUserAccess(int $userId, array $catIds): void
    {
        $this->repo->deleteUserAccess($userId, $catIds);
    }

    /**
     * Grants direct user-category access. Thin wrapper around the existing
     * add_permission_on_category() free function (admin/include/
     * functions.php) -- that function is also called from
     * create_virtual_category()'s own inheritance logic (P21 Albums
     * batch), out of this method's scope to duplicate.
     *
     * @param list<int> $catIds
     */
    public function grantUserAccess(int $userId, array $catIds): void
    {
        add_permission_on_category($catIds, $userId);
    }
}
