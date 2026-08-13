<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryService;
use Piwigo\Permission\Projection\EffectivePermissionsSnapshot;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Per-user cached wrapper around the "effective" permission snapshot:
 * `forbiddenCategories`, `imageAccessType`, `imageAccessList`,
 * `nbTotalImages`, and `lastPhotoDate`. These are computed together
 * because `imageAccessType`/`imageAccessList`/`nbTotalImages` are all
 * derived from the *structural* forbidden-categories value (before the
 * widening below), not the effective one -- preserving that exact
 * distinction is why this isn't simply {@see ForbiddenCategoriesCache}
 * plus 4 more fields.
 *
 * "Effective" forbidden categories = the structural value from
 * {@see PermissionService::getForbiddenCategories()} (same as
 * {@see ForbiddenCategoriesCache}), PLUS -- for non-admins only -- every
 * category with zero visible images, found via
 * {@see CategoryService::getComputedCategories()} -- called
 * unconditionally, not gated by admin status, since `lastPhotoDate` is a
 * real byproduct every status needs; only the *widening loop* below is
 * admin-gated.
 *
 * NOT the same value as `Filter\FilterService`'s own, separately-computed
 * `last_photo_date` -- that one deliberately passes a non-null `$filterDays`
 * (the "recent period" filter setting) into `getComputedCategories()`,
 * scoping its own rollup query to recent images only
 * ({@see \Piwigo\Category\CategoryRepository::findComputedCategoriesRollup()}'s
 * own `$imagesJoinCondition` docblock), so it can genuinely differ from this
 * class's unfiltered (`$filterDays = null`) value. Merging them into one
 * cache entry would conflate two differently-scoped computations.
 *
 * 30s TTL ({@see \Piwigo\Cache\EffectivePermissionsCachePool}) keeps a
 * permission change visible well within one user session, while avoiding
 * recomputing this multi-query calculation on every request for the same
 * user.
 *
 * A separate class rather than a PermissionService/UserService method, same
 * reasoning as {@see ForbiddenCategoriesCache}: both are constructed
 * directly (`new X(...)`, no DI container) at many call sites -- adding a
 * cache dependency to either constructor would break every one of them.
 *
 * `UserService::getUserData()` calls this class unconditionally to
 * populate its permission-derived fields, so there is no separate writer
 * of that data to go stale against.
 */
final readonly class EffectiveForbiddenCategoriesCache
{
    public function __construct(
        private AccessLevelChecker $accessLevelChecker,
        private PermissionService $permissionService,
        private CategoryService $categoryService,
        private PermissionRepository $permissionRepository,
        private CacheItemPoolInterface $pool,
    ) {}

    /**
     * @param  int|string  $level  `user_infos.level` as returned by DBAL: a
     *   native int for this tinyint column; the parameter also accepts a
     *   numeric string since not every caller passes the DBAL-native type.
     */
    public function getForUser(int $userId, string $userStatus, int|string $level): EffectivePermissionsSnapshot
    {
        $item = $this->pool->getItem('effective_' . $userId);
        if ($item->isHit()) {
            $cached = $item->get();
            if ($cached instanceof EffectivePermissionsSnapshot) {
                return $cached;
            }
        }

        $result = $this->compute($userId, $userStatus, $level);

        $item->set($result);
        $this->pool->save($item);

        return $result;
    }

    private function compute(int $userId, string $userStatus, int|string $level): EffectivePermissionsSnapshot
    {
        $structuralForbidden = $this->permissionService->getForbiddenCategories($userId, $userStatus);

        // this list does not contain images that are not in at least an
        // authorized category -- same query UserService::getUserData()'s
        // own regeneration block runs, based on the structural value.
        $forbiddenIds = array_map(
            static fn (int $v): string => (string) $v,
            $this->permissionRepository->findImageIdsOutsideForbiddenCategories($structuralForbidden, $level)
        );
        if ($forbiddenIds === []) {
            $forbiddenIds[] = '0';
        }
        $imageAccessType = 'NOT IN';
        $imageAccessList = implode(',', $forbiddenIds);

        $nbTotalImages = $this->permissionRepository->countAccessibleImages($structuralForbidden, $imageAccessType, $imageAccessList);

        // Called unconditionally: lastPhotoDate below is needed for every
        // user status; only the widening loop that follows is admin-gated.
        $computedCategories = $this->categoryService->getComputedCategories([
            'id' => $userId,
            'level' => $level,
            'forbidden_categories' => $structuralForbidden,
        ], null);

        $effectiveForbidden = $structuralForbidden;
        if (! $this->accessLevelChecker->isAdmin($userStatus)) { // for non admins we forbid categories with no image (feature 1053)
            $zeroImageIds = [];
            foreach ($computedCategories['categories'] as $cat) {
                if ($cat['count_images'] === 0) {
                    $zeroImageIds[] = (string) $cat['cat_id'];
                }
            }

            if ($zeroImageIds !== []) {
                $effectiveForbidden = $structuralForbidden === ''
                    ? implode(',', $zeroImageIds)
                    : $structuralForbidden . ',' . implode(',', $zeroImageIds);
            }
        }

        $lastPhotoDate = $computedCategories['lastPhotoDate'];

        return new EffectivePermissionsSnapshot(
            forbiddenCategories: $effectiveForbidden,
            imageAccessType: $imageAccessType,
            imageAccessList: $imageAccessList,
            nbTotalImages: $nbTotalImages,
            lastPhotoDate: $lastPhotoDate,
        );
    }
}
