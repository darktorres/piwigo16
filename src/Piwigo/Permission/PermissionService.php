<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Group\GroupRepository;
use Piwigo\Users\CurrentUser;
use UnexpectedValueException;

/**
 * Forbidden-categories computation. Constructor-injects
 * PermissionRepository, GroupRepository, and CategoryRepository directly
 * (same shape as PermalinkService). GroupRepository is needed because the
 * group-based access check (calculate_permissions()'s user_group JOIN
 * group_access query) requires Group to live in this same layer
 * (L2aCoreDomain); see deptrac.yaml's own comment on that namespace.
 * CategoryRepository (not CategoryService) is deliberate:
 * addPermissionOnCategory() needs uppercat/subcat id lookups, but
 * CategoryService already constructor-injects PermissionService, so
 * depending on CategoryService here would cycle -- the repository sits
 * below both services with no such conflict.
 */
final readonly class PermissionService
{
    public function __construct(
        private PermissionRepository $repo,
        private GroupRepository $groupRepo,
        private CategoryRepository $categoryRepo,
        private CurrentUser $currentUser,
        private FilterState $filterState,
        private AccessLevelChecker $accessLevelChecker,
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
     * Localized privacy level values. Static since it needs no repository
     * access -- a pure $currentConfig + l10n() read.
     *
     * @return string[]
     */
    public static function getPrivacyLevelOptions(CurrentConfig $currentConfig, Lang $lang): array
    {

        $available_permission_levels = $currentConfig->availablePermissionLevels;

        $options = [];
        $label = '';
        foreach (array_reverse($available_permission_levels) as $level) {
            if ($level === 0) {
                $label = $lang->t('Everybody');
            } else {
                if (strlen($label) > 0) {
                    $label .= ', ';
                }
                $label .= $lang->t(sprintf('Level %d', $level));
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
        if (! $this->accessLevelChecker->isAdmin($userStatus)) {
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
     * Returns every forbidden/visible/access criteria field at once (see
     * {@see PermissionCriteria}'s own docblock for the full per-field
     * mapping), so each caller picks the subset it actually needs off the
     * returned object -- same "typed DTO computed once, each consumer
     * translates its own subset" pattern as
     * {@see \Piwigo\Image\ImageFilterCriteria}. Reads session/request
     * globals directly; no DB access of its own.
     *
     * Throws `UnexpectedValueException` for a malformed `image_access_type`
     * whenever $imageAccessIds/$imageAccessIsAllowlist would otherwise be
     * computed: `image_access_type`/`image_access_list` are the current
     * user's own account data (`CurrentUser::get()->rawAttributes`, set by
     * `getuserdata()`), and an unexpected value there means that data is
     * corrupted -- worth failing fast on regardless of which condition
     * dimension a particular caller happens to read.
     */
    public function getPermissionCriteria(): PermissionCriteria
    {
        $currentUser = $this->currentUser->get();

        $userForbiddenCategories = $currentUser->forbiddenCategories;
        $filterVisibleCategories = $this->filterState->isInitialized() ? $this->filterState->visibleCategories() : '';
        $filterVisibleImages = $this->filterState->isInitialized() ? $this->filterState->visibleImages() : '';
        $userImageAccessType = $currentUser->rawAttributes['image_access_type'] ?? null;
        $userImageAccessType = is_scalar($userImageAccessType) ? (string) $userImageAccessType : '';
        $userImageAccessList = $currentUser->rawAttributes['image_access_list'] ?? null;
        $userImageAccessList = is_scalar($userImageAccessList) ? (string) $userImageAccessList : '';

        $forbiddenImagesApplies = $userImageAccessList !== '' || $userImageAccessType !== 'NOT IN';

        $imageAccessIds = null;
        $imageAccessIsAllowlist = null;
        if ($forbiddenImagesApplies && $userImageAccessList !== '' && $userImageAccessType !== '') {
            if (! in_array($userImageAccessType, ['IN', 'NOT IN'], true)) {
                throw new UnexpectedValueException('Unexpected image_access_type: ' . $userImageAccessType);
            }

            $imageAccessIds = self::csvToIntList($userImageAccessList);
            $imageAccessIsAllowlist = $userImageAccessType === 'IN';
        }

        return new PermissionCriteria(
            forbiddenCategoryIds: $userForbiddenCategories === '' ? null : self::csvToIntList($userForbiddenCategories),
            visibleCategoryIds: $filterVisibleCategories === '' ? null : self::csvToIntList($filterVisibleCategories),
            visibleImageIds: $filterVisibleImages === '' ? null : self::csvToIntList($filterVisibleImages),
            maxLevel: $forbiddenImagesApplies ? $currentUser->level : null,
            imageAccessIds: $imageAccessIds,
            imageAccessIsAllowlist: $imageAccessIsAllowlist,
        );
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
     * Revokes direct user-category access. Forbidding access to a category
     * also forbids every sub-category: the caller is expected to pass the
     * already-expanded subcategory ids in $catIds.
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
     * Grants users direct access to categories -- but only to categories
     * that are actually private; a request naming a public category is
     * silently a no-op for that category.
     *
     * Lives here (not Piwigo\Admin) because real callers span
     * L2aCoreDomain (category-creation code, for its own
     * permission-inheritance step) and
     * L4Integration (Piwigo\Admin\Category\CategoryAdminService,
     * Piwigo\Controller\Admin\SiteUpdateSubController) -- L2a code may not
     * depend on L4, so this must live at the L2a domain owner instead of
     * Piwigo\Admin.
     *
     * Calls `CategoryRepository::findUppercatIds()`/`findSubcategoryIds()`
     * directly rather than `CategoryService::getUppercatIds()`/
     * `getSubcatIds()` -- `CategoryService` already constructor-injects
     * `PermissionService`, so depending on it here would cycle.
     * `findSubcategoryIds()` needs no separate numeric-validation pass
     * here (unlike `CategoryService::getSubcatIds()`'s own wrapper):
     * `$categoryIds` is already normalized to `int` two lines above.
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
}
