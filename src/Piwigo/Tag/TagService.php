<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Piwigo\Cache\RequestCache;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Db\SqlFragment;
use Piwigo\Db\Tables;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Html\HtmlService;
use Piwigo\Image\OrderByService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class TagService
{
    public function __construct(
        private HtmlService $htmlService,
        private TagRepository $repo,
        private PermissionService $permissionService,
        private CacheItemPoolInterface $pool,
        private EventDispatcherInterface $dispatcher,
        private OrderByService $orderByService,
    ) {
    }

    public function getNbAvailableTags(): int
    {
        $user = CurrentUser::get()->rawAttributes;
        if (!isset($user['nb_available_tags'])) {
            $user['nb_available_tags'] = count($this->getAvailableTags());
            $this->repo->setUserCacheFields(CurrentUser::get()->id, ['nb_available_tags' => $user['nb_available_tags']]);
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
        $user = CurrentUser::get()->rawAttributes;

        $useCache = count($tagIds) === 0;

        $perm = $this->permissionService->getSqlConditionFandF(
            [
                'forbidden_categories' => 'category_id',
                'visible_categories'   => 'category_id',
                'visible_images'       => 'ic.image_id',
            ],
            ' AND '
        );

        if ($useCache) {
            $userId      = CurrentUser::get()->id;
            $cacheUpdate = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';
            $cacheKey    = md5('get_available_tags' . $userId . $cacheUpdate . AppInfo::VERSION);
            $item        = $this->pool->getItem($cacheKey);
            if ($item->isHit()) {
                /** @var array<mixed> $tagCounters */
                $tagCounters = $item->get();
            } else {
                $tagCounters = $this->repo->findTagCountersWithPermissions([], $perm->where, $perm->params, $perm->types);
                $item->set($tagCounters);
                $item->expiresAfter(86400);
                $this->pool->save($item);
            }
        } else {
            $tagCounters = $this->repo->findTagCountersWithPermissions(array_values($tagIds), $perm->where, $perm->params, $perm->types);
        }

        if (empty($tagCounters)) {
            return [];
        }

        $entities = count($tagCounters) < 1000
            ? $this->repo->findByIds(array_map(intval(...), array_keys($tagCounters)))
            : $this->repo->findAll();

        $tags = [];
        foreach ($entities as $entity) {
            $rowId = $entity->id->value;
            if (isset($tagCounters[$rowId])) {
                $row             = $entity->toRow();
                $row['counter']  = is_scalar($tagCounters[$rowId]) ? intval($tagCounters[$rowId]) : 0;
                $row['name_raw'] = $entity->name;
                $renderEvent     = new RenderTagName($entity->name, $row);
                $this->dispatcher->dispatch($renderEvent);
                $row['name']     = $renderEvent->tagName;
                $tags[]          = $row;
            }
        }
        return $tags;
    }

    /** @return list<array<string, mixed>> */
    public function getAllTags(): array
    {
        $tags = [];
        foreach ($this->repo->findAll() as $entity) {
            $row             = $entity->toRow();
            $row['name_raw'] = $entity->name;
            $renderEvent     = new RenderTagName($entity->name, $row);
            $this->dispatcher->dispatch($renderEvent);
            $row['name']     = $renderEvent->tagName;
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

        $intTagIds = array_map(intval(...), $tagIds);

        $baseQuery = '
SELECT id
  FROM ' . Tables::images() . ' i ';

        if ($usePermissions) {
            $baseQuery .= '
    INNER JOIN ' . Tables::imageCategory() . ' ic ON id=ic.image_id';
        }

        $baseQuery .= '
    INNER JOIN ' . Tables::imageTag() . ' it ON id=it.image_id
    WHERE tag_id IN (' . implode(',', $intTagIds) . ')';

        $perm = new SqlFragment('');
        if ($usePermissions) {
            $perm = $this->permissionService->getSqlConditionFandF(
                [
                    'forbidden_categories' => 'category_id',
                    'visible_categories'   => 'category_id',
                    'visible_images'       => 'id',
                ],
                "\n  AND"
            );
        }

        return $this->repo->findImageIdsForTagsWithPermissions(
            $baseQuery,
            $perm->where,
            $mode === 'AND' && count($intTagIds) > 1,
            count($intTagIds),
            $extraImagesWhereSql,
            ($orderBy === null || $orderBy === '') ? $this->orderByService->buildOrderByClause(Config::orderBy()) : $orderBy,
            $perm->params,
            $perm->types,
        );
    }

    /**
     * @param  array<mixed>  $items
     * @param  int[]         $excludedTagIds
     * @return list<array<string, mixed>>
     */
    public function getCommonTags(array $items, int $maxTags, array $excludedTagIds = []): array
    {
        if (empty($items)) {
            return [];
        }
        $imageIds = array_map(static fn (mixed $v): int => is_scalar($v) ? (int) $v : 0, $items);
        $entities = $this->repo->findCommonTags($imageIds, $maxTags, $excludedTagIds);
        $tags     = [];
        foreach ($entities as $entity) {
            $row         = $entity->toRow();
            $renderEvent = new RenderTagName($entity->name, $row);
            $this->dispatcher->dispatch($renderEvent);
            $row['name'] = $renderEvent->tagName;
            $tags[]      = $row;
        }
        usort($tags, $this->htmlService->tagAlphaCompare(...));
        return $tags;
    }

    /**
     * @param int[]|string[] $ids
     * @param string[]       $urlNames
     * @param string[]       $names
     * @return list<array<string, mixed>>
     */
    public function findTags(array $ids = [], array $urlNames = [], array $names = []): array
    {
        return array_map(
            static fn (\Piwigo\Tag\Entity\Tag $t): array => $t->toRow(),
            $this->repo->findByIdUrlOrName(
                array_map(intval(...), $ids),
                array_map(strval(...), $urlNames),
                array_map(strval(...), $names),
            ),
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
