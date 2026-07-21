<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Per-user cached replacement for `user_cache_categories`'s row set (P23
 * batch 3b) -- `CategoryService::getComputedCategories()`'s rollup
 * (`nb_images`/`count_images`/`count_categories`/`max_date_last`, permission-
 * filtered) merged with `CategoryRepository::findNamesByIds()`'s
 * `name`/`permalink` (fetched *only* for the cat_ids that survived the
 * permission-filtered rollup -- a forbidden category's name is never looked
 * up). Caching the merged result, not just the rollup, means a cache hit
 * costs zero DB queries; a miss costs two (the rollup query +
 * `findNamesByIds()`) plus one cache write.
 *
 * A separate class rather than a `CategoryService` method: `CategoryService`
 * is constructed directly (`new CategoryService(...)`, no DI container) at
 * ~10 call sites throughout `include/functions_category.inc.php` -- adding a
 * cache dependency to its own constructor would break every one of them.
 *
 * 300s TTL ({@see \Piwigo\Cache\CachePools::categoryTree()}) means a newly
 * created/renamed category can take up to 5 minutes to appear/update in the
 * category menu -- a real, user-visible behavior change from the previous
 * `user_cache.need_update`-flagged (effectively immediate) invalidation,
 * accepted as part of P23's cache-table-rationalization design.
 */
final readonly class CategoryTreeCache
{
    public function __construct(
        private CategoryService $service,
        private CategoryRepository $repo,
        private CacheItemPoolInterface $pool,
    ) {}

    /**
     * @param array<string, mixed> $userdata same shape getuserdata() returns
     *   -- must at least have 'id', 'level', 'forbidden_categories'.
     * @return array<int, array<string, mixed>> keyed by category id
     */
    public function getForUser(array $userdata): array
    {
        $userId = $userdata['id'] ?? null;
        $userIdInt = is_numeric($userId) ? (int) $userId : 0;

        $item = $this->pool->getItem('tree_' . $userIdInt);
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_array($cached)) {
                /** @var array<int, array<string, mixed>> $cached */
                return $cached;
            }
        }

        $rollup = $this->service->getComputedCategories($userdata, null)['categories'];

        // Rekeyed by a real int (getComputedCategories()'s own docblock
        // widens the key to int|string) -- both the findNamesByIds() call
        // below and the merge loop need a reliable int key.
        $rollupByCatId = [];
        foreach ($rollup as $row) {
            $catId = is_numeric($row['cat_id'] ?? null) ? (int) $row['cat_id'] : 0;
            $rollupByCatId[$catId] = $row;
        }

        $names = $this->repo->findNamesByIds(array_keys($rollupByCatId));

        $merged = [];
        foreach ($rollupByCatId as $catId => $row) {
            if (! isset($names[$catId])) {
                // Category was deleted between the rollup query and the
                // name lookup -- vanishingly rare (two queries, no
                // transaction), skip rather than emit a row with no name.
                continue;
            }
            $merged[$catId] = array_merge($row, $names[$catId], [
                'id' => $catId,
            ]);
        }

        $item->set($merged);
        $this->pool->save($item);

        return $merged;
    }
}
