<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Per-user cached wrapper around the *effective* permission snapshot --
 * gap-closure Stage 4b/4c/4d/4e (docs/plan/gap-closure-p0-p23.md), replacing
 * `user_cache.forbidden_categories`/`image_access_type`/`image_access_list`/
 * `nb_total_images`/`last_photo_date`. Computed together, matching
 * `UserService::getUserData()`'s own dependency chain: `imageAccessType`/
 * `imageAccessList`/`nbTotalImages` are all derived from the *structural*
 * forbidden-categories value (before the feature-1053 widening below), not
 * the effective one -- preserving that exact distinction is why this isn't
 * simply {@see ForbiddenCategoriesCache} plus 4 more fields.
 *
 * "Effective" forbidden categories = the structural value from
 * {@see PermissionService::getForbiddenCategories()} (same as
 * {@see ForbiddenCategoriesCache}), PLUS -- for non-admins only -- every
 * category with zero visible images (feature 1053), found via
 * {@see CategoryService::getComputedCategories()} -- called unconditionally
 * (matching the legacy code's own real behavior, not gated by admin status)
 * since `lastPhotoDate` is a real byproduct every status needs; only the
 * *widening loop* below is admin-gated.
 *
 * NOT the same value as `Filter\FilterService`'s own, separately-computed
 * `last_photo_date` -- that one deliberately passes a non-null `$filterDays`
 * (the "recent period" filter setting) into `getComputedCategories()`,
 * scoping its own rollup query to recent images only
 * ({@see \Piwigo\Category\CategoryRepository::findComputedCategoriesRollup()}'s
 * own `$imagesJoinCondition` docblock), so it can genuinely differ from this
 * class's unfiltered (`$filterDays = null`) value. Merging them into one
 * cache entry would silently break whichever computation is scoped
 * differently -- confirmed by reading that method's real SQL before
 * assuming this class could just absorb it too.
 *
 * 30s TTL ({@see \Piwigo\Cache\CachePools::effectivePermissions()}) means a
 * permission change becomes visible well within one user session, long
 * enough to avoid recomputing this multi-query calculation on every request
 * for the same user.
 *
 * A separate class rather than a PermissionService/UserService method, same
 * reasoning as {@see ForbiddenCategoriesCache}: both are constructed
 * directly (`new X(...)`, no DI container) at many call sites -- adding a
 * cache dependency to either constructor would break every one of them.
 *
 * Used directly by `UserService::getUserData()` -- gap-closure Stage 4g
 * deleted that method's old `$useCache`-gated `user_cache`-regenerating
 * block outright and replaced it with an unconditional call into this
 * class, so there is no separate legacy writer left to go stale against.
 */
final readonly class EffectiveForbiddenCategoriesCache
{
    public function __construct(
        private PermissionService $permissionService,
        private CategoryService $categoryService,
        private PermissionRepository $permissionRepository,
        private CacheItemPoolInterface $pool,
    ) {}

    /**
     * @param  int|string  $level  `user_infos.level` -- DBAL returns this
     *   tinyint column as a native int, not the mysqli-style string every
     *   other caller of this class's SQL fragments historically assumed.
     * @return array{forbiddenCategories: string, imageAccessType: string, imageAccessList: string, nbTotalImages: string, lastPhotoDate: ?string}
     */
    public function getForUser(int $userId, string $userStatus, int|string $level): array
    {
        $item = $this->pool->getItem('effective_' . $userId);
        if ($item->isHit()) {
            $cached = $item->get();
            if (
                is_array($cached)
                && isset($cached['forbiddenCategories'], $cached['imageAccessType'], $cached['imageAccessList'], $cached['nbTotalImages'])
                && array_key_exists('lastPhotoDate', $cached)
                && is_string($cached['forbiddenCategories'])
                && is_string($cached['imageAccessType'])
                && is_string($cached['imageAccessList'])
                && is_string($cached['nbTotalImages'])
                && ($cached['lastPhotoDate'] === null || is_string($cached['lastPhotoDate']))
            ) {
                return [
                    'forbiddenCategories' => $cached['forbiddenCategories'],
                    'imageAccessType' => $cached['imageAccessType'],
                    'imageAccessList' => $cached['imageAccessList'],
                    'nbTotalImages' => $cached['nbTotalImages'],
                    'lastPhotoDate' => $cached['lastPhotoDate'],
                ];
            }
        }

        $result = $this->compute($userId, $userStatus, $level);

        $item->set($result);
        $this->pool->save($item);

        return $result;
    }

    /**
     * @return array{forbiddenCategories: string, imageAccessType: string, imageAccessList: string, nbTotalImages: string, lastPhotoDate: ?string}
     */
    private function compute(int $userId, string $userStatus, int|string $level): array
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

        // Called unconditionally, matching the legacy code's own real
        // behavior -- lastPhotoDate below is a real byproduct every status
        // needs; only the widening loop that follows is admin-gated.
        $computedCategories = $this->categoryService->getComputedCategories([
            'id' => $userId,
            'level' => $level,
            'forbidden_categories' => $structuralForbidden,
        ], null);

        $effectiveForbidden = $structuralForbidden;
        if (! AccessControl::isAdmin($userStatus)) { // for non admins we forbid categories with no image (feature 1053)
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

        return [
            'forbiddenCategories' => $effectiveForbidden,
            'imageAccessType' => $imageAccessType,
            'imageAccessList' => $imageAccessList,
            'nbTotalImages' => $nbTotalImages,
            'lastPhotoDate' => $lastPhotoDate,
        ];
    }
}
