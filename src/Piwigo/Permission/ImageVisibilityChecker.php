<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Piwigo\Users\CurrentUser;

/**
 * [SEC-33] Decides, from precomputed permission data only, whether an
 * image is visible to the current user -- false means the image belongs
 * exclusively to categories the user cannot see.
 *
 * P23 batch 8f (i.php): relocated from i.php's
 * check_derivative_permission() free function (minus the HTTP 403
 * emission, which stays with the caller), unchanged logic. Mirrors the
 * bootMinimal()-era design ADR-0007/0008 already settled: never
 * recomputing permissions live on the fast path -- deliberately NOT
 * PermissionService, whose live recomputation is exactly what this fast
 * path must avoid. Delegating the one query itself to
 * {@see PermissionRepository::isImageOutsideForbiddenCategories()} doesn't
 * reintroduce that recomputation -- it's still the same single cheap
 * query against already-computed forbidden-category ids, just relocated
 * out of a standalone raw-DBAL call per the deptrac DBAL-leak cleanup
 * (2026-07-29): PermissionRepository is exactly as directly constructible
 * as a bare Connection was, no DI container cost added.
 *
 * Gap-closure Stage 4g gap-closure (2026-07-25): retargeted from a raw
 * `user_cache.forbidden_categories` read onto `CurrentUser::
 * forbiddenCategories` -- a real regression this fix closes, caught by a
 * failing Browser test, not found during Stage 4's own original
 * investigation. `getUserData()` no longer writes `user_cache` at all as
 * of Stage 4g, so the old read was frozen at whatever value predated that
 * change (or a test's own direct DB poke) forever after -- a live
 * security-relevant staleness bug, not just an inefficiency.
 * `CurrentUser::forbiddenCategories` is the *same* effective value
 * (`Permission\EffectiveForbiddenCategoriesCache`, Stage 4b), already
 * fresh every request (RequestBootstrap::connect() ->
 * UserBootstrap::initialize() populates it before this class's own sole
 * caller ever runs) -- reading it here is a plain property access, not a
 * new query, so the "1 query" fast-path property this class's docblock
 * used to describe is now "0 queries for the permission check itself,"
 * strictly better. The `$userId` parameter is dropped -- its one real
 * caller (Controller\ImageDerivativeController::checkDerivativePermission())
 * always passed `CurrentUser::get()->id`, never an arbitrary other user's.
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
