<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Piwigo\Cache\PersistentFileCache;
use Piwigo\Db\Tables;
use Piwigo\Permission\PermissionService;

/**
 * Tag domain business logic. Constructor-injects TagRepository and
 * PermissionService, plain constructor injection (same shape as
 * CalendarService, also L2aCoreDomain -- Permission is same-layer, no
 * deptrac concern).
 *
 * Calls the still-procedural tag_alpha_compare() (functions_html.inc.php,
 * Html domain, already migrated in P17), trigger_change(), and
 * single_update() (functions_mysqli.inc.php, relocate-only per P23 batch
 * 8c precedent) directly -- free functions, no class dependency, no
 * deptrac concern.
 */
final class TagService
{
    public function __construct(
        private readonly TagRepository $repo,
        private readonly PermissionService $permissionService,
    ) {}

    /**
     * Returns all tags even associated to no image.
     *
     * @return list<array<string, mixed>> [id, name, url_name]
     */
    public function getAllTags(): array
    {
        $tags = [];
        foreach ($this->repo->findAll() as $row) {
            $row['name_raw'] = $row['name'];
            $row['name'] = trigger_change('render_tag_name', $row['name'], $row);
            $tags[] = $row;
        }

        usort($tags, tag_alpha_compare(...));

        return $tags;
    }

    /**
     * Returns a list of tags corresponding to any of ids, url_names or
     * names.
     *
     * @param array<int|string, int|string> $ids
     * @param array<int|string, string> $urlNames
     * @param array<int|string, string> $names
     * @return list<array<string, mixed>> [id, name, url_name]
     */
    public function findTags(array $ids = [], array $urlNames = [], array $names = []): array
    {
        return $this->repo->findByIdsUrlNamesOrNames($ids, $urlNames, $names);
    }

    /**
     * Giving a set of tags with a counter for each one, calculate the
     * display level of each tag.
     *
     * The level of each tag depends on the average count of tags. This
     * calculation method avoid having very different levels for tags
     * having nearly the same count when set are small.
     *
     * @param array<int, array<string, mixed>> $tags at least [id, counter]
     * @return array<int, array<string, mixed>> [..., level]
     */
    public function addLevelToTags(array $tags): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if ($tags === []) {
            return $tags;
        }

        $totalCount = 0;
        foreach ($tags as $tag) {
            $totalCount += is_numeric($tag['counter']) ? (int) $tag['counter'] : 0;
        }

        // average count of available tags will determine the level of each tag
        $tagAverageCount = $totalCount / count($tags);

        // tag levels threshold calculation: a tag with an average rate
        // must have the middle level.
        $tagsLevels = is_numeric($conf['tags_levels'] ?? null) ? (int) $conf['tags_levels'] : 5;

        $thresholdOfLevel = [];
        for ($i = 1; $i < $tagsLevels; $i++) {
            $thresholdOfLevel[$i] = 2 * $i * $tagAverageCount / $tagsLevels;
        }

        foreach ($tags as &$tag) {
            $tag['level'] = 1;

            for ($i = $tagsLevels - 1; $i >= 1; $i--) {
                if ($tag['counter'] > $thresholdOfLevel[$i]) {
                    $tag['level'] = $i + 1;
                    break;
                }
            }
        }
        unset($tag);

        return $tags;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function tagsIdCompare(array $a, array $b): int
    {
        return ($a['id'] < $b['id']) ? -1 : 1;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function tagsCounterCompare(array $a, array $b): int
    {
        $counterA = is_numeric($a['counter']) ? (float) $a['counter'] : 0.0;
        $counterB = is_numeric($b['counter']) ? (float) $b['counter'] : 0.0;

        if ($counterA === $counterB) {
            return $this->tagsIdCompare($a, $b);
        }

        return $counterA < $counterB ? 1 : -1;
    }

    /**
     * Returns all available tags for the connected user (not sorted). The
     * returned list can be a subset of all existing tags due to
     * permissions, also tags with no images are not returned.
     *
     * @param array<int, int|string> $tagIds empty means "no tag_id filter"
     * @return list<array<string, mixed>> [id, name, counter, url_name]
     */
    public function getAvailableTags(array $tagIds = []): array
    {
        /**
         * @var array<string, mixed> $user
         * @var PersistentFileCache $persistent_cache
         */
        global $user, $persistent_cache;

        $usePersistentCache = $tagIds === [];

        $fandFSql = $this->permissionService->getSqlConditionFandF(
            [
                'forbidden_categories' => 'category_id',
                'visible_categories' => 'category_id',
                'visible_images' => 'ic.image_id',
            ],
            ' AND '
        );

        if ($usePersistentCache) {
            $userId = is_scalar($user['id'] ?? null) ? (string) $user['id'] : '';
            $userCacheUpdateTime = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';
            $cacheKey = $persistent_cache->make_key(__METHOD__ . $userId . $userCacheUpdateTime);

            if (! $persistent_cache->get($cacheKey, $tagCounters)) {
                $tagCounters = $this->repo->countImagesPerTag($tagIds, $fandFSql);
                $persistent_cache->set($cacheKey, $tagCounters);
            }
        } else {
            $tagCounters = $this->repo->countImagesPerTag($tagIds, $fandFSql);
        }

        // $persistent_cache->get()'s by-reference $value output param is
        // declared mixed (a cache hit could genuinely hold anything), so
        // narrow once here regardless of which branch above ran.
        $tagCounters = is_array($tagCounters ?? null) ? $tagCounters : [];

        if ($tagCounters === []) {
            return [];
        }

        $tags = [];
        foreach ($this->repo->findByIdsOrAll(array_keys($tagCounters)) as $row) {
            $id = $row['id'] ?? null;
            if ((! is_int($id) && ! is_string($id)) || ! isset($tagCounters[$id])) {
                continue;
            }
            $row['counter'] = $tagCounters[$id];
            $row['name_raw'] = $row['name'];
            $row['name'] = trigger_change('render_tag_name', $row['name'], $row);
            $tags[] = $row;
        }

        return $tags;
    }

    /**
     * Returns the number of available tags for the connected user.
     */
    public function getNbAvailableTags(): int
    {
        /** @var array<string, mixed> $user */
        global $user;

        if (! isset($user['nb_available_tags'])) {
            $user['nb_available_tags'] = count($this->getAvailableTags());
            single_update(
                Tables::userCache(),
                [
                    'nb_available_tags' => $user['nb_available_tags'],
                ],
                [
                    'user_id' => $user['id'],
                ]
            );
        }

        return is_numeric($user['nb_available_tags']) ? (int) $user['nb_available_tags'] : 0;
    }

    /**
     * Return the list of image ids corresponding to given tags. AND & OR
     * mode supported.
     *
     * @param int[] $tagIds
     * @param string|null $extraImagesWhereSql optionally apply a sql where
     *   filter to retrieved images; null is treated the same as '' (both
     *   are empty() below), and BatchManagerSubController passes null
     *   explicitly
     * @param string|null $orderBy optionally overwrite default photo order;
     *   null is treated the same as '' for the same reason
     * @return list<int>
     */
    public function getImageIdsForTags(array $tagIds, string $mode = 'AND', ?string $extraImagesWhereSql = '', ?string $orderBy = '', bool $usePermissions = true): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if ($tagIds === []) {
            return [];
        }

        $joinSql = $usePermissions
            ? 'INNER JOIN ' . Tables::imageCategory() . ' ic ON id=ic.image_id'
            : '';
        $joinSql .= '
    INNER JOIN ' . Tables::imageTag() . ' it ON id=it.image_id';

        $whereSql = 'WHERE tag_id IN (' . implode(',', $tagIds) . ')';

        if ($usePermissions) {
            $whereSql .= $this->permissionService->getSqlConditionFandF(
                [
                    'forbidden_categories' => 'category_id',
                    'visible_categories' => 'category_id',
                    'visible_images' => 'id',
                ],
                "\n  AND"
            );
        }

        $whereSql .= empty($extraImagesWhereSql) ? '' : " \nAND (" . $extraImagesWhereSql . ')';

        $groupHavingSql = 'GROUP BY id';
        if ($mode === 'AND' && count($tagIds) > 1) {
            $groupHavingSql .= '
  HAVING COUNT(DISTINCT tag_id)=' . count($tagIds);
        }

        $orderBySql = empty($orderBy) ? (is_string($conf['order_by'] ?? null) ? $conf['order_by'] : '') : $orderBy;

        return $this->repo->findImageIdsForTags($joinSql, $whereSql, $groupHavingSql, $orderBySql);
    }

    /**
     * Return a list of tags corresponding to given items.
     *
     * @param int[] $items
     * @param int[] $excludedTagIds
     * @return list<array<string, mixed>> [id, name, counter, url_name]
     */
    public function getCommonTags(array $items, int $maxTags, array $excludedTagIds = []): array
    {
        if ($items === []) {
            return [];
        }

        $tags = [];
        foreach ($this->repo->findCommonTags($items, $maxTags, $excludedTagIds) as $row) {
            $row['name'] = trigger_change('render_tag_name', $row['name'], $row);
            $tags[] = $row;
        }

        usort($tags, tag_alpha_compare(...));

        return $tags;
    }
}
