<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Piwigo\Users\CurrentUser;

/**
 * [SEC-33] Decides, from precomputed permission data only, whether an
 * image is visible to the current user -- false means the image belongs
 * exclusively to categories the user cannot see.
 *
 * Never recomputes permissions live on this fast path: deliberately not
 * PermissionService, whose live recomputation is exactly what this fast
 * path must avoid. Delegating the check to
 * {@see PermissionRepository::isImageOutsideForbiddenCategories()} keeps
 * it a single cheap query against already-computed forbidden-category
 * ids.
 *
 * Reads `CurrentUser::forbiddenCategories` directly, the same effective
 * value {@see \Piwigo\Permission\EffectiveForbiddenCategoriesCache}
 * computes, already fresh every request (populated during bootstrap
 * before this class's sole caller ever runs) -- so the permission check
 * itself costs zero additional queries, just a plain property access.
 *
 * Always checks against the current user (via `CurrentUser`), never an
 * arbitrary other user's forbidden categories.
 */
final readonly class ImageVisibilityChecker
{
    public function __construct(
        private PermissionRepository $permissionRepository,
        private CurrentUser $currentUser,
    ) {}

    public function isVisibleToUser(int $imageId): bool
    {
        $forbidden = trim($this->currentUser->get()->forbiddenCategories);

        if ($forbidden === '' || $forbidden === '0') {
            return true; // nothing forbidden for this user -- fast accept
        }

        $forbiddenIds = array_map(intval(...), explode(',', $forbidden));

        return $this->permissionRepository->isImageOutsideForbiddenCategories($imageId, $forbiddenIds);
    }
}
