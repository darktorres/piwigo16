<?php

declare(strict_types=1);

namespace Piwigo\Admin\Tag;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Event\Tag\DeleteTags;
use Piwigo\Event\Tag\GetTagAltNames;
use Piwigo\Event\Tag\GetTagNameLikeWhere;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Event\Tag\RenderTagUrl;
use Piwigo\Html\HtmlService;
use Piwigo\Tag\Entity\Tag;
use Piwigo\Tag\TagRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

final class TagAdminService
{
    /** @var array<string, int|string> */
    private array $tagCache = [];

    public function __construct(
        private readonly HtmlService $htmlService,
        private readonly ImageAdminService $imageAdminService,
        private readonly TagRepository $tagRepository,
        private readonly UserAdminService $userAdminService,
        private readonly ActivityLogger $activityLogger,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function deleteOrphanTags(): void
    {
        $orphans = $this->getOrphanTags();
        if (count($orphans) > 0) {
            $ids = [];
            foreach ($orphans as $tag) {
                if (is_array($tag) && isset($tag['id'])) {
                    $ids[] = is_numeric($tag['id']) ? (int) $tag['id'] : 0;
                }
            }
            $this->deleteTags($ids);
        }
    }

    /** @return array<mixed> */
    public function getOrphanTags(): array
    {
        return $this->tagRepository->findOrphanTags();
    }

    /**
     * @param (int|string)[] $tags
     *
     * @psalm-param array<int|string> $tags
     */
    public function setTags(array $tags, int $imageId): void
    {
        $this->setTagsOf([$imageId => $tags]);
    }

    /**
     * @param (int|string)[] $tags
     * @param int[] $images
     *
     * @psalm-param array<int|string> $tags
     * @psalm-param array<int> $images
     */
    public function addTags(array $tags, array $images): void
    {
        $tagsArr   = $tags;
        $imagesArr = $images;
        if (count($tagsArr) === 0 || count($imagesArr) === 0) {
            return;
        }
        $taglistBefore = $this->getImageTagIds($imagesArr);
        $tagInts       = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $tagsArr);
        $this->tagRepository->deleteImageTagsByImageIdsAndTagIds($imagesArr, $tagInts);
        $inserts = [];
        foreach ($imagesArr as $imageId) {
            foreach (array_unique($tagInts) as $tagId) {
                $inserts[] = ['tag_id' => $tagId, 'image_id' => $imageId];
            }
        }
        $this->tagRepository->insertImageTagsBatch($inserts, false);
        $taglistAfter  = $this->getImageTagIds($imagesArr);
        $toUpdate      = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->compareImageTagLists($taglistBefore, $taglistAfter));
        $this->imageAdminService->updateImagesLastmodified($toUpdate);
        $this->userAdminService->invalidateUserCacheNbTags();
    }

    /** @param int[]|int $tagIds */
    public function deleteTags(array|int $tagIds): void
    {
        if (is_int($tagIds)) {
            $tagIds = [$tagIds];
        }
        $tagRepo  = $this->tagRepository;
        $imageIds = $tagRepo->findDistinctImageIdsByTagIds($tagIds);
        $tagRepo->deleteImageTagsByTagIds($tagIds);
        $tagRepo->deleteByIds($tagIds);
        $this->dispatcher->dispatch(new DeleteTags($tagIds));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $tagIds, 'delete'));
        $this->imageAdminService->updateImagesLastmodified($imageIds);
        $this->userAdminService->invalidateUserCacheNbTags();
    }

    public function tagIdFromTagName(string $tagName): int|string
    {
        $tagName = trim($tagName);
        if (isset($this->tagCache[$tagName])) {
            $cached = $this->tagCache[$tagName];
            return $cached;
        }
        $tagRepo = $this->tagRepository;
        $foundId = $tagRepo->findIdByExactName($tagName);
        $existing = $foundId !== null ? [$foundId] : [];
        if (count($existing) === 0) {
            $urlEvent = new RenderTagUrl($tagName);
            $this->dispatcher->dispatch($urlEvent);
            $urlName  = $urlEvent->tagName;
            $foundUrlId = $tagRepo->findIdByUrlName($urlName);
            $existing = $foundUrlId !== null ? [$foundUrlId] : [];
            if (count($existing) === 0) {
                $likeEvent = new GetTagNameLikeWhere([], $tagName);
                $this->dispatcher->dispatch($likeEvent);
                $extraClauses = $likeEvent->value;
                if (count($extraClauses) > 0) {
                    $clauses  = array_values(array_filter($extraClauses, is_string(...)));
                    $existing = $this->tagRepository->findIdsByOrClauses($clauses);
                }
                if (count($existing) === 0) {
                    $newId = $this->tagRepository->insertNewTag(['name' => $tagName, 'url_name' => $urlName]);
                    $this->tagCache[$tagName] = $newId;
                    $this->userAdminService->invalidateUserCacheNbTags();
                    return $newId;
                }
            }
        }
        $resolved = $existing[0];
        $this->tagCache[$tagName] = $resolved;
        return $resolved;
    }

    /** @param array<mixed> $tagsOf */
    public function setTagsOf(array $tagsOf): void
    {
        if (count($tagsOf) === 0) {
            return;
        }
        $logger        = LoggerRegistry::current();
        $imageIds      = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_keys($tagsOf));
        $taglistBefore = $this->getImageTagIds($imageIds);
        $logger->debug('taglist_before', $taglistBefore);
        $this->tagRepository->deleteImageTagsByImageIds($imageIds);
        $inserts = [];
        foreach ($tagsOf as $imageId => $tagIds) {
            $tagIdsArr = is_array($tagIds) ? array_map(fn (mixed $v): int|string => is_numeric($v) ? (int) $v : (is_scalar($v) ? (string) $v : ''), $tagIds) : [];
            foreach (array_unique($tagIdsArr) as $tagId) {
                $inserts[] = ['image_id' => $imageId, 'tag_id' => $tagId];
            }
        }
        if (count($inserts) > 0) {
            $batch = [];
            foreach ($inserts as $row) {
                $batch[] = [
                    'tag_id'   => is_numeric($row['tag_id']) ? (int) $row['tag_id'] : 0,
                    'image_id' => is_numeric($row['image_id']) ? (int) $row['image_id'] : 0,
                ];
            }
            $this->tagRepository->insertImageTagsBatch($batch, false);
        }
        $taglistAfter = $this->getImageTagIds($imageIds);
        $logger->debug('taglist_after', $taglistAfter);
        $toUpdate = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->compareImageTagLists($taglistBefore, $taglistAfter));
        $logger->debug('images_to_update', $toUpdate);
        $this->imageAdminService->updateImagesLastmodified($toUpdate);
        $this->userAdminService->invalidateUserCacheNbTags();
    }

    /**
     * @param int[] $imageIds
     * @return array<mixed>
     */
    public function getImageTagIds(array $imageIds): array
    {
        if (count($imageIds) === 0) {
            return [];
        }
        $tagsOf    = array_fill_keys($imageIds, []);
        $imageTags = $this->tagRepository->findImageTagPairs($imageIds);
        foreach ($imageTags as $imageTag) {
            $imgIdKey = is_numeric($imageTag['image_id']) ? (int) $imageTag['image_id'] : 0;
            if (isset($tagsOf[$imgIdKey])) {
                $tagsOf[$imgIdKey][] = $imageTag['tag_id'];
            }
        }
        return $tagsOf;
    }

    /**
     * @param array<mixed> $taglistBefore
     * @param array<mixed> $taglistAfter
     * @return array<mixed>
     */
    public function compareImageTagLists(array $taglistBefore, array $taglistAfter): array
    {
        $toUpdate = [];
        foreach ($taglistAfter as $imageId => $listAfter) {
            $listAfter  = is_array($listAfter) ? $listAfter : [];
            sort($listAfter);
            $listBefore = is_array($taglistBefore[$imageId] ?? null) ? $taglistBefore[$imageId] : [];
            sort($listBefore);
            if ($listAfter !== $listBefore) {
                $toUpdate[] = $imageId;
            }
        }
        return $toUpdate;
    }

    /**
     * Return a display-ready tag list (name+id, optionally with alt-name
     * synonyms) for the given tag ids. Used by the batch-manager filter
     * row to render the currently-selected tags.
     *
     * @param  list<int> $tagIds
     * @return array<mixed>
     */
    public function getTaglistForIds(array $tagIds, bool $onlyUserLanguage = true): array
    {
        if ($tagIds === []) {
            return [];
        }
        return $this->getTaglistFromRows(
            array_map(static fn (Tag $t): array => $t->toRow(), $this->tagRepository->findByIds($tagIds)),
            $onlyUserLanguage,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function getTaglistFromRows(array $rows, bool $onlyUserLanguage = true): array
    {
        $taglist = [];
        $altlist = [];
        foreach ($rows as $row) {
            $rawName = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';
            $renderEvent = new RenderTagName($rawName, $row);
            $this->dispatcher->dispatch($renderEvent);
            $name = $renderEvent->tagName;
            $taglist[] = ['name' => $name, 'id' => '~~' . (is_scalar($row['id'] ?? null) ? (string) $row['id'] : '') . '~~'];
            if (!$onlyUserLanguage) {
                $altEvent = new GetTagAltNames([], $rawName);
                $this->dispatcher->dispatch($altEvent);
                foreach (array_diff(array_unique(array_filter($altEvent->value, is_string(...))), [$name]) as $alt) {
                    $altlist[] = ['name' => $alt, 'id' => '~~' . (is_scalar($row['id'] ?? null) ? (string) $row['id'] : '') . '~~'];
                }
            }
        }
        usort($taglist, $this->htmlService->tagAlphaCompare(...));
        if (count($altlist)) {
            usort($altlist, $this->htmlService->tagAlphaCompare(...));
            $taglist = array_merge($taglist, $altlist);
        }
        return $taglist;
    }

    /**
     * @return int[]
     *
     * @param (array|string)[]|string $rawTags
     *
     * @psalm-param array<int|string, array<int|string, mixed>|string>|string $rawTags
     */
    public function getTagIds(array|string $rawTags, bool $allowCreate = true): array
    {
        $tagIds = [];
        if (!is_array($rawTags)) {
            $rawTags = explode(',', $rawTags);
        }
        foreach ($rawTags as $rawTag) {
            if (is_string($rawTag) && preg_match('/^~~(\d+)~~$/', $rawTag, $matches)) {
                $tagIds[] = (int) $matches[1];
            } elseif ($allowCreate && is_string($rawTag)) {
                $tagIds[] = (int) $this->tagIdFromTagName(strip_tags($rawTag));
            }
        }
        return $tagIds;
    }

    /** @return array<mixed> */
    public function createTag(string $tagName): array
    {
        $tagName    = strip_tags($tagName);
        $existingId = $this->tagRepository->findIdByExactName($tagName);
        if ($existingId === null) {
            $createUrlEvent = new RenderTagUrl($tagName);
            $this->dispatcher->dispatch($createUrlEvent);
            $newId = $this->tagRepository->insertNewTag(['name' => $tagName, 'url_name' => $createUrlEvent->tagName]);
            return ['info' => Lang::t('Tag "%s" was added', stripslashes($tagName)), 'id' => $newId];
        }
        return ['error' => Lang::t('Tag "%s" already exists', stripslashes($tagName))];
    }
}
