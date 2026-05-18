<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Category\CategoryService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Album\MergeTags;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Event\Tag\GetTagAltNames;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Event\Tag\RenderTagUrl;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageRepository;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Url\UrlService;
use Piwigo\Ws\OpenApi\ApiMethod;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsHelper;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class TagsEndpoints
{
    public function __construct(
        private CategoryService $categoryService,
        private HtmlService $htmlService,
        private ImageRepository $imageRepository,
        private TagAdminService $tagAdminService,
        private TagRepository $tagRepository,
        private TagService $tagService,
        private UrlService $urlService,
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private WsHelper $wsHelper,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    #[ApiMethod(summary: 'Retrieves a list of available tags.', tags: ['tags'])]
    public function getList(array $params, PwgServer &$service): array
    {
        /** @var array<int, array<string, mixed>> $tags */
        $tags = $this->tagService->getAvailableTags();
        if ($params['sort_by_counter']) {
            usort($tags, fn (array $a, array $b): int => (is_numeric($b['counter'] ?? null) ? (int) $b['counter'] : 0) - (is_numeric($a['counter'] ?? null) ? (int) $a['counter'] : 0));
        } else {
            usort($tags, $this->htmlService->tagAlphaCompare(...));
        }
        for ($i = 0; $i < count($tags); $i++) {
            $tagIdRaw = $tags[$i]['id'] ?? null;
            $tags[$i]['id']      = is_numeric($tagIdRaw) ? (int) $tagIdRaw : 0;
            $tagCounterRaw = $tags[$i]['counter'] ?? null;
            $tags[$i]['counter'] = is_numeric($tagCounterRaw) ? (int) $tagCounterRaw : 0;
            $tags[$i]['url']     = $this->urlService->makeIndexUrl(['section' => 'tags', 'tags' => [$tags[$i]]]);
        }
        return ['tags' => new PwgNamedArray($tags, 'tag', $this->wsHelper->getTagXmlAttributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    #[ApiMethod(summary: '<b>Admin only.</b>', tags: ['tags'])]
    public function getAdminList(array $params, PwgServer &$service): array
    {
        return ['tags' => new PwgNamedArray($this->tagService->getAllTags(), 'tag', $this->wsHelper->getTagXmlAttributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    #[ApiMethod(summary: 'Returns elements for the corresponding tags. Fill at least tag_id, tag_url_name or tag_name.', tags: ['tags'])]
    public function getImages(array $params, PwgServer &$service): array
    {
        $tagIdArr     = is_array($params['tag_id']) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $params['tag_id']) : [];
        $tagUrlNameArr = is_array($params['tag_url_name']) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $params['tag_url_name']) : [];
        $tagNameArr   = is_array($params['tag_name']) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $params['tag_name']) : [];
        /** @var array<int, array<string, mixed>> $tagsResult */
        $tagsResult = $this->tagService->findTags($tagIdArr, $tagUrlNameArr, $tagNameArr);
        $tagsById   = [];
        foreach ($tagsResult as $tag) {
            $tagIdVal             = is_numeric($tag['id'] ?? null) ? (int) $tag['id'] : 0;
            $tagsById[$tagIdVal]  = $tag;
        }
        $tagIds = array_keys($tagsById);
        /** @var string[] $whereClausesArr */
        $whereClausesArr = $this->wsHelper->imageSqlFilter($params);
        $whereClauses    = !empty($whereClausesArr) ? implode(' AND ', $whereClausesArr) : null;
        $orderBy         = $this->wsHelper->imageSqlOrder($params, 'i.');
        if (!empty($orderBy)) {
            $orderBy = 'ORDER BY ' . $orderBy;
        }
        $imageIds = $this->tagService->getImageIdsForTags($tagIds, $params['tag_mode_and'] ? 'AND' : 'OR', $whereClauses, $orderBy);
        $countSet = count($imageIds);
        $perPage  = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $page     = is_numeric($params['page']) ? (int) $params['page'] : 0;
        $imageIds = array_slice($imageIds, $perPage * $page, $perPage);
        $imageTagMap = [];
        if (!empty($imageIds) && !$params['tag_mode_and']) {
            $tagMapRows = $this->tagRepository->findImageTagMap($tagIds, $imageIds);
            foreach ($tagMapRows as $row) {
                $imgId = is_numeric($row['image_id']) ? (int) $row['image_id'] : 0;
                $imageTagMap[$imgId] = explode(',', is_string($row['tag_ids'] ?? null) ? $row['tag_ids'] : '');
            }
        }
        $images = [];
        if (!empty($imageIds)) {
            $rankOf      = array_flip($imageIds);
            $favoriteIds = $this->urlService->getUserFavorites();
            $imageRows   = $this->imageRepository->findByIds($imageIds);
            foreach ($imageRows as $row) {
                $image       = [];
                $rowIdInt = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
                $image['rank']        = $rankOf[$rowIdInt] ?? 0;
                $image['is_favorite'] = isset($favoriteIds[$rowIdInt]);
                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($row[$k])) {
                        $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                    }
                }
                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $row[$k] ?? null;
                }
                $imgNameStr    = is_scalar($image['name'] ?? null) ? (string) $image['name'] : '';
                $renderEvent   = new RenderElementName($imgNameStr, $image);
                $this->dispatcher->dispatch($renderEvent);
                $image['name']    = strip_tags($renderEvent->elementName);
                $imgCommentRaw    = $image['comment'] ?? null;
                $imgDescEvent     = new RenderElementDescription(is_string($imgCommentRaw) ? $imgCommentRaw : '', __FUNCTION__);
                $this->dispatcher->dispatch($imgDescEvent);
                $image['comment'] = $imgDescEvent->elementDescription;
                $image = array_merge($image, $this->wsHelper->getUrls($row));
                $imgIdRaw   = $image['id'] ?? null;
                $imgIdKey   = is_numeric($imgIdRaw) ? (int) $imgIdRaw : 0;
                $imageTagIds = $params['tag_mode_and'] ? $tagIds : ($imageTagMap[$imgIdKey] ?? []);
                $imageTags   = [];
                foreach ($imageTagIds as $tagId) {
                    $tagIdInt = (int) $tagId;
                    if (!isset($tagsById[$tagIdInt])) {
                        continue;
                    }
                    $url    = $this->urlService->makeIndexUrl(['section' => 'tags', 'tags' => [$tagsById[$tagIdInt]]]);
                    $pageUrl = $this->urlService->makePictureUrl(['section' => 'tags', 'tags' => [$tagsById[$tagIdInt]], 'image_id' => $row['id'], 'image_file' => $row['file']]);
                    $imageTags[] = ['id' => $tagIdInt, 'url' => $url, 'page_url' => $pageUrl];
                }
                $image['tags'] = new PwgNamedArray($imageTags, 'tag', $this->wsHelper->getTagXmlAttributes());
                $images[] = $image;
            }
            usort($images, $this->categoryService->rankCompare(...));
        }
        return ['paging' => new PwgNamedStruct(['page' => $params['page'], 'per_page' => $params['per_page'], 'count' => count($images), 'total_count' => $countSet]), 'images' => new PwgNamedArray($images, 'image', $this->wsHelper->getImageXmlAttributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Adds a new tag.', tags: ['tags'])]
    public function add(array $params, PwgServer &$service): PwgError|array
    {
        $creationOutput = $this->tagAdminService->createTag(is_string($params['name'] ?? null) ? $params['name'] : '');
        if (isset($creationOutput['error'])) {
            return new PwgError(WsError::InvalidParam->value, is_string($creationOutput['error']) ? $creationOutput['error'] : '');
        }
        $tagAddId = is_numeric($creationOutput['id'] ?? null) ? (int) $creationOutput['id'] : 0;
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $tagAddId, 'add'));
        $newTagRow = $this->tagRepository->findById($tagAddId);
        return ['info' => $creationOutput['info'], 'id' => $creationOutput['id'], 'name' => $newTagRow['name'] ?? '', 'url_name' => $newTagRow['url_name'] ?? ''];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Delete tag(s) by ID.', tags: ['tags'])]
    public function delete(array $params, PwgServer &$service): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $tagIdsRaw = is_array($params['tag_id']) ? $params['tag_id'] : [];
        /** @var int[] $tagIdsDel */
        $tagIdsDel = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $tagIdsRaw);
        if ($this->tagRepository->countByIds($tagIdsDel) !== count($tagIdsDel)) {
            return new PwgError(WsError::InvalidParam->value, 'All tags does not exist.');
        }
        if (count($tagIdsDel) > 0) {
            $this->tagAdminService->deleteTags($tagIdsDel);
            return ['id' => $tagIdsDel];
        }
        return ['id' => []];
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Rename tag', tags: ['tags'])]
    public function rename(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $tagId   = is_numeric($params['tag_id']) ? (int) $params['tag_id'] : 0;
        $tagName = strip_tags(stripslashes(is_string($params['new_name'] ?? null) ? $params['new_name'] : ''));
        $tagRepo = $this->tagRepository;
        if ($tagRepo->countById($tagId) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'This tag does not exist.');
        }
        $existingNames = $tagRepo->findNamesExcluding($tagId);
        $update = [];
        if (in_array($tagName, $existingNames)) {
            return new PwgError(WsError::InvalidParam->value, 'This name is already token');
        } elseif (!empty($tagName)) {
            $urlEvent = new RenderTagUrl($tagName);
            $this->dispatcher->dispatch($urlEvent);
            $update = ['name' => $tagName, 'url_name' => $urlEvent->tagName];
        }
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $tagId, 'edit'));
        $this->tagRepository->updateById($tagId, $update);
        $tag = $tagRepo->findById($tagId) ?? [];
        $tag['raw_name'] = $tag['name'] ?? '';
        $rawTagNameStr   = is_string($tag['raw_name']) ? $tag['raw_name'] : '';
        $tagRenderEvent  = new RenderTagName($rawTagNameStr, $tag);
        $this->dispatcher->dispatch($tagRenderEvent);
        $tag['name']     = $tagRenderEvent->tagName;
        $altEvent        = new GetTagAltNames([], $rawTagNameStr);
        $this->dispatcher->dispatch($altEvent);
        $tag['alt_names'] = $altEvent->value;
        return $tag;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Create a copy of a tag', tags: ['tags'])]
    public function duplicate(array $params, PwgServer &$service): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $dupTagId   = is_numeric($params['tag_id']) ? (int) $params['tag_id'] : 0;
        $dupCopyName = is_string($params['copy_name'] ?? null) ? $params['copy_name'] : '';
        $dupTagRepo  = $this->tagRepository;
        if ($dupTagRepo->countById($dupTagId) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'This tag does not exist.');
        }
        if ($dupTagRepo->countByExactName($dupCopyName) !== 0) {
            return new PwgError(WsError::InvalidParam->value, 'This name is already taken.');
        }
        $dupUrlEvent = new RenderTagUrl($dupCopyName);
        $this->dispatcher->dispatch($dupUrlEvent);
        $dupUrlName  = $dupUrlEvent->tagName;
        $destinationTagId = $this->tagRepository->insertNewTag(['name' => $dupCopyName, 'url_name' => $dupUrlName]);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $destinationTagId, 'add', ['action' => 'duplicate', 'source_tag' => $dupTagId]));
        $destinationTagImageIds = $dupTagRepo->findImageIdsByTagId($dupTagId);
        $inserts = [];
        foreach ($destinationTagImageIds as $imageId) {
            $inserts[] = ['tag_id' => $destinationTagId, 'image_id' => $imageId];
            $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $imageId, 'edit', ['add-tag' => $destinationTagId]));
        }
        $this->tagRepository->insertImageTagsBatch($inserts, false);
        return ['id' => $destinationTagId, 'name' => $dupCopyName, 'url_name' => $dupUrlName, 'count' => count($inserts)];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Merge tags in one other group', tags: ['tags'])]
    public function merge(array $params, PwgServer &$service): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $mergeDestId  = is_numeric($params['destination_tag_id']) ? (int) $params['destination_tag_id'] : 0;
        $mergeTagIds  = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['merge_tag_id']) ? $params['merge_tag_id'] : []);
        $allTags      = array_unique(array_merge($mergeTagIds, [$mergeDestId]));
        $mergeTag     = array_diff($mergeTagIds, [$mergeDestId]);
        $mergeTagRepo = $this->tagRepository;
        if ($mergeTagRepo->countByIds($allTags) !== count($allTags)) {
            return new PwgError(WsError::InvalidParam->value, 'All tags does not exist.');
        }
        $imageInMergeTags = $mergeTagRepo->findDistinctImageIdsByTagIds($mergeTag);
        $imageInDest      = $mergeTagRepo->findImageIdsByTagId($mergeDestId);
        $imageToAdd       = array_diff($imageInMergeTags, $imageInDest);
        $inserts          = [];
        foreach ($imageToAdd as $image) {
            $inserts[] = ['tag_id' => $mergeDestId, 'image_id' => $image];
        }
        $this->tagRepository->insertImageTagsBatch($inserts, true);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $mergeDestId, 'edit'));
        foreach ($imageToAdd as $imageId) {
            $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $imageId, 'edit', ['tag-add' => $mergeDestId]));
        }
        $this->dispatcher->dispatch(new MergeTags($mergeDestId, $mergeTag));
        $this->tagAdminService->deleteTags($mergeTag);
        return ['destination_tag' => $mergeDestId, 'deleted_tag' => $mergeTagIds, 'images_in_merged_tag' => array_merge($imageInDest, $imageToAdd)];
    }
}
