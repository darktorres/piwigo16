<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\ImageRepository;
use Piwigo\Tag\TagRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsHelper;

final class TagsEndpoints
{
    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function getList(array $params, PwgServer &$service): array
    {
        /** @var array<int, array<string, mixed>> $tags */
        $tags = get_available_tags();
        if ($params['sort_by_counter']) {
            usort($tags, fn (array $a, array $b): int => (is_numeric($b['counter'] ?? null) ? (int) $b['counter'] : 0) - (is_numeric($a['counter'] ?? null) ? (int) $a['counter'] : 0));
        } else {
            usort($tags, tag_alpha_compare(...));
        }
        for ($i = 0; $i < count($tags); $i++) {
            $tags[$i]['id']      = is_numeric($tags[$i]['id'] ?? null) ? (int) $tags[$i]['id'] : 0;
            $tags[$i]['counter'] = is_numeric($tags[$i]['counter'] ?? null) ? (int) $tags[$i]['counter'] : 0;
            $tags[$i]['url']     = make_index_url(['section' => 'tags', 'tags' => [$tags[$i]]]);
        }
        return ['tags' => new PwgNamedArray($tags, 'tag', ServiceLocator::get(WsHelper::class)->getTagXmlAttributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function getAdminList(array $params, PwgServer &$service): array
    {
        return ['tags' => new PwgNamedArray(get_all_tags(), 'tag', ServiceLocator::get(WsHelper::class)->getTagXmlAttributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function getImages(array $params, PwgServer &$service): array
    {
        $tagIdArr     = is_array($params['tag_id']) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $params['tag_id']) : [];
        $tagUrlNameArr = is_array($params['tag_url_name']) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $params['tag_url_name']) : [];
        $tagNameArr   = is_array($params['tag_name']) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $params['tag_name']) : [];
        /** @var array<int, array<string, mixed>> $tagsResult */
        $tagsResult = find_tags($tagIdArr, $tagUrlNameArr, $tagNameArr);
        $tagsById   = [];
        foreach ($tagsResult as $tag) {
            $tagIdVal             = is_numeric($tag['id'] ?? null) ? (int) $tag['id'] : 0;
            $tagsById[$tagIdVal]  = $tag;
        }
        $tagIds = array_keys($tagsById);
        /** @var string[] $whereClausesArr */
        $whereClausesArr = ServiceLocator::get(WsHelper::class)->imageSqlFilter($params);
        $whereClauses    = !empty($whereClausesArr) ? implode(' AND ', $whereClausesArr) : null;
        $orderBy         = ServiceLocator::get(WsHelper::class)->imageSqlOrder($params, 'i.');
        if (!empty($orderBy)) {
            $orderBy = 'ORDER BY ' . $orderBy;
        }
        $imageIds = get_image_ids_for_tags($tagIds, $params['tag_mode_and'] ? 'AND' : 'OR', $whereClauses, $orderBy);
        $countSet = count($imageIds);
        $perPage  = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $page     = is_numeric($params['page']) ? (int) $params['page'] : 0;
        $imageIds = array_slice($imageIds, $perPage * $page, $perPage);
        $imageTagMap = [];
        if (!empty($imageIds) && !$params['tag_mode_and']) {
            $tagMapRows = ServiceLocator::get(TagRepository::class)->findImageTagMap($tagIds, $imageIds);
            foreach ($tagMapRows as $row) {
                $imgId = is_numeric($row['image_id']) ? (int) $row['image_id'] : 0;
                $imageTagMap[$imgId] = explode(',', is_scalar($row['tag_ids']) ? (string) $row['tag_ids'] : '');
            }
        }
        $images = [];
        if (!empty($imageIds)) {
            $rankOf      = array_flip($imageIds);
            $favoriteIds = get_user_favorites();
            $imageRows   = ServiceLocator::get(ImageRepository::class)->findByIds($imageIds);
            foreach ($imageRows as $row) {
                $image       = [];
                $rowIdKey    = is_scalar($row['id']) ? (string) $row['id'] : '';
                $image['rank']        = $rankOf[$rowIdKey] ?? 0;
                $image['is_favorite'] = isset($favoriteIds[$rowIdKey]);
                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($row[$k])) {
                        $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                    }
                }
                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $row[$k];
                }
                $imgNameStr    = is_scalar($image['name'] ?? null) ? (string) $image['name'] : '';
                $renderedName  = trigger_change('render_element_name', $imgNameStr, __FUNCTION__);
                $image['name']    = strip_tags((string) $renderedName);
                $image['comment'] = trigger_change('render_element_description', $image['comment'] ?? null, __FUNCTION__);
                $image = array_merge($image, ServiceLocator::get(WsHelper::class)->getUrls($row));
                $imgIdKey   = is_numeric($image['id']) ? (int) $image['id'] : 0;
                $imageTagIds = $params['tag_mode_and'] ? $tagIds : ($imageTagMap[$imgIdKey] ?? []);
                $imageTags   = [];
                foreach ($imageTagIds as $tagId) {
                    $url    = make_index_url(['section' => 'tags', 'tags' => [$tagsById[$tagId]]]);
                    $pageUrl = make_picture_url(['section' => 'tags', 'tags' => [$tagsById[$tagId]], 'image_id' => $row['id'], 'image_file' => $row['file']]);
                    $imageTags[] = ['id' => (int) $tagId, 'url' => $url, 'page_url' => $pageUrl];
                }
                $image['tags'] = new PwgNamedArray($imageTags, 'tag', ServiceLocator::get(WsHelper::class)->getTagXmlAttributes());
                $images[] = $image;
            }
            usort($images, rank_compare(...));
        }
        return ['paging' => new PwgNamedStruct(['page' => $params['page'], 'per_page' => $params['per_page'], 'count' => count($images), 'total_count' => $countSet]), 'images' => new PwgNamedArray($images, 'image', ServiceLocator::get(WsHelper::class)->getImageXmlAttributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function add(array $params, PwgServer &$service): PwgError|array
    {
        $creationOutput = ServiceLocator::get(TagAdminService::class)->createTag(is_scalar($params['name']) ? (string) $params['name'] : '');
        if (isset($creationOutput['error'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, is_scalar($creationOutput['error']) ? (string) $creationOutput['error'] : '');
        }
        $tagAddId = is_numeric($creationOutput['id'] ?? null) ? (int) $creationOutput['id'] : (is_scalar($creationOutput['id'] ?? null) ? (string) $creationOutput['id'] : 0);
        pwg_activity('tag', $tagAddId, 'add');
        $newTagRow = ServiceLocator::get(TagRepository::class)->findById((int) $tagAddId);
        return ['info' => $creationOutput['info'], 'id' => $creationOutput['id'], 'name' => $newTagRow['name'] ?? '', 'url_name' => $newTagRow['url_name'] ?? ''];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function delete(array $params, PwgServer &$service): PwgError|array
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $tagIdsRaw = is_array($params['tag_id']) ? $params['tag_id'] : [];
        /** @var int[] $tagIdsDel */
        $tagIdsDel = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $tagIdsRaw);
        if (ServiceLocator::get(TagRepository::class)->countByIds($tagIdsDel) !== count($tagIdsDel)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'All tags does not exist.');
        }
        if (count($tagIdsDel) > 0) {
            ServiceLocator::get(TagAdminService::class)->deleteTags($tagIdsDel);
            return ['id' => $tagIdsDel];
        }
        return ['id' => []];
    }

    /** @param array<mixed> $params */
    public function rename(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $tagId   = is_numeric($params['tag_id']) ? (int) $params['tag_id'] : (is_scalar($params['tag_id']) ? (string) $params['tag_id'] : 0);
        $tagName = strip_tags(stripslashes(is_scalar($params['new_name']) ? (string) $params['new_name'] : ''));
        $tagRepo = ServiceLocator::get(TagRepository::class);
        if ($tagRepo->countById((int) $tagId) === 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This tag does not exist.');
        }
        $existingNames = $tagRepo->findNamesExcluding((int) $tagId);
        $update = [];
        if (in_array($tagName, $existingNames)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already token');
        } elseif (!empty($tagName)) {
            $update = ['name' => $tagName, 'url_name' => trigger_change('render_tag_url', $tagName)];
        }
        pwg_activity('tag', $tagId, 'edit');
        single_update(TAGS_TABLE, $update, ['id' => $tagId]);
        $tag = $tagRepo->findById((int) $tagId) ?? [];
        $tag['raw_name'] = $tag['name'] ?? '';
        $tag['name']     = trigger_change('render_tag_name', $tag['raw_name'], $tag);
        $tag['alt_names'] = trigger_change('get_tag_alt_names', [], $tag['raw_name']);
        return $tag;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function duplicate(array $params, PwgServer &$service): PwgError|array
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $dupTagId   = is_numeric($params['tag_id']) ? (int) $params['tag_id'] : 0;
        $dupCopyName = is_scalar($params['copy_name']) ? (string) $params['copy_name'] : '';
        $dupTagRepo  = ServiceLocator::get(TagRepository::class);
        if ($dupTagRepo->countById($dupTagId) === 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This tag does not exist.');
        }
        if ($dupTagRepo->countByExactName($dupCopyName) !== 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already taken.');
        }
        single_insert(TAGS_TABLE, ['name' => $dupCopyName, 'url_name' => trigger_change('render_tag_url', $dupCopyName)]);
        $destinationTagId = (int) get_dbal_connection()->lastInsertId();
        pwg_activity('tag', $destinationTagId, 'add', ['action' => 'duplicate', 'source_tag' => $dupTagId]);
        $destinationTagImageIds = $dupTagRepo->findImageIdsByTagId($dupTagId);
        $inserts = [];
        foreach ($destinationTagImageIds as $imageId) {
            $inserts[] = ['tag_id' => $destinationTagId, 'image_id' => $imageId];
            pwg_activity('photo', $imageId, 'edit', ['add-tag' => $destinationTagId]);
        }
        if (count($inserts) > 0) {
            mass_inserts(IMAGE_TAG_TABLE, array_keys($inserts[0]), $inserts);
        }
        return ['id' => $destinationTagId, 'name' => $dupCopyName, 'url_name' => trigger_change('render_tag_url', $dupCopyName), 'count' => count($inserts)];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function merge(array $params, PwgServer &$service): PwgError|array
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $mergeDestId  = is_numeric($params['destination_tag_id']) ? (int) $params['destination_tag_id'] : 0;
        $mergeTagIds  = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['merge_tag_id']) ? $params['merge_tag_id'] : []);
        $allTags      = array_unique(array_merge($mergeTagIds, [$mergeDestId]));
        $mergeTag     = array_diff($mergeTagIds, [$mergeDestId]);
        $mergeTagRepo = ServiceLocator::get(TagRepository::class);
        if ($mergeTagRepo->countByIds($allTags) !== count($allTags)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'All tags does not exist.');
        }
        $imageInMergeTags = $mergeTagRepo->findDistinctImageIdsByTagIds($mergeTag);
        $imageInDest      = $mergeTagRepo->findImageIdsByTagId($mergeDestId);
        $imageToAdd       = array_diff($imageInMergeTags, $imageInDest);
        $inserts          = [];
        foreach ($imageToAdd as $image) {
            $inserts[] = ['tag_id' => $mergeDestId, 'image_id' => $image];
        }
        mass_inserts(IMAGE_TAG_TABLE, ['tag_id', 'image_id'], $inserts, ['ignore' => true]);
        pwg_activity('tag', $mergeDestId, 'edit');
        foreach ($imageToAdd as $imageId) {
            pwg_activity('photo', $imageId, 'edit', ['tag-add' => $mergeDestId]);
        }
        trigger_notify('merge_tags', $mergeDestId, $mergeTag);
        ServiceLocator::get(TagAdminService::class)->deleteTags($mergeTag);
        return ['destination_tag' => $mergeDestId, 'deleted_tag' => $mergeTagIds, 'images_in_merged_tag' => array_merge($imageInDest, $imageToAdd)];
    }
}
