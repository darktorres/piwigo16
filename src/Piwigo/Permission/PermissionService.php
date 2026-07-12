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
}
