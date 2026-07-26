<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\Projection\Tag;

/**
 * Tag domain business logic. Constructor-injects TagRepository and
 * PermissionService, plain constructor injection (same shape as
 * CalendarService, also L2aCoreDomain -- Permission is same-layer, no
 * deptrac concern). Also injects ActivityLoggerInterface (P23 batch 8d) --
 * `Piwigo\Activity\ActivityService` itself is L2bExtendedDomain, so
 * deleteTags() depends on the L1Infrastructure interface instead, same
 * shape as GroupService/UserService/AuthService's own established fix for
 * this exact layering constraint (see ActivityLoggerInterface's own
 * docblock).
 *
 * Calls trigger_change() directly -- a free function, no class dependency,
 * no deptrac concern. Former bare MysqliDb::singleUpdate()/::massInserts()
 * calls now go through TagRepository (Legacy Coupling Retirement: DI+DBAL
 * migration, Phase 1b).
 *
 * getAllTags()/getCommonTags()/getTagList() each take HtmlRenderingInterface
 * as an explicit parameter (P23 batch 8f-3), same per-method shape as
 * ActivityLoggerInterface above -- their real callers already construct an
 * HtmlService for their own unrelated needs, or can trivially do so (all
 * L3/L4/L2b, HtmlService itself injects nothing).
 */
final readonly class TagService
{
    /**
     * Per-instance memoization for tagIdFromTagName() -- every real caller
     * constructs one TagService and calls tagIdFromTagName() in a loop on
     * it.
     */
    private TagIdCache $tagIdFromTagNameCache;

    public function __construct(
        private TagRepository $repo,
        private PermissionService $permissionService,
        private ActivityLoggerInterface $activityLogger,
    ) {
        $this->tagIdFromTagNameCache = new TagIdCache();
    }

    /**
     * Inline-constructed rather than constructor-injected -- ImageService
     * (P23 batch 8d, Elements/photos sub-batch) is only ever needed for
     * updateImagesLastmodified(), so adding it as a 4th constructor param
     * would mean touching every existing manual `new TagService(...)`
     * call site a 2nd time for a single one-line delegation, matching
     * MetadataService::syncMetadata()'s own established "inline-construct
     * a one-off dependency" precedent rather than TagRepository/
     * PermissionService/ActivityLoggerInterface's own multi-method,
     * constructor-injected shape. Reuses $this->activityLogger (Image and
     * Tag are both L2aCoreDomain, so ActivityLoggerInterface -> concrete
     * ActivityService's own dependency direction is identical either way).
     */
    private function newImageService(): ImageService
    {
        return new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), $this->activityLogger);
    }

    /**
     * Returns all tags even associated to no image. Row = Tag::toArray()'s
     * shape plus name_raw (the pre-render_tag_name-hook value); 'name'
     * itself is overwritten to EventDispatcher::triggerChange()'s own
     * by-design mixed return.
     *
     * @return list<array{id: int, name: mixed, url_name: string, lastmodified: string, name_raw: string}>
     */
    public function getAllTags(HtmlRenderingInterface $htmlRenderer): array
    {
        $tags = [];
        foreach ($this->repo->findAllTags() as $tag) {
            $row = $tag->toArray();
            $row['name_raw'] = $tag->name;
            $row['name'] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_name', $tag->name, $row);
            $tags[] = $row;
        }

        usort($tags, $htmlRenderer->tagAlphaCompare(...));

        return $tags;
    }

    /**
     * Returns a list of tags corresponding to any of ids, url_names or
     * names.
     *
     * @param array<int|string, int|string> $ids
     * @param array<int|string, string> $urlNames
     * @param array<int|string, string> $names
     * @return list<array{id: int, name: string, url_name: string, lastmodified: string}>
     */
    public function findTags(array $ids = [], array $urlNames = [], array $names = []): array
    {
        // Unboxed back to array at this public boundary -- Ws\PwgTags::
        // getImages() mutates $tag['id'] on the rows this returns, which
        // needs real array semantics, not a readonly Tag object (same
        // "narrow once, unbox where genuinely needed" shape as
        // CategoryCatsRenderer/SearchFilterRenderer's own unboxing).
        return array_map(static fn (Tag $tag): array => $tag->toArray(), $this->repo->findByIdsUrlNamesOrNames($ids, $urlNames, $names));
    }

    /**
     * Giving a set of tags with a counter for each one, calculate the
     * display level of each tag.
     *
     * The level of each tag depends on the average count of tags. This
     * calculation method avoid having very different levels for tags
     * having nearly the same count when set are small.
     *
     * $tags is cross-shape generic by design -- real callers pass either
     * getAvailableTags()'s row shape or findCommonTags()'s, which share
     * 'counter' but otherwise differ; only 'counter' is read (defensively),
     * 'level' is the only key added.
     *
     * @param array<int, array<string, mixed>> $tags at least [id, counter]
     * @return array<int, array<string, mixed>> [..., level]
     */
    public function addLevelToTags(array $tags): array
    {

        if ($tags === []) {
            return $tags;
        }

        $totalCount = 0;
        foreach ($tags as $tag) {
            $totalCount += is_numeric($tag['counter']) ? (int) $tag['counter'] : 0;
        }

        // average count of available tags will determine the level of each tag
        $tagAverageCount = (float) $totalCount / (float) count($tags);

        // tag levels threshold calculation: a tag with an average rate
        // must have the middle level.
        $tagsLevels = \Piwigo\Config\CurrentConfig::tagsLevels();

        $thresholdOfLevel = [];
        for ($i = 1; $i < $tagsLevels; $i++) {
            $thresholdOfLevel[$i] = 2.0 * (float) $i * $tagAverageCount / (float) $tagsLevels;
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
     * Same cross-domain generic-row-reader rationale as
     * Category\CategoryService::compareByGlobalRank().
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function tagsIdCompare(array $a, array $b): int
    {
        return ($a['id'] < $b['id']) ? -1 : 1;
    }

    /**
     * Same cross-domain generic-row-reader rationale as tagsIdCompare().
     *
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
     * Row = Tag::toArray()'s shape plus counter (from countImagesPerTag()'s
     * own array<int, int>) and name_raw; 'name' is overwritten to
     * EventDispatcher::triggerChange()'s own by-design mixed return.
     *
     * @param array<int, int|string> $tagIds empty means "no tag_id filter"
     * @return list<array{id: int, name: mixed, url_name: string, lastmodified: string, counter: int, name_raw: string}>
     */
    public function getAvailableTags(array $tagIds = []): array
    {
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
            // CachePools::tagCloud() (P23 Stage 1d) replaces the older
            // CurrentPersistentCache mechanism -- a fixed 300s TTL instead
            // of the previous cacheUpdateTime-keyed immediate invalidation,
            // same accepted staleness tradeoff CategoryTreeCache's own
            // docblock already documents for the equivalent categoryTree()
            // wiring. TagService is constructed manually (`new
            // TagService(...)`) at ~18 call sites, no DI container, so the
            // pool is fetched inline here rather than constructor-injected
            // (same reasoning as CachePools::config()'s own inline use in
            // ConfigService).
            $pool = \Piwigo\Cache\CachePools::tagCloud();
            $item = $pool->getItem('counts_' . \Piwigo\Users\CurrentUser::get()->id);
            $cached = $item->isHit() ? $item->get() : null;
            $tagCounters = is_array($cached) ? $cached : null;

            if ($tagCounters === null) {
                $tagCounters = $this->repo->countImagesPerTag($tagIds, $fandFSql);
                $item->set($tagCounters);
                $pool->save($item);
            }
        } else {
            $tagCounters = $this->repo->countImagesPerTag($tagIds, $fandFSql);
        }

        if ($tagCounters === []) {
            return [];
        }

        $tags = [];
        foreach ($this->repo->findByIdsOrAll(array_keys($tagCounters)) as $tag) {
            if (! isset($tagCounters[$tag->id])) {
                continue;
            }
            $row = $tag->toArray();
            $row['counter'] = $tagCounters[$tag->id];
            $row['name_raw'] = $tag->name;
            $row['name'] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_name', $tag->name, $row);
            $tags[] = $row;
        }

        return $tags;
    }

    /**
     * Returns the number of available tags for the connected user.
     */
    public function getNbAvailableTags(): int
    {
        $currentUser = \Piwigo\Users\CurrentUser::get();

        if (! isset($currentUser->rawAttributes['nb_available_tags'])) {
            $nbAvailableTags = count($this->getAvailableTags());
            $currentUser = $currentUser->withRawAttribute('nb_available_tags', $nbAvailableTags);
            \Piwigo\Users\CurrentUser::set($currentUser);
        }

        $nbAvailableTags = $currentUser->rawAttributes['nb_available_tags'] ?? null;

        return is_numeric($nbAvailableTags) ? (int) $nbAvailableTags : 0;
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

        $whereSql .= in_array($extraImagesWhereSql, [null, ''], true) ? '' : " \nAND (" . $extraImagesWhereSql . ')';

        $groupHavingSql = 'GROUP BY id';
        if ($mode === 'AND' && count($tagIds) > 1) {
            $groupHavingSql .= '
  HAVING COUNT(DISTINCT tag_id)=' . count($tagIds);
        }

        $orderBySql = in_array($orderBy, [null, ''], true) ? \Piwigo\Config\CurrentConfig::orderBy() : $orderBy;

        return $this->repo->findImageIdsForTags($joinSql, $whereSql, $groupHavingSql, $orderBySql);
    }

    /**
     * Return a list of tags corresponding to given items.
     *
     * Row = TagRepository::findCommonTags()'s own shape with 'name'
     * overwritten to EventDispatcher::triggerChange()'s by-design mixed
     * return.
     *
     * @param int[] $items
     * @param int[] $excludedTagIds
     * @return list<array{id: int, name: mixed, url_name: string, lastmodified: string, counter: int}>
     */
    public function getCommonTags(array $items, int $maxTags, HtmlRenderingInterface $htmlRenderer, array $excludedTagIds = []): array
    {
        if ($items === []) {
            return [];
        }

        $tags = [];
        foreach ($this->repo->findCommonTags(array_values($items), $maxTags, array_values($excludedTagIds)) as $row) {
            $row['name'] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_name', $row['name'], $row);
            $tags[] = $row;
        }

        usort($tags, $htmlRenderer->tagAlphaCompare(...));

        return $tags;
    }

    /**
     * Deletes all tags linked to no photo.
     */
    public function deleteOrphanTags(): void
    {
        $orphanTags = $this->getOrphanTags();

        if ($orphanTags !== []) {
            $orphanTagIds = [];
            foreach ($orphanTags as $tag) {
                $orphanTagIds[] = $tag['id'];
            }

            $this->deleteTags($orphanTagIds);
        }
    }

    /**
     * Get all tags (id + name) linked to no photo.
     *
     * @return list<array{id: string, name: string}>
     */
    public function getOrphanTags(): array
    {
        return $this->repo->findOrphanTags();
    }

    /**
     * Set tags to an image.
     * Warning: given tags are all tags associated to the image, not additionnal tags.
     *
     * @param array<int|string> $tags real callers (ws_functions/pwg.images.php)
     *   pass explode()'d tag id strings, never converted to int -- tag ids only
     *   ever flow into SQL/array-value contexts here, so numeric strings work
     *   identically
     */
    public function setTags(array $tags, int $imageId): void
    {
        $this->setTagsOf([
            $imageId => array_values($tags),
        ]);
    }

    /**
     * Add new tags to a set of images.
     *
     * @param array<int|string> $tags see setTags()'s $tags
     * @param int[] $images
     */
    public function addTags(array $tags, array $images): void
    {
        if (count($tags) === 0 || count($images) === 0) {
            return;
        }

        $imageIds = array_values($images);

        $taglistBefore = $this->getImageTagIds($imageIds);

        // we can't insert twice the same {image_id,tag_id} so we must first
        // delete lines we'll insert later
        $this->repo->deleteImageTagByImageAndTagIds($images, $tags);

        $inserts = [];
        foreach ($images as $imageId) {
            foreach (array_unique($tags) as $tagId) {
                $inserts[] = [
                    'image_id' => $imageId,
                    'tag_id' => $tagId,
                ];
            }
        }
        $this->repo->massInsertImageTags($inserts);

        $taglistAfter = $this->getImageTagIds($imageIds);
        $imagesToUpdate = $this->compareImageTagLists($taglistBefore, $taglistAfter);
        $this->newImageService()
            ->updateImagesLastmodified($imagesToUpdate);

        \Piwigo\Users\CurrentUser::set(\Piwigo\Users\CurrentUser::get()->withRawAttribute('nb_available_tags', null));
    }

    /**
     * Delete tags and tags associations.
     *
     * @param array<int, int|string> $tagIds getOrphanTags()'s ids flow in as
     *   mysqli-returned numeric strings; tag ids only ever flow into SQL/array
     *   contexts here, so numeric strings work identically
     */
    public function deleteTags(array $tagIds): void
    {
        // we need the list of impacted images, to update their lastmodified
        $imageIds = $this->repo->findImageIdsForTagIds($tagIds);

        $this->repo->deleteImageTagByTagIds($tagIds);
        $this->repo->deleteByIds($tagIds);

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('delete_tags', $tagIds);
        $this->activityLogger->record('tag', $tagIds, 'delete');

        $this->newImageService()
            ->updateImagesLastmodified($imageIds);
        \Piwigo\Users\CurrentUser::set(\Piwigo\Users\CurrentUser::get()->withRawAttribute('nb_available_tags', null));
    }

    /**
     * Returns a tag id from its name. If nothing found, create a new tag.
     */
    public function tagIdFromTagName(string $tagName): int
    {
        $tagName = trim($tagName);
        $cached = $this->tagIdFromTagNameCache->get($tagName);
        if ($cached !== null) {
            return $cached;
        }

        // search existing by exact name
        $existingId = $this->repo->findIdByName($tagName);

        if ($existingId === null) {
            $urlName = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_url', $tagName);
            if (! is_string($urlName)) {
                // a misbehaving plugin handler could return a non-string; fall
                // back to the untransformed tag name rather than propagate it.
                $urlName = $tagName;
            }

            // search existing by url name
            $existingId = $this->repo->findIdByUrlName($urlName);

            if ($existingId === null) {
                // search by extended description (plugin sub name)
                $subNameWhere = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_tag_name_like_where', [], $tagName);
                $subNameWhere = is_array($subNameWhere) ? array_filter($subNameWhere, is_string(...)) : [];
                if ($subNameWhere !== []) {
                    $existingId = $this->repo->findIdByWhereFragment(implode(' OR ', $subNameWhere));
                }

                if ($existingId === null) {
                    // finally create the tag
                    $newId = $this->repo->insertWithoutTimestamp($tagName, $urlName);
                    $this->tagIdFromTagNameCache->set($tagName, $newId);

                    \Piwigo\Users\CurrentUser::set(\Piwigo\Users\CurrentUser::get()->withRawAttribute('nb_available_tags', null));

                    return $newId;
                }
            }
        }

        $this->tagIdFromTagNameCache->set($tagName, $existingId);
        return $existingId;
    }

    /**
     * Set tags of images. Overwrites all existing associations.
     *
     * @param array<int|string, array<int, int|string>> $tagsOf - keys are image ids, values are array of tag ids
     */
    public function setTagsOf(array $tagsOf): void
    {
        if (count($tagsOf) === 0) {
            return;
        }

        $logger = \Piwigo\Core\CurrentLogger::get();

        $taglistBefore = $this->getImageTagIds(array_keys($tagsOf));
        $logger->debug('taglist_before', $taglistBefore);

        $this->repo->deleteImageTagByImageIds(array_keys($tagsOf));

        $inserts = [];

        foreach ($tagsOf as $imageId => $tagIds) {
            foreach (array_unique($tagIds) as $tagId) {
                $inserts[] = [
                    'image_id' => $imageId,
                    'tag_id' => $tagId,
                ];
            }
        }

        $this->repo->massInsertImageTags($inserts);

        $taglistAfter = $this->getImageTagIds(array_keys($tagsOf));
        $logger->debug('taglist_after', $taglistAfter);
        $imagesToUpdate = $this->compareImageTagLists($taglistBefore, $taglistAfter);
        $logger->debug('$images_to_update', $imagesToUpdate);

        $this->newImageService()
            ->updateImagesLastmodified($imagesToUpdate);
        \Piwigo\Users\CurrentUser::set(\Piwigo\Users\CurrentUser::get()->withRawAttribute('nb_available_tags', null));
    }

    /**
     * Get list of tag ids for each image. Returns an empty list if the image has
     * no tags.
     *
     * @since 2.9
     * @param array<int, int|string> $imageIds
     * @return array<int, int[]> image_id => list of tag ids
     */
    public function getImageTagIds(array $imageIds): array
    {
        if (count($imageIds) === 0) {
            return [];
        }

        $imageIds = array_map(intval(...), $imageIds);

        $tagsOf = array_fill_keys($imageIds, []);
        foreach ($this->repo->findTagIdsByImageIds($imageIds) as $imageTag) {
            $tagImageId = $imageTag['image_id'];
            $tagId = $imageTag['tag_id'];
            assert(is_numeric($tagImageId) && is_numeric($tagId));
            $tagsOf[(int) $tagImageId][] = (int) $tagId;
        }

        return $tagsOf;
    }

    /**
     * Compare the list of tags, for each image. Returns image_ids where tag list has changed.
     *
     * @since 2.9
     * @param array<int, int[]> $taglistBefore - for each image_id (key), list of tag ids;
     *   all real callers pass getImageTagIds()'s return directly
     * @param array<int, int[]> $taglistAfter - for each image_id (key), list of tag ids
     * @return array<int, int> - image_ids where the list has changed
     */
    public function compareImageTagLists(array $taglistBefore, array $taglistAfter): array
    {
        $imagesToUpdate = [];

        foreach ($taglistAfter as $imageId => $listAfter) {
            sort($listAfter);

            $listBefore = $taglistBefore[$imageId] ?? [];
            sort($listBefore);

            if ($listAfter !== $listBefore) {
                $imagesToUpdate[] = $imageId;
            }
        }

        return $imagesToUpdate;
    }

    /**
     * Create a new tag.
     *
     * @return array{info: string, id: int|string}|array{error: string}
     */
    public function createTag(string $tagName): array
    {
        // clean the tag, no html/js allowed in tag name
        $tagName = strip_tags($tagName);

        // does the tag already exist?
        if ($this->repo->findIdByName($tagName) === null) {
            $urlName = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_url', $tagName);
            // a misbehaving plugin handler could return a non-string; fall
            // back to the untransformed tag name rather than propagate it
            // (same guard as tagIdFromTagName()'s own url_name resolution).
            $urlName = is_string($urlName) ? $urlName : $tagName;

            $insertedId = $this->repo->insert($tagName, $urlName);

            return [
                'info' => Lang::t('Tag "%s" was added', stripslashes($tagName)),
                'id' => $insertedId,
            ];
        }

        return [
            'error' => Lang::t('Tag "%s" already exists', stripslashes($tagName)),
        ];
    }

    /**
     * Get tags list from SQL query (ids are surrounded by ~~, for getTagIds()).
     *
     * @param string $query a complete, already-built SELECT id, name query --
     *   real callers each build their own WHERE clause against
     *   Tables::tags()/Tables::imageTag() and hand the whole thing in
     * @param bool $onlyUserLanguage - if true, only local name is returned for
     *    multilingual tags (if ExtendedDescription plugin is active)
     * @return array<int, array{name: mixed, id: string}>
     */
    public function getTagList(string $query, HtmlRenderingInterface $htmlRenderer, bool $onlyUserLanguage = true): array
    {
        $taglist = [];
        $altlist = [];

        foreach ($this->repo->fetchTagListRows($query) as $row) {
            $rawName = $row['name'];
            $name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_name', $rawName, $row);
            $rowId = is_scalar($row['id']) ? (string) $row['id'] : '';

            $taglist[] = [
                'name' => $name,
                'id' => '~~' . $rowId . '~~',
            ];

            if (! $onlyUserLanguage) {
                $altNames = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_tag_alt_names', [], $rawName);
                $altNames = is_array($altNames) ? array_filter($altNames, is_string(...)) : [];
                $nameForDiff = is_scalar($name) ? (string) $name : '';

                foreach (array_diff(array_unique($altNames), [$nameForDiff]) as $alt) {
                    $altlist[] = [
                        'name' => $alt,
                        'id' => '~~' . $rowId . '~~',
                    ];
                }
            }
        }

        usort($taglist, $htmlRenderer->tagAlphaCompare(...));
        if ($altlist !== []) {
            usort($altlist, $htmlRenderer->tagAlphaCompare(...));
            $taglist = array_merge($taglist, $altlist);
        }

        return $taglist;
    }

    /**
     * Get tags ids from a list of raw tags (existing tags or new tags).
     *
     * In $rawTags we receive something like array('~~6~~', '~~59~~', 'New
     * tag', 'Another new tag') The ~~34~~ means that it is an existing
     * tag. We added the surrounding ~~ to permit creation of tags like "10"
     * or "1234" (numeric characters only)
     *
     * @param string|array<string> $rawTags - array or comma separated string;
     *   real callers (array_filter()'d $_POST fields) don't guarantee a
     *   list -- key type is never read below, only values
     * @return int[]
     */
    public function getTagIds(string|array $rawTags, bool $allowCreate = true): array
    {
        $tagIds = [];
        if (! is_array($rawTags)) {
            $rawTags = explode(',', $rawTags);
        }

        foreach ($rawTags as $rawTag) {
            if (preg_match('/^~~(\d+)~~$/', $rawTag, $matches) === 1) {
                $tagIds[] = (int) $matches[1];
            } elseif ($allowCreate) {
                // we have to create a new tag
                $tagIds[] = $this->tagIdFromTagName(strip_tags($rawTag));
            }
        }

        return $tagIds;
    }
}
