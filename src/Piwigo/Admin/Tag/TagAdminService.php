<?php

declare(strict_types=1);

namespace Piwigo\Admin\Tag;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\Util;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Tag\TagRepository;

final readonly class TagAdminService
{
    public function __construct(
        private Connection $conn,
        private HtmlService $htmlService,
        private ImageAdminService $imageAdminService,
        private TagRepository $tagRepository,
        private UserAdminService $userAdminService,
        private Util $util,
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
                $inserts[] = ['image_id' => $imageId, 'tag_id' => $tagId];
            }
        }
        Dml::massInserts(Tables::imageTag(), array_keys($inserts[0]), $inserts);
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
        EventDispatcher::notify('delete_tags', $tagIds);
        $this->util->pwgActivity('tag', $tagIds, 'delete');
        $this->imageAdminService->updateImagesLastmodified($imageIds);
        $this->userAdminService->invalidateUserCacheNbTags();
    }

    public function tagIdFromTagName(string $tagName): int|string
    {
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        $tagName = trim($tagName);
        $cache   = is_array($page['tag_id_from_tag_name_cache'] ?? null) ? $page['tag_id_from_tag_name_cache'] : [];
        if (isset($cache[$tagName])) {
            $cached = $cache[$tagName];
            return is_int($cached) ? $cached : (is_scalar($cached) ? (string) $cached : '');
        }
        $tagRepo = $this->tagRepository;
        $foundId = $tagRepo->findIdByExactName($tagName);
        $existing = $foundId !== null ? [$foundId] : [];
        if (count($existing) === 0) {
            $urlName  = (string) EventDispatcher::dispatch('render_tag_url', $tagName);
            $foundUrlId = $tagRepo->findIdByUrlName($urlName);
            $existing = $foundUrlId !== null ? [$foundUrlId] : [];
            if (count($existing) === 0) {
                $extraClauses = EventDispatcher::dispatch('get_tag_name_like_where', [], $tagName);
                if (count($extraClauses) > 0) {
                    $existing = array_column($this->conn->executeQuery(
                        'SELECT id FROM ' . Tables::tags() . ' WHERE ' . implode(' OR ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $extraClauses))
                    )->fetchAllAssociative(), 'id');
                }
                if (count($existing) === 0) {
                    Dml::massInserts(Tables::tags(), ['name', 'url_name'], [['name' => $tagName, 'url_name' => $urlName]]);
                    if (!isset($page['tag_id_from_tag_name_cache']) || !is_array($page['tag_id_from_tag_name_cache'])) {
                        $page['tag_id_from_tag_name_cache'] = [];
                    }
                    $newId = (int) $this->conn->lastInsertId();
                    $page['tag_id_from_tag_name_cache'][$tagName] = $newId;
                    $this->userAdminService->invalidateUserCacheNbTags();
                    return $newId;
                }
            }
        }
        if (!isset($page['tag_id_from_tag_name_cache']) || !is_array($page['tag_id_from_tag_name_cache'])) {
            $page['tag_id_from_tag_name_cache'] = [];
        }
        $resolved = is_numeric($existing[0]) ? (int) $existing[0] : (is_scalar($existing[0]) ? (string) $existing[0] : '');
        $page['tag_id_from_tag_name_cache'][$tagName] = $resolved;
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
        if (count($inserts)) {
            Dml::massInserts(Tables::imageTag(), array_keys($inserts[0]), $inserts);
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

    /** @return array<mixed> */
    public function getTaglist(string $query, bool $onlyUserLanguage = true): array
    {
        $rows = $this->conn->executeQuery($query)->fetchAllAssociative();
        return $this->getTaglistFromRows($rows, $onlyUserLanguage);
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
            $name    = EventDispatcher::dispatch('render_tag_name', $rawName, $row);
            $taglist[] = ['name' => $name, 'id' => '~~' . (is_scalar($row['id'] ?? null) ? (string) $row['id'] : '') . '~~'];
            if (!$onlyUserLanguage) {
                $altNames = EventDispatcher::dispatch('get_tag_alt_names', [], $rawName);
                foreach (array_diff(array_unique(array_filter($altNames, is_string(...))), [$name]) as $alt) {
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
            Dml::singleInsert(Tables::tags(), ['name' => $tagName, 'url_name' => EventDispatcher::dispatch('render_tag_url', $tagName)]);
            return ['info' => Lang::t('Tag "%s" was added', stripslashes($tagName)), 'id' => (int) $this->conn->lastInsertId()];
        }
        return ['error' => Lang::t('Tag "%s" already exists', stripslashes($tagName))];
    }
}
