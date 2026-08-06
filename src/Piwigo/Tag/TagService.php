<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use LogicException;
use Piwigo\Cache\CachePools;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Tag\DeleteTags;
use Piwigo\Event\Tag\GetTagAltNames;
use Piwigo\Event\Tag\GetTagNameLikeWhere;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Event\Tag\RenderTagUrl;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageFilterCriteria;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Tag\Projection\Tag;
use Piwigo\Tag\Projection\TagBrief;
use Piwigo\Users\CurrentUser;

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
 * Also constructor-injects EventDispatcher (singleton/service-locator
 * elimination campaign, Phase 4). Former bare MysqliDb::singleUpdate()/::massInserts() calls now
 * go through TagRepository (Legacy Coupling Retirement: DI+DBAL migration,
 * Phase 1b).
 *
 * getAllTags()/getCommonTags()/getTagListForImage()/getTagListByIds() each
 * take HtmlRenderingInterface as an explicit parameter (P23 batch 8f-3), same
 * per-method shape as
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
        private Lang $lang,
        private TagRepository $repo,
        private PermissionService $permissionService,
        private ActivityLoggerInterface $activityLogger,
        private EventDispatcher $eventDispatcher,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private CurrentLogger $currentLogger,
        private SessionService $sessionService,
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
        return new ImageService($this->lang, EntityManagerFactory::build(DbConnection::build())->getRepository(ImageEntity::class), $this->activityLogger, $this->sessionService, $this->eventDispatcher, $this->currentConfig, $this->translator(), $this->paths());
    }

    /**
     * Same "avoid a 2nd touch of every manual `new TagService(...)` call
     * site" reasoning as translator() above.
     */
    private function paths(): Paths
    {
        $paths = Kernel::container()->get(Paths::class);
        if (! $paths instanceof Paths) {
            throw new LogicException('Container returned an unexpected type for ' . Paths::class);
        }

        return $paths;
    }

    /**
     * Container resolve, not a constructor property -- same "avoid a 2nd
     * touch of every manual `new TagService(...)` call site for a single
     * one-line delegation" reasoning as newImageService()'s own docblock
     * above (singleton/service-locator elimination campaign, Phase 11
     * sub-phase 11G).
     */
    private function translator(): Translator
    {
        $translator = Kernel::container()->get(Translator::class);
        if (! $translator instanceof Translator) {
            throw new LogicException('Container returned an unexpected type for ' . Translator::class);
        }

        return $translator;
    }

    /**
     * Returns all tags even associated to no image. Row = Tag::toArray()'s
     * shape plus name_raw (the pre-render_tag_name-hook value); 'name'
     * itself is passed through dispatchChange(new RenderTagName(...)).
     *
     * @return list<array{id: int, name: string, url_name: string, lastmodified: string, name_raw: string}>
     */
    public function getAllTags(HtmlRenderingInterface $htmlRenderer): array
    {
        $tags = [];
        foreach ($this->repo->findAllTags() as $tag) {
            $row = $tag->toArray();
            $row['name_raw'] = $tag->name;
            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderTagName($tag->name, $row));
            $row['name'] = $nameEvent->tagName;
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
        $tagsLevels = $this->currentConfig->tagsLevels();

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
     * own array<int, int>) and name_raw; 'name' is passed through
     * dispatchChange(new RenderTagName(...)).
     *
     * @param array<int, int|string> $tagIds empty means "no tag_id filter"
     * @return list<array{id: int, name: string, url_name: string, lastmodified: string, counter: int, name_raw: string}>
     */
    public function getAvailableTags(array $tagIds = []): array
    {
        $usePersistentCache = $tagIds === [];

        $condition = $this->permissionService->getPermissionCriteria();

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
            $pool = CachePools::tagCloud();
            $item = $pool->getItem('counts_' . $this->currentUser->get()->id->value);
            $cached = $item->isHit() ? $item->get() : null;
            $tagCounters = is_array($cached) ? array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                array_filter($cached, is_int(...), ARRAY_FILTER_USE_KEY)
            ) : null;

            if ($tagCounters === null) {
                $tagCounters = $this->repo->countImagesPerTag($tagIds, $condition);
                $item->set($tagCounters);
                $pool->save($item);
            }
        } else {
            $tagCounters = $this->repo->countImagesPerTag($tagIds, $condition);
        }

        if ($tagCounters === []) {
            return [];
        }

        $tags = [];
        $tagIds = array_map(static fn (int $id): TagId => TagId::from($id), array_keys($tagCounters));
        foreach ($this->repo->findByIdsOrAll($tagIds) as $tag) {
            if (! isset($tagCounters[$tag->id->value])) {
                continue;
            }
            $row = $tag->toArray();
            $row['counter'] = $tagCounters[$tag->id->value];
            $row['name_raw'] = $tag->name;
            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderTagName($tag->name, $row));
            $row['name'] = $nameEvent->tagName;
            $tags[] = $row;
        }

        return $tags;
    }

    /**
     * Returns the number of available tags for the connected user.
     */
    public function getNbAvailableTags(): int
    {
        $user = $this->currentUser->get();

        if (! isset($user->rawAttributes['nb_available_tags'])) {
            $nbAvailableTags = count($this->getAvailableTags());
            $user = $user->withRawAttribute('nb_available_tags', $nbAvailableTags);
            $this->currentUser->set($user);
        }

        $nbAvailableTags = $user->rawAttributes['nb_available_tags'] ?? null;

        return is_numeric($nbAvailableTags) ? (int) $nbAvailableTags : 0;
    }

    /**
     * Return the list of image ids corresponding to given tags. AND & OR
     * mode supported.
     *
     * SQL-modernization audit, Item 14 Sub-phase C3: $extraImagesWhereSql/
     * $extraParams/$extraTypes (a caller-built bound-fragment escape hatch,
     * only ever populated by Ws\PwgTags::getImages()'s own
     * `WsHelper::stdImageSqlFilterCriteria()` output) replaced by
     * `?ImageFilterCriteria $filterCriteria` -- see that class's own
     * docblock.
     *
     * @param list<TagId> $tagIds
     * @param string|null $orderBy optionally overwrite default photo order;
     *   null is treated the same as '' for the same reason
     *   BatchManagerSubController passes null explicitly
     * @return list<int>
     */
    public function getImageIdsForTags(array $tagIds, string $mode = 'AND', ?ImageFilterCriteria $filterCriteria = null, ?string $orderBy = '', bool $usePermissions = true): array
    {

        if ($tagIds === []) {
            return [];
        }

        $orderBySql = in_array($orderBy, [null, ''], true) ? $this->currentConfig->orderBy() : $orderBy;

        return $this->repo->findImageIdsForTags(
            array_map(static fn (TagId $id): int => $id->value, $tagIds),
            $mode,
            $usePermissions,
            $this->permissionService->getPermissionCriteria(),
            $filterCriteria,
            $orderBySql
        );
    }

    /**
     * Return a list of tags corresponding to given items.
     *
     * Row = TagRepository::findCommonTags()'s own shape with 'name'
     * passed through dispatchChange(new RenderTagName(...)).
     *
     * @param int[] $items
     * @param int[] $excludedTagIds
     * @return list<array{id: int, name: string, url_name: string, lastmodified: string, counter: int}>
     */
    public function getCommonTags(array $items, int $maxTags, HtmlRenderingInterface $htmlRenderer, array $excludedTagIds = []): array
    {
        if ($items === []) {
            return [];
        }

        $tags = [];
        foreach ($this->repo->findCommonTags(array_values($items), $maxTags, array_values($excludedTagIds)) as $row) {
            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderTagName($row['name'], $row));
            $row['name'] = $nameEvent->tagName;
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
            $this->deleteTags(array_map(static fn (TagBrief $tag): TagId => $tag->id, $orphanTags));
        }
    }

    /**
     * Get all tags (id + name) linked to no photo.
     *
     * @return list<TagBrief>
     */
    public function getOrphanTags(): array
    {
        return $this->repo->findOrphanTags();
    }

    /**
     * Set tags to an image.
     * Warning: given tags are all tags associated to the image, not additionnal tags.
     *
     * @param list<TagId> $tags
     */
    public function setTags(array $tags, int $imageId): void
    {
        $this->setTagsOf([
            $imageId => $tags,
        ]);
    }

    /**
     * Add new tags to a set of images.
     *
     * @param list<TagId> $tags see setTags()'s $tags
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

        // massInsertImageTags() is a raw BatchWriter passthrough (bypasses
        // the ORM entirely), so tag_id unwraps to a plain int right here,
        // at the point of building its row shape.
        $inserts = [];
        foreach ($images as $imageId) {
            foreach (array_unique($tags) as $tagId) {
                $inserts[] = [
                    'image_id' => $imageId,
                    'tag_id' => $tagId->value,
                ];
            }
        }
        $this->repo->massInsertImageTags($inserts);

        $taglistAfter = $this->getImageTagIds($imageIds);
        $imagesToUpdate = $this->compareImageTagLists($taglistBefore, $taglistAfter);
        $this->newImageService()
            ->updateImagesLastmodified($imagesToUpdate);

        $this->currentUser->set($this->currentUser->get()->withRawAttribute('nb_available_tags', null));
    }

    /**
     * Removes only the association between $imageIds and $tagIds (unlike
     * deleteTags() below, which deletes the tags themselves) --
     * Admin\BatchManagerGlobalPageRenderer's own "del_tags" action.
     *
     * @param int[] $imageIds
     * @param list<TagId> $tagIds
     */
    public function removeTagsFromImages(array $imageIds, array $tagIds): void
    {
        $this->repo->deleteImageTagByImageAndTagIds($imageIds, $tagIds);
    }

    /**
     * Delete tags and tags associations.
     *
     * @param list<TagId> $tagIds
     */
    public function deleteTags(array $tagIds): void
    {
        // we need the list of impacted images, to update their lastmodified
        $imageIds = $this->repo->findImageIdsForTagIds($tagIds);

        $this->repo->deleteImageTagByTagIds($tagIds);
        $this->repo->deleteByIds($tagIds);

        // EventDispatcher/ActivityLoggerInterface are cross-boundary sinks
        // (plugin events, ActivityEntity::$objectId is a plain int column)
        // -- unwrap ->value explicitly, same rule as every other
        // unconverted-domain sink in this codebase.
        $rawTagIds = array_map(static fn (TagId $id): int => $id->value, $tagIds);
        $this->eventDispatcher->dispatchNotify(new DeleteTags($rawTagIds));
        $this->activityLogger->record('tag', $rawTagIds, 'delete');

        $this->newImageService()
            ->updateImagesLastmodified($imageIds);
        $this->currentUser->set($this->currentUser->get()->withRawAttribute('nb_available_tags', null));
    }

    /**
     * Inserts a brand-new tag row from an already-validated name/url_name
     * pair -- Ws\PwgTags::duplicate()'s own "copy this tag under a new
     * name" step, unlike tagIdFromTagName() below which looks up an
     * existing tag first. Stays without an explicit `lastmodified`, same
     * as TagRepository::insertWithoutTimestamp()'s own docblock.
     */
    public function duplicateTag(string $name, string $urlName): TagId
    {
        return $this->repo->insertWithoutTimestamp($name, $urlName);
    }

    /**
     * Raw {tag_id, image_id} association copy, bypassing addTags()'s
     * before/after comparison and updateImagesLastmodified() side effect --
     * Ws\PwgTags::duplicate()/merge() already know exactly which rows to
     * copy and log their own activity entries separately.
     *
     * @param  list<array{image_id: int|string, tag_id: int|string}>  $inserts
     */
    public function copyImageTagAssociations(array $inserts, bool $ignore = false): void
    {
        $this->repo->massInsertImageTags($inserts, $ignore);
    }

    /**
     * Returns a tag id from its name. If nothing found, create a new tag.
     */
    public function tagIdFromTagName(string $tagName): TagId
    {
        $tagName = trim($tagName);
        $cached = $this->tagIdFromTagNameCache->get($tagName);
        if ($cached !== null) {
            return $cached;
        }

        // search existing by exact name
        $existingId = $this->repo->findIdByName($tagName);

        if ($existingId === null) {
            $urlNameEvent = $this->eventDispatcher->dispatchChange(new RenderTagUrl($tagName));
            $urlName = $urlNameEvent->tagName;

            // search existing by url name
            $existingId = $this->repo->findIdByUrlName($urlName);

            if ($existingId === null) {
                // search by extended description (plugin sub name) --
                // SQL-modernization audit: the hook now returns LIKE
                // pattern VALUES (bound as parameters), not raw SQL
                // fragments -- see TagRepository::
                // findIdByNameLikeAnyPattern()'s own docblock for why.
                $likeWhereEvent = $this->eventDispatcher->dispatchChange(new GetTagNameLikeWhere([], $tagName));
                $namePatterns = array_values(array_filter($likeWhereEvent->value, is_string(...)));
                if ($namePatterns !== []) {
                    $existingId = $this->repo->findIdByNameLikeAnyPattern($namePatterns);
                }

                if ($existingId === null) {
                    // finally create the tag
                    $newId = $this->repo->insertWithoutTimestamp($tagName, $urlName);
                    $this->tagIdFromTagNameCache->set($tagName, $newId);

                    $this->currentUser->set($this->currentUser->get()->withRawAttribute('nb_available_tags', null));

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
     * @param array<int|string, list<TagId>> $tagsOf - keys are image ids, values are array of tag ids
     */
    public function setTagsOf(array $tagsOf): void
    {
        if (count($tagsOf) === 0) {
            return;
        }

        $logger = $this->currentLogger->get();

        $taglistBefore = $this->getImageTagIds(array_keys($tagsOf));
        $logger->debug('taglist_before', $taglistBefore);

        $this->repo->deleteImageTagByImageIds(array_keys($tagsOf));

        // massInsertImageTags() is a raw BatchWriter passthrough (bypasses
        // the ORM entirely), so tag_id unwraps to a plain int right here,
        // same as addTags()'s own identical insert-row construction.
        $inserts = [];

        foreach ($tagsOf as $imageId => $tagIds) {
            foreach (array_unique($tagIds) as $tagId) {
                $inserts[] = [
                    'image_id' => $imageId,
                    'tag_id' => $tagId->value,
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
        $this->currentUser->set($this->currentUser->get()->withRawAttribute('nb_available_tags', null));
    }

    /**
     * Get list of tag ids for each image. Returns an empty list if the image has
     * no tags.
     *
     * @since 2.9
     * @param array<int, int|string> $imageIds
     * @return array<int, list<TagId>> image_id => list of tag ids
     */
    public function getImageTagIds(array $imageIds): array
    {
        if (count($imageIds) === 0) {
            return [];
        }

        $imageIds = array_map(intval(...), $imageIds);

        $tagsOf = array_fill_keys($imageIds, []);
        foreach ($this->repo->findTagIdsByImageIds($imageIds) as $imageTag) {
            $tagsOf[$imageTag['image_id']][] = $imageTag['tag_id'];
        }

        return $tagsOf;
    }

    /**
     * Compare the list of tags, for each image. Returns image_ids where tag list has changed.
     *
     * @since 2.9
     * @param array<int, list<TagId>> $taglistBefore - for each image_id (key), list of tag ids;
     *   all real callers pass getImageTagIds()'s return directly
     * @param array<int, list<TagId>> $taglistAfter - for each image_id (key), list of tag ids
     * @return array<int, int> - image_ids where the list has changed
     */
    public function compareImageTagLists(array $taglistBefore, array $taglistAfter): array
    {
        $imagesToUpdate = [];

        foreach ($taglistAfter as $imageId => $listAfter) {
            sort($listAfter);

            $listBefore = $taglistBefore[$imageId] ?? [];
            sort($listBefore);

            // TagId objects: !== compares by identity, not value, so two
            // separately-constructed TagId(5) instances would never be
            // considered equal -- would silently mark every image as
            // "changed" on every call. Compare the unwrapped value lists
            // instead (empirically verified: sort() itself DOES order
            // same-class objects correctly by property value under
            // SORT_REGULAR, so only the equality check needed fixing).
            $valuesAfter = array_map(static fn (TagId $id): int => $id->value, $listAfter);
            $valuesBefore = array_map(static fn (TagId $id): int => $id->value, $listBefore);

            if ($valuesAfter !== $valuesBefore) {
                $imagesToUpdate[] = $imageId;
            }
        }

        return $imagesToUpdate;
    }

    /**
     * Create a new tag.
     *
     * @return array{info: string, id: int}|array{error: string}
     */
    public function createTag(string $tagName): array
    {
        // clean the tag, no html/js allowed in tag name
        $tagName = strip_tags($tagName);

        // does the tag already exist?
        if ($this->repo->findIdByName($tagName) === null) {
            $insertUrlNameEvent = $this->eventDispatcher->dispatchChange(new RenderTagUrl($tagName));
            $urlName = $insertUrlNameEvent->tagName;

            $insertedId = $this->repo->insert($tagName, $urlName);

            return [
                'info' => $this->lang->t('Tag "%s" was added', stripslashes($tagName)),
                'id' => $insertedId->value,
            ];
        }

        return [
            'error' => $this->lang->t('Tag "%s" already exists', stripslashes($tagName)),
        ];
    }

    /**
     * Further SQL-modernization audit, Item 10: getTagList() (a caller-built-
     * query wrapper around the now-deleted TagRepository::fetchTagListRows())
     * replaced with this shared row-processing tail plus one typed public
     * method per real query shape below (ids are surrounded by ~~, for
     * getTagIds()).
     *
     * @param  list<array{id: int, name: string}>  $rows
     * @param  bool  $onlyUserLanguage  if true, only local name is returned for
     *    multilingual tags (if ExtendedDescription plugin is active)
     * @return array<int, array{name: string, id: string}>
     */
    private function buildTagList(array $rows, HtmlRenderingInterface $htmlRenderer, bool $onlyUserLanguage): array
    {
        $taglist = [];
        $altlist = [];

        foreach ($rows as $row) {
            $rawName = $row['name'];
            $listNameEvent = $this->eventDispatcher->dispatchChange(new RenderTagName($rawName, $row));
            $name = $listNameEvent->tagName;
            $rowId = (string) $row['id'];

            $taglist[] = [
                'name' => $name,
                'id' => '~~' . $rowId . '~~',
            ];

            if (! $onlyUserLanguage) {
                $altNamesEvent = $this->eventDispatcher->dispatchChange(new GetTagAltNames([], $rawName));
                $altNames = array_filter($altNamesEvent->value, is_string(...));
                $nameForDiff = $name;

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
     * Admin\PictureModifyPageRenderer/Admin\BatchManagerUnitPageRenderer's
     * own per-image tag list.
     *
     * @return array<int, array{name: mixed, id: string}>
     */
    public function getTagListForImage(int $imageId, HtmlRenderingInterface $htmlRenderer, bool $onlyUserLanguage = true): array
    {
        return $this->buildTagList($this->repo->findTagsForImage($imageId), $htmlRenderer, $onlyUserLanguage);
    }

    /**
     * Admin\BatchManager\FilterPanelRenderer's own tag-filter list.
     *
     * @param  list<int>  $ids
     * @return array<int, array{name: mixed, id: string}>
     */
    public function getTagListByIds(array $ids, HtmlRenderingInterface $htmlRenderer, bool $onlyUserLanguage = true): array
    {
        return $this->buildTagList($this->repo->findTagsByIds($ids), $htmlRenderer, $onlyUserLanguage);
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
     * @return list<TagId>
     */
    public function getTagIds(string|array $rawTags, bool $allowCreate = true): array
    {
        $tagIds = [];
        if (! is_array($rawTags)) {
            $rawTags = explode(',', $rawTags);
        }

        foreach ($rawTags as $rawTag) {
            if (preg_match('/^~~(\d+)~~$/', $rawTag, $matches) === 1) {
                $tagIds[] = TagId::from((int) $matches[1]);
            } elseif ($allowCreate) {
                // we have to create a new tag
                $tagIds[] = $this->tagIdFromTagName(strip_tags($rawTag));
            }
        }

        return $tagIds;
    }

    public function getById(TagId $id): ?Tag
    {
        return $this->repo->findById($id);
    }

    public function existsById(int $id): bool
    {
        return $this->repo->existsById($id);
    }

    /**
     * @param  list<int>  $ids
     */
    public function countExistingIds(array $ids): int
    {
        return $this->repo->countExistingIds($ids);
    }

    public function existsByName(string $name): bool
    {
        return $this->repo->existsByName($name);
    }

    /**
     * @return list<string>
     */
    public function getOtherNames(int $excludeId): array
    {
        return $this->repo->findOtherNames($excludeId);
    }

    /**
     * Renames a tag -- Ws\PwgTags::rename()'s own single-tag update.
     */
    public function renameTag(TagId $id, string $name, string $urlName): void
    {
        $this->repo->updateNameAndUrlName($id, $name, $urlName);
    }

    /**
     * @param  list<int>  $tagIds
     * @param  list<int>  $imageIds
     * @return array<int, string>
     */
    public function getCommaJoinedTagIdsByImageIds(array $tagIds, array $imageIds): array
    {
        return $this->repo->findCommaJoinedTagIdsByImageIds($tagIds, $imageIds);
    }

    /**
     * @param  list<TagId>  $tagIds
     * @return list<int>
     */
    public function getImageIdsForTagIds(array $tagIds): array
    {
        return $this->repo->findImageIdsForTagIds($tagIds);
    }

    /**
     * @return list<int>
     */
    public function getIdsByNameLike(string $pattern): array
    {
        return $this->repo->findIdsByNameLike($pattern);
    }

    /**
     * @return array<int, int>
     */
    public function getImageCountsPerTagUnrestricted(): array
    {
        return $this->repo->countImagesPerTagUnrestricted();
    }

    public function countAll(): int
    {
        return $this->repo->countAll();
    }

    public function countAllImageTagLinks(): int
    {
        return $this->repo->countAllImageTagLinks();
    }

    /**
     * Every tag, unenriched -- Admin\TagsPageRenderer's own listing, which
     * builds its own row shape (counter/alt_names) rather than
     * {@see getAllTags()}'s own enriched shape.
     *
     * @return list<Tag>
     */
    public function getAll(): array
    {
        return $this->repo->findAllTags();
    }
}
