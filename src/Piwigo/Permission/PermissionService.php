<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Category\CategoryRepository;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Lang;
use Piwigo\Group\GroupRepository;

/**
 * Forbidden-categories computation. Constructor-injects
 * PermissionRepository, GroupRepository, and CategoryRepository, plain
 * constructor injection (same shape as PermalinkService) -- the
 * group-based access check (calculate_permissions()'s user_group JOIN
 * group_access query) is why Group had to land in this same layer
 * (L2aCoreDomain), see deptrac.yaml's own comment on that namespace.
 * CategoryRepository (not CategoryService) is deliberate:
 * addPermissionOnCategory() needs uppercat/subcat id lookups, but
 * CategoryService already constructor-injects PermissionService, so
 * depending on CategoryService here would cycle -- the repository sits
 * below both services with no such conflict (Legacy Coupling Retirement
 * Phase 4a).
 */
final readonly class PermissionService
{
    public function __construct(
        private PermissionRepository $repo,
        private GroupRepository $groupRepo,
        private CategoryRepository $categoryRepo,
    ) {}

    /**
     * Which of $categoryIds are private -- Admin\GroupPermPageRenderer's
     * own "restrict to private categories" filter.
     *
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function getPrivateCategoryIdsAmong(array $categoryIds): array
    {
        return $this->repo->findPrivateCategoryIdsAmong($categoryIds);
    }

    /**
     * get localized privacy level values
     *
     * P23 batch 8d: relocated from include/functions.inc.php's
     * get_privacy_level_options(), unchanged logic -- static since it
     * needs no repository access (a pure $conf + l10n() read), matching
     * InputValidator's own mixed static/instance precedent.
     *
     * @return string[]
     */
    public static function getPrivacyLevelOptions(): array
    {

        $available_permission_levels = \Piwigo\Config\CurrentConfig::availablePermissionLevels();

        $options = [];
        $label = '';
        foreach (array_reverse($available_permission_levels) as $level) {
            if ($level === 0) {
                $label = Lang::t('Everybody');
            } else {
                if (strlen($label) > 0) {
                    $label .= ', ';
                }
                $label .= Lang::t(sprintf('Level %d', $level));
            }
            $options[$level] = $label;
        }
        return $options;
    }

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
            array_map(
                static fn (CategoryId $id): int => $id->value,
                $this->groupRepo->getAccessibleCategoryIdsForUser(UserId::from($userId)),
            ),
        );

        // uniquify ids : some private categories might be authorized for the
        // groups and for the user
        $authorizedIds = array_unique($authorizedIds);

        // only unauthorized private categories are forbidden
        $forbiddenIds = array_diff($privateIds, $authorizedIds);

        // if user is not an admin, locked categories are forbidden
        if (! \Piwigo\Auth\AccessControl::isAdmin($userStatus)) {
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
     * Returns a `SqlCondition` (bound-parameter carrier) filtering by
     * forbidden/visible categories and images, from CurrentUser and the
     * request-scoped $filter global -- same "reads session/request
     * globals directly" shape as AuditService's
     * $_SERVER['REMOTE_ADDR'] read; a pure string/params builder with no
     * DB access of its own. Was originally paired with a
     * `getSqlConditionFandF(): string` sibling during the SQL-
     * modernization initiative's file-by-file migration (see the
     * initiative's own plan for the full history) -- deleted once every
     * real call site had migrated to this method, since every one of
     * this initiative's 335 heredoc call sites ultimately needed real
     * bound parameters, not a raw interpolated string.
     *
     * No `$prefixCondition` parameter: a `SqlCondition`-based caller
     * composes conjunction via `QueryBuilder::andWhere()`/
     * `SqlCondition::combine()` instead of splicing a raw
     * `"\n  AND"`-shaped continuation directly into a returned string.
     *
     * Every placeholder name gets a per-call unique suffix (a monotonic
     * counter, not fixed names): real call sites combine multiple
     * `SqlCondition` results into one query, and Doctrine only supports
     * one binding per named placeholder per query.
     *
     * @param array<string, string> $conditionFields condition name
     *   (forbidden_categories|visible_categories|visible_images|
     *   forbidden_images) => SQL field/table.column to filter on
     */
    public function getSqlConditionFandFAsCondition(
        array $conditionFields,
        bool $forceOneCondition = false,
    ): SqlCondition {
        $currentUser = \Piwigo\Users\CurrentUser::get();

        $userForbiddenCategories = $currentUser->forbiddenCategories;
        $filterVisibleCategories = \Piwigo\Core\FilterState::isInitializedStatic() ? \Piwigo\Core\FilterState::visibleCategoriesStatic() : '';
        $filterVisibleImages = \Piwigo\Core\FilterState::isInitializedStatic() ? \Piwigo\Core\FilterState::visibleImagesStatic() : '';
        $userImageAccessType = $currentUser->rawAttributes['image_access_type'] ?? null;
        $userImageAccessType = is_scalar($userImageAccessType) ? (string) $userImageAccessType : '';
        $userImageAccessList = $currentUser->rawAttributes['image_access_list'] ?? null;
        $userImageAccessList = is_scalar($userImageAccessList) ? (string) $userImageAccessList : '';

        $suffix = self::nextPlaceholderSuffix();

        $sqlParts = [];
        $parameters = [];
        $types = [];

        foreach ($conditionFields as $condition => $fieldName) {
            switch ($condition) {
                case 'forbidden_categories':
                    if ($userForbiddenCategories !== '') {
                        $placeholder = 'forbidden_categories' . $suffix;
                        $sqlParts[] = $fieldName . ' NOT IN (:' . $placeholder . ')';
                        $parameters[$placeholder] = self::csvToIntList($userForbiddenCategories);
                        $types[$placeholder] = ArrayParameterType::INTEGER;
                    }

                    break;

                case 'visible_categories':
                    if ($filterVisibleCategories !== '') {
                        $placeholder = 'visible_categories' . $suffix;
                        $sqlParts[] = $fieldName . ' IN (:' . $placeholder . ')';
                        $parameters[$placeholder] = self::csvToIntList($filterVisibleCategories);
                        $types[$placeholder] = ArrayParameterType::INTEGER;
                    }

                    break;

                case 'visible_images':
                    if ($filterVisibleImages !== '') {
                        $placeholder = 'visible_images' . $suffix;
                        $sqlParts[] = $fieldName . ' IN (:' . $placeholder . ')';
                        $parameters[$placeholder] = self::csvToIntList($filterVisibleImages);
                        $types[$placeholder] = ArrayParameterType::INTEGER;
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
                            $placeholder = 'user_level' . $suffix;
                            $sqlParts[] = $tablePrefix . 'level<=:' . $placeholder;
                            $parameters[$placeholder] = $currentUser->level;
                            $types[$placeholder] = ParameterType::INTEGER;
                        } elseif ($userImageAccessList !== '' && $userImageAccessType !== '') {
                            if (! in_array($userImageAccessType, ['IN', 'NOT IN'], true)) {
                                // image_access_type is always either literal string
                                // set by getuserdata() -- an unexpected value here
                                // means the raw legacy read is corrupted, not a
                                // real code path to silently tolerate.
                                throw new \UnexpectedValueException('Unexpected image_access_type: ' . $userImageAccessType);
                            }

                            $placeholder = 'image_access_list' . $suffix;
                            $sqlParts[] = $fieldName . ' ' . $userImageAccessType . ' (:' . $placeholder . ')';
                            $parameters[$placeholder] = self::csvToIntList($userImageAccessList);
                            $types[$placeholder] = ArrayParameterType::INTEGER;
                        }
                    }

                    break;

                default:
                    throw new \InvalidArgumentException('Unknown condition: ' . $condition);
            }
        }

        if ($sqlParts !== []) {
            $sql = '(' . implode(' AND ', $sqlParts) . ')';
        } else {
            $sql = $forceOneCondition ? '1 = 1' : '';
        }

        return new SqlCondition($sql, $parameters, $types);
    }

    /**
     * Monotonic per-process suffix for getSqlConditionFandFAsCondition()'s
     * placeholder names. A `static` local rather than a class property:
     * PermissionService is a class-level `readonly class`, and a static
     * property there can't carry a mutable default/be incremented (PHP
     * rejects a readonly static property outright).
     */
    private static function nextPlaceholderSuffix(): string
    {
        /** @var int */
        static $counter = 0;

        return '_' . $counter++;
    }

    /**
     * @return list<int>
     */
    private static function csvToIntList(string $csv): array
    {
        if ($csv === '') {
            return [];
        }

        return array_map(intval(...), explode(',', $csv));
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
     * @param  list<array{user_id: int, cat_id: int}>  $inserts
     */
    public function massInsertUserAccess(array $inserts, bool $ignore = true): void
    {
        $this->repo->massInsertUserAccess($inserts, $ignore);
    }

    /**
     * Grants direct user-category access. Thin wrapper around
     * addPermissionOnCategory() -- that method is also called from
     * create_virtual_category()'s own inheritance logic (P21 Albums
     * batch), out of this method's scope to duplicate.
     *
     * @param list<int> $catIds
     */
    public function grantUserAccess(int $userId, array $catIds): void
    {
        $this->addPermissionOnCategory($catIds, $userId);
    }

    /**
     * Grants users direct access to categories -- but only to categories
     * that are actually private; a request naming a public category is
     * silently a no-op for that category, matching the original.
     *
     * Ported from admin/include/functions.php's
     * add_permission_on_category() (P23 batch 8d), unchanged logic. Lives
     * here (not Piwigo\Admin) because real callers span L2aCoreDomain
     * (this class itself, via grantUserAccess()) and L4Integration
     * (Piwigo\Admin\Category\CategoryAdminService,
     * Piwigo\Controller\Admin\SiteUpdateSubController) -- the same
     * L2a-may-not-depend-on-L4 wall this batch's own FilesystemHelper/
     * PermissionService precedent already resolved elsewhere by picking
     * the L2a domain owner as the target instead of Piwigo\Admin.
     *
     * Calls `CategoryRepository::findUppercatIds()`/`findSubcategoryIds()`
     * directly rather than `CategoryService::getUppercatIds()`/
     * `getSubcatIds()` -- `CategoryService` already constructor-injects
     * `PermissionService`, so depending on it here would cycle (Legacy
     * Coupling Retirement Phase 4a). `findSubcategoryIds()` needs no
     * separate numeric-validation pass here (unlike
     * `CategoryService::getSubcatIds()`'s own wrapper): `$categoryIds` is
     * already normalized to `int` two lines above.
     *
     * @param int|array<int, int> $categoryIds real callers pass a mix of
     *   `list<int>` and array-key-preserving results of array_map()/
     *   \Piwigo\Db\MysqliDb::query2Array() -- never index-dependent below, so not narrowed to
     *   `list`
     * @param int|array<int, int> $userIds
     */
    public function addPermissionOnCategory(int|array $categoryIds, int|array $userIds, bool $applyOnSub = false): void
    {
        if (! is_array($categoryIds)) {
            $categoryIds = [$categoryIds];
        }
        if (! is_array($userIds)) {
            $userIds = [$userIds];
        }

        // check for emptiness
        if (count($categoryIds) === 0 or count($userIds) === 0) {
            return;
        }

        // normalize: real callers pass a mix of int and numeric-string ids
        $categoryIds = array_values(array_map(intval(...), $categoryIds));

        // make sure categories are private and select uppercats or subcats
        $catIds = $this->categoryRepo->findUppercatIds($categoryIds);
        if ($applyOnSub) {
            $catIds = array_merge($catIds, $this->categoryRepo->findSubcategoryIds($categoryIds));
        }

        $catIdsForQuery = array_values($catIds);
        $privateCats = $this->repo->findPrivateCategoryIdsAmong($catIdsForQuery);

        if (count($privateCats) === 0) {
            return;
        }

        $inserts = [];
        foreach ($privateCats as $catId) {
            foreach ($userIds as $userId) {
                $inserts[] = [
                    'user_id' => $userId,
                    'cat_id' => $catId,
                ];
            }
        }

        $this->repo->massInsertUserAccess($inserts);
    }

    /**
     * @param  list<int>  $catIds
     * @return list<array<string, mixed>>
     */
    public function getDirectUserAccessRows(array $catIds): array
    {
        return $this->repo->findDirectUserAccessRows($catIds);
    }

    /**
     * @param  list<int>  $catIds
     * @return list<array<string, mixed>>
     */
    public function getIndirectUserAccessRows(array $catIds): array
    {
        return $this->repo->findIndirectUserAccessRows($catIds);
    }

    /**
     * @param  list<int>  $catIds
     * @return list<array<string, mixed>>
     */
    public function getGroupAccessRows(array $catIds): array
    {
        return $this->repo->findGroupAccessRows($catIds);
    }
}
