<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Category\Projection\ComputedCategoryRow;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Per-user cached replacement for `user_cache_categories`'s row set --
 * `CategoryService::getComputedCategories()`'s rollup
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
 * 300s TTL ({@see \Piwigo\Cache\CategoryTreeCachePool}) means a newly
 * created/renamed category can take up to 5 minutes to appear/update in the
 * category menu -- a real, user-visible behavior change from the previous
 * `user_cache.need_update`-flagged (effectively immediate) invalidation,
 * accepted as part of this cache-table-rationalization design.
 */
final readonly class CategoryTreeCache
{
    public function __construct(
        private CategoryService $service,
        private CategoryRepository $repo,
        private CacheItemPoolInterface $pool,
    ) {}

    /**
     * @return array<int, ComputedCategoryRow> keyed by category id
     */
    public function getForUser(int $userId, int $level, string $forbiddenCategories): array
    {
        $item = $this->pool->getItem('tree_' . $userId);
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_array($cached)) {
                /** @var array<int, ComputedCategoryRow> $cached */
                return $cached;
            }
        }

        // Already keyed by cat_id -- getComputedCategories() builds its
        // $cats array that way internally.
        $rollupByCatId = $this->service->getComputedCategories($userId, $level, $forbiddenCategories, null)['categories'];

        $names = $this->repo->findNamesByIds(array_keys($rollupByCatId));

        $merged = [];
        foreach ($rollupByCatId as $catId => $row) {
            if (! isset($names[$catId])) {
                // Category was deleted between the rollup query and the
                // name lookup -- vanishingly rare (two queries, no
                // transaction), skip rather than emit a row with no name.
                continue;
            }
            $row->name = $names[$catId]->name;
            $row->permalink = $names[$catId]->permalink;
            $merged[$catId] = $row;
        }

        $item->set($merged);
        $this->pool->save($item);

        return $merged;
    }
}
