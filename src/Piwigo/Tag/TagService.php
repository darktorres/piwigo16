<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Doctrine\DBAL\Connection;
use Piwigo\Cache\PersistentCacheRegistry;
use Piwigo\Cache\RequestCache;
use Piwigo\Config\Config;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;

final readonly class TagService
{
    public function __construct(
        private Connection $conn,
        private HtmlService $htmlService,
        private TagRepository $repo,
        private PermissionService $permissionService,
    ) {
    }

    public function getNbAvailableTags(): int
    {
        $user = CurrentUser::get()->rawAttributes;
        if (!isset($user['nb_available_tags'])) {
            $user['nb_available_tags'] = count($this->getAvailableTags());
            Dml::singleUpdate(
                Tables::userCache(),
                ['nb_available_tags' => $user['nb_available_tags']],
                ['user_id' => CurrentUser::get()->id]
            );
        }
        $nb = $user['nb_available_tags'];
        return is_numeric($nb) ? (int) $nb : 0;
    }

    /**
     * @param int[] $tagIds
     * @return array<mixed>
     */
    public function getAvailableTags(array $tagIds = []): array
    {
        if (count($tagIds) === 0) {
            $cached = RequestCache::remember('tag', 'available', fn (): array => $this->fetchAvailableTags([]));
            return is_array($cached) ? $cached : [];
        }
        return $this->fetchAvailableTags($tagIds);
    }

    /**
     * @param int[] $tagIds
     * @return array<mixed>
     */
    private function fetchAvailableTags(array $tagIds): array
    {
        $persistentCache = PersistentCacheRegistry::current();
        $user            = CurrentUser::get()->rawAttributes;

        $usePersistentCache = true;

        $query = '
SELECT tag_id, COUNT(DISTINCT(it.image_id)) AS counter
  FROM ' . Tables::imageCategory() . ' ic
    INNER JOIN ' . Tables::imageTag() . ' it
    ON ic.image_id=it.image_id
  WHERE 1=1
  ' . $this->permissionService->getSqlConditionFandF(
            [
                'forbidden_categories' => 'category_id',
                'visible_categories'   => 'category_id',
                'visible_images'       => 'ic.image_id',
            ],
            ' AND '
        );

        if (count($tagIds) > 0) {
            $usePersistentCache = false;
            $query .= '
    AND tag_id IN (' . implode(',', $tagIds) . ')
';
        }

        $query .= '
  GROUP BY tag_id
;';

        if ($usePersistentCache) {
            $userId      = CurrentUser::get()->id;
            $cacheUpdate = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';
            $cacheKey    = $persistentCache->makeKey('get_available_tags' . $userId . $cacheUpdate);
            $tagCounters = [];
            if (!$persistentCache->get($cacheKey, $tagCounters)) {
                $tagCounters = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'counter', 'tag_id');
                $persistentCache->set($cacheKey, $tagCounters);
            }
        } else {
            $tagCounters = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'counter', 'tag_id');
        }

        if (!is_array($tagCounters) || empty($tagCounters)) {
            return [];
        }

        $rows = count($tagCounters) < 1000
            ? $this->repo->findByIds(array_map(intval(...), array_keys($tagCounters)))
            : $this->repo->findAll();

        $tags = [];
        foreach ($rows as $row) {
            $rowIdRaw = $row['id'];
            $rowId = is_numeric($rowIdRaw) ? (int) $rowIdRaw : (is_string($rowIdRaw) ? $rowIdRaw : '');
            if (isset($tagCounters[$rowId])) {
                $row['counter']  = is_scalar($tagCounters[$rowId]) ? intval($tagCounters[$rowId]) : 0;
                $row['name_raw'] = $row['name'];
                $row['name']     = EventDispatcher::dispatch('render_tag_name', $row['name'], $row);
                $tags[]          = $row;
            }
        }
        return $tags;
    }

    /** @return array<mixed> */
    public function getAllTags(): array
    {
        $tags = [];
        foreach ($this->repo->findAll() as $row) {
            $row['name_raw'] = $row['name'];
            $row['name']     = EventDispatcher::dispatch('render_tag_name', $row['name'], $row);
            $tags[]          = $row;
        }
        usort($tags, $this->htmlService->tagAlphaCompare(...));
        return $tags;
    }

    /**
     * @param array<mixed> $tags
     * @return array<mixed>
     */
    public function addLevelToTags(array $tags): array
    {
        if (count($tags) == 0) {
            return $tags;
        }

        $totalCount = 0;
        foreach ($tags as $tag) {
            if (is_array($tag)) {
                $totalCount += is_numeric($tag['counter']) ? (int) $tag['counter'] : 0;
            }
        }

        $tagAverageCount    = $totalCount / count($tags);
        $thresholdOfLevel   = [];
        for ($i = 1; $i < Config::tagsLevels(); $i++) {
            $thresholdOfLevel[$i] = 2.0 * (float) $i * (float) $tagAverageCount / (float) Config::tagsLevels();
        }

        foreach ($tags as &$tag) {
            if (!is_array($tag)) {
                continue;
            }
            $tag['level'] = 1;
            for ($i = Config::tagsLevels() - 1; $i >= 1; $i--) {
                if ((is_numeric($tag['counter']) ? (int) $tag['counter'] : 0) > $thresholdOfLevel[$i]) {
                    $tag['level'] = $i + 1;
                    break;
                }
            }
        }
        unset($tag);

        return $tags;
    }

    /**
     * @param int[]|int|string $tagIds
     * @return int[]
     */
    public function getImageIdsForTags(array|int|string $tagIds, string $mode = 'AND', ?string $extraImagesWhereSql = '', ?string $orderBy = '', bool $usePermissions = true): array
    {
        if (!is_array($tagIds)) {
            $tagIds = [$tagIds];
        }
        if (empty($tagIds)) {
            return [];
        }

        $query = '
SELECT id
  FROM ' . Tables::images() . ' i ';

        if ($usePermissions) {
            $query .= '
    INNER JOIN ' . Tables::imageCategory() . ' ic ON id=ic.image_id';
        }

        $query .= '
    INNER JOIN ' . Tables::imageTag() . ' it ON id=it.image_id
    WHERE tag_id IN (' . implode(',', $tagIds) . ')';

        if ($usePermissions) {
            $query .= $this->permissionService->getSqlConditionFandF(
                [
                    'forbidden_categories' => 'category_id',
                    'visible_categories'   => 'category_id',
                    'visible_images'       => 'id',
                ],
                "\n  AND"
            );
        }

        $query .= (($extraImagesWhereSql === null || $extraImagesWhereSql === '') ? '' : " \nAND (" . $extraImagesWhereSql . ')') . '
  GROUP BY id';

        if ($mode == 'AND' and count($tagIds) > 1) {
            $query .= '
  HAVING COUNT(DISTINCT tag_id)=' . count($tagIds);
        }
        $query .= "\n" . (($orderBy === null || $orderBy === '') ? Config::orderBy() : $orderBy);

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id'));
    }

    /**
     * @param array<mixed>  $items
     * @param int[]         $excludedTagIds
     * @return array<mixed>
     */
    public function getCommonTags(array $items, int $maxTags, array $excludedTagIds = []): array
    {
        if (empty($items)) {
            return [];
        }
        $imageIds = array_map(static fn (mixed $v): int => is_scalar($v) ? (int) $v : 0, $items);
        $rows     = $this->repo->findCommonTags($imageIds, $maxTags, $excludedTagIds);
        $tags     = [];
        foreach ($rows as $row) {
            $row['name'] = EventDispatcher::dispatch('render_tag_name', $row['name'], $row);
            $tags[]      = $row;
        }
        usort($tags, $this->htmlService->tagAlphaCompare(...));
        return $tags;
    }

    /**
     * @param int[]|string[] $ids
     * @param string[]       $urlNames
     * @param string[]       $names
     * @return array<mixed>
     */
    public function findTags(array $ids = [], array $urlNames = [], array $names = []): array
    {
        return $this->repo->findByIdUrlOrName(
            array_map(intval(...), $ids),
            array_map(strval(...), $urlNames),
            array_map(strval(...), $names)
        );
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function tagsIdCompare(array $a, array $b): int
    {
        return ($a['id'] < $b['id']) ? -1 : 1;
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function tagsCounterCompare(array $a, array $b): int
    {
        if ($a['counter'] == $b['counter']) {
            return $this->tagsIdCompare($a, $b);
        }
        return ($a['counter'] < $b['counter']) ? +1 : -1;
    }
}
