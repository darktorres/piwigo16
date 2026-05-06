<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Rate\RateRepository;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsHelper;

final class ImagesEndpoints
{
    // ── Internal helpers ─────────────────────────────────────────────────

    public function addImageCategoryRelations(mixed $imageId, string $categoriesString, bool $replaceMode = false): true|PwgError
    {
        $catIds          = [];
        $rankOnCategory  = [];
        $searchCurrentRanks = false;
        if (empty($categoriesString)) {
            if ($replaceMode) {
                ServiceLocator::get(CategoryRepository::class)->deleteImageCategoryByImageIds([is_numeric($imageId) ? (int) $imageId : 0]);
                update_category([]);
            }
            return true;
        }
        $tokens = explode(';', $categoriesString);
        foreach ($tokens as $token) {
            $parts  = explode(',', $token);
            $catId  = $parts[0];
            $rank   = $parts[1] ?? null;
            if (!preg_match('/^\d+$/', $catId)) {
                continue;
            }
            $catIds[] = $catId;
            $rankOnCategory[$catId] = empty($rank) ? 'auto' : $rank;
            if ($rankOnCategory[$catId] === 'auto') {
                $searchCurrentRanks = true;
            }
        }
        $catIds = array_unique($catIds);
        if (count($catIds) === 0) {
            if ($replaceMode) {
                ServiceLocator::get(CategoryRepository::class)->deleteImageCategoryByImageIds([is_numeric($imageId) ? (int) $imageId : 0]);
                update_category([]);
            }
            return true;
        }
        $dbCatIds    = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', $catIds) . ');')->fetchAllAssociative(), 'id');
        $unknownCatIds = array_diff($catIds, array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $dbCatIds));
        if (count($unknownCatIds) !== 0) {
            return new PwgError(500, '[addImageCategoryRelations] the following categories are unknown: ' . implode(', ', $unknownCatIds));
        }
        $existingCatIds = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column(get_dbal_connection()->executeQuery('SELECT category_id FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE image_id = ' . (is_scalar($imageId) ? (string) $imageId : '0') . ';')->fetchAllAssociative(), 'category_id'));
        if ($replaceMode) {
            $toRemoveCatIds = array_diff($existingCatIds, $catIds);
            if (count($toRemoveCatIds) > 0) {
                ServiceLocator::get(CategoryRepository::class)->removeImageFromCategories(is_numeric($imageId) ? (int) $imageId : 0, array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $toRemoveCatIds));
                update_category(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $toRemoveCatIds));
            }
        }
        $newCatIds = array_diff($catIds, $existingCatIds);
        if (count($newCatIds) === 0) {
            return true;
        }
        if ($searchCurrentRanks) {
            $currentRankOf = array_column(get_dbal_connection()->executeQuery('SELECT category_id, MAX(`rank`) AS max_rank FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE `rank` IS NOT NULL AND category_id IN (' . implode(',', $newCatIds) . ') GROUP BY category_id;')->fetchAllAssociative(), 'max_rank', 'category_id');
            foreach ($newCatIds as $catId) {
                if (!isset($currentRankOf[$catId])) {
                    $currentRankOf[$catId] = 0;
                }
                if ($rankOnCategory[$catId] === 'auto') {
                    $rankOnCategory[$catId] = (is_numeric($currentRankOf[$catId]) ? (int) $currentRankOf[$catId] : 0) + 1;
                }
            }
        }
        $inserts = [];
        foreach ($newCatIds as $catId) {
            $inserts[] = ['image_id' => $imageId, 'category_id' => $catId, 'rank' => $rankOnCategory[$catId]];
        }
        mass_inserts(IMAGE_CATEGORY_TABLE, array_keys($inserts[0]), $inserts);
        update_category($newCatIds);
        return true;
    }

    public function mergeChunks(string $outputFilepath, string $originalSum, string $type): mixed
    {
        $logger = LoggerRegistry::current();
        $logger->debug('[mergeChunks] input $outputFilepath : ' . $outputFilepath);
        if (is_file($outputFilepath)) {
            unlink($outputFilepath);
            if (is_file($outputFilepath)) {
                return new PwgError(500, '[mergeChunks] error while trying to remove existing ' . $outputFilepath);
            }
        }
        $uploadDir = Config::uploadDir() . '/buffer';
        $pattern   = '/' . $originalSum . '-' . $type . '/';
        $chunks    = [];
        if ($handle = opendir($uploadDir)) {
            while (false !== ($file = readdir($handle))) {
                if (preg_match($pattern, $file)) {
                    $chunks[] = $uploadDir . '/' . $file;
                }
            }
            closedir($handle);
        }
        sort($chunks);
        foreach ($chunks as $chunk) {
            $string = file_get_contents($chunk);
            if (!file_put_contents($outputFilepath, $string, FILE_APPEND)) {
                return new PwgError(500, '[mergeChunks] error while writting chunks for ' . $outputFilepath);
            }
            unlink($chunk);
        }
        return null;
    }

    public function removeChunks(string $originalSum, string $type): void
    {
        $uploadDir = Config::uploadDir() . '/buffer';
        $pattern   = '/' . $originalSum . '-' . $type . '/';
        $chunks    = [];
        if ($handle = opendir($uploadDir)) {
            while (false !== ($file = readdir($handle))) {
                if (preg_match($pattern, $file)) {
                    $chunks[] = $uploadDir . '/' . $file;
                }
            }
            closedir($handle);
        }
        foreach ($chunks as $chunk) {
            unlink($chunk);
        }
    }

    // ── API methods ──────────────────────────────────────────────────────

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function addComment(array $params, PwgServer $service): PwgError|array
    {
        if (!Config::activateComments()) {
            return new PwgError(403, 'Comments are disabled');
        }
        $pImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $query    = 'SELECT DISTINCT image_id FROM ' . IMAGE_CATEGORY_TABLE . ' INNER JOIN ' . CATEGORIES_TABLE . ' ON category_id=id WHERE commentable="true" AND image_id=' . $pImageId . get_sql_condition_FandF(['forbidden_categories' => 'id', 'visible_categories' => 'id', 'visible_images' => 'image_id'], ' AND') . ';';
        if (ServiceLocator::get(Connection::class)->executeQuery($query)->fetchOne() === false) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid image_id');
        }
        $comm = ['author' => trim(is_scalar($params['author']) ? (string) $params['author'] : ''), 'content' => trim(is_scalar($params['content']) ? (string) $params['content'] : ''), 'image_id' => $pImageId];
        $infos         = [];
        $commentAction = insert_user_comment($comm, is_scalar($params['key']) ? (string) $params['key'] : '', $infos);
        switch ($commentAction) {
            case 'reject':
                $infos[] = l10n('Your comment has NOT been registered because it did not pass the validation rules');
                return new PwgError(403, implode('; ', $infos));
            case 'validate':
            case 'moderate':
                return ['comment' => new PwgNamedStruct(['id' => $comm['id'], 'validation' => $commentAction === 'validate'])];
            default:
                return new PwgError(500, 'Unknown comment action ' . $commentAction);
        }
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function getInfo(array $params, PwgServer $service): PwgError|array
    {
        $pImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $query    = 'SELECT * FROM ' . IMAGES_TABLE . ' WHERE id=' . $pImageId . get_sql_condition_FandF(['visible_images' => 'id'], ' AND') . ' LIMIT 1;';
        $imageRow = ServiceLocator::get(Connection::class)->executeQuery($query)->fetchAssociative();
        if ($imageRow === false) {
            return new PwgError(404, 'image_id not found');
        }
        /** @var array<string, mixed> $imageRow */
        $imageRow      = array_merge($imageRow, ServiceLocator::get(WsHelper::class)->getUrls($imageRow));
        $imageRowId    = is_numeric($imageRow['id']) ? (int) $imageRow['id'] : 0;
        $imageRowFile  = is_scalar($imageRow['file']) ? (string) $imageRow['file'] : '';
        $imageRow['name_raw']    = $imageRow['name'];
        $renderName              = trigger_change('render_element_name', $imageRow['name'], __FUNCTION__);
        $imageRow['name']        = strip_tags(is_scalar($renderName) ? (string) $renderName : '');
        $imageRow['comment_raw'] = $imageRow['comment'];
        $imageRow['comment']     = trigger_change('render_element_description', $imageRow['comment'], __FUNCTION__);
        $isCommentable    = false;
        $relatedCategories = [];
        foreach (ServiceLocator::get(Connection::class)->executeQuery('SELECT id, name, permalink, uppercats, global_rank, commentable FROM ' . IMAGE_CATEGORY_TABLE . ' INNER JOIN ' . CATEGORIES_TABLE . ' ON category_id = id WHERE image_id = ' . $imageRowId . get_sql_condition_FandF(['forbidden_categories' => 'category_id'], ' AND') . ';')->fetchAllAssociative() as $row) {
            if ($row['commentable'] === 'true') {
                $isCommentable = true;
            }
            unset($row['commentable']);
            $row['url']      = make_index_url(['category' => $row]);
            $row['page_url'] = make_picture_url(['image_id' => $imageRowId, 'image_file' => $imageRowFile, 'category' => $row]);
            $row['id']       = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $catNameRaw      = trigger_change('render_category_name', $row['name'], __FUNCTION__);
            $row['name']     = strip_tags(is_scalar($catNameRaw) ? (string) $catNameRaw : '');
            $relatedCategories[] = $row;
        }
        usort($relatedCategories, global_rank_compare(...));
        if (empty($relatedCategories) && !is_admin()) {
            return new PwgError(401, 'Access denied');
        }
        /** @var list<array<string, mixed>> $relatedTags */
        $relatedTags = get_common_tags([$imageRowId], -1);
        foreach ($relatedTags as $i => $tag) {
            $tag['url']      = make_index_url(['tags' => [$tag]]);
            $tag['page_url'] = make_picture_url(['image_id' => $imageRowId, 'image_file' => $imageRowFile, 'tags' => [$tag]]);
            unset($tag['counter']);
            $tag['id']       = is_numeric($tag['id']) ? (int) $tag['id'] : 0;
            $relatedTags[$i] = $tag;
        }
        $rating = ['score' => $imageRow['rating_score'], 'count' => 0, 'average' => null];
        if (isset($rating['score'])) {
            [$rating['count'], $rating['average']] = ServiceLocator::get(RateRepository::class)->findCountAndAvgByElementId($imageRowId);
            $rating['score'] = is_numeric($rating['score']) ? (float) $rating['score'] : 0.0;
        }
        $relatedComments = [];
        $whereComments   = 'image_id = ' . $imageRowId;
        if (!is_admin()) {
            $whereComments .= ' AND validated="true"';
        }
        [$nbComments] = array_column(get_dbal_connection()->executeQuery('SELECT COUNT(id) AS nb_comments FROM ' . COMMENTS_TABLE . ' WHERE ' . $whereComments . ';')->fetchAllAssociative(), 'nb_comments');
        $nbComments          = is_numeric($nbComments) ? (int) $nbComments : 0;
        $pCommentsPerPage    = is_numeric($params['comments_per_page']) ? (int) $params['comments_per_page'] : 0;
        $pCommentsPage       = is_numeric($params['comments_page']) ? (int) $params['comments_page'] : 0;
        if ($nbComments > 0 && $pCommentsPerPage > 0) {
            foreach (ServiceLocator::get(Connection::class)->executeQuery('SELECT id, date, author, content FROM ' . COMMENTS_TABLE . ' WHERE ' . $whereComments . ' ORDER BY date LIMIT ' . $pCommentsPerPage . ' OFFSET ' . ($pCommentsPerPage * $pCommentsPage) . ';')->fetchAllAssociative() as $row) {
                $row['id']         = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $relatedComments[] = $row;
            }
        }
        $commentPostData = null;
        if (Config::activateComments() && $isCommentable && (!is_a_guest() || Config::commentsForall())) {
            $commentPostData['author'] = stripslashes(CurrentUser::get()->username);
            $commentPostData['key']    = get_ephemeral_key(2, (string) $pImageId);
        }
        $ret = $imageRow;
        foreach (['id', 'width', 'height', 'hit', 'filesize'] as $k) {
            if (isset($ret[$k])) {
                $ret[$k] = is_numeric($ret[$k]) ? (int) $ret[$k] : 0;
            }
        }
        unset($ret['path'], $ret['storage_category_id']);
        $ret['rates']    = [WS_XML_ATTRIBUTES => $rating];
        $ret['categories'] = new PwgNamedArray($relatedCategories, 'category', ['id', 'url', 'page_url']);
        $ret['tags']       = new PwgNamedArray($relatedTags, 'tag', ServiceLocator::get(WsHelper::class)->getTagXmlAttributes());
        if (isset($commentPostData)) {
            $ret['comment_post'] = [WS_XML_ATTRIBUTES => $commentPostData];
        }
        $ret['comments_paging'] = new PwgNamedStruct(['page' => $params['comments_page'], 'per_page' => $params['comments_per_page'], 'count' => count($relatedComments), 'total_count' => $nbComments]);
        $ret['comments']        = new PwgNamedArray($relatedComments, 'comment', ['id', 'date']);
        if ($service->getResponseFormat() !== 'rest') {
            return $ret;
        }
        return ['image' => new PwgNamedStruct($ret, null, ['name', 'comment'])];
    }

    /** @param array<mixed> $params */
    public function rate(array $params, PwgServer $service): mixed
    {
        $pImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pRate    = is_numeric($params['rate']) ? (int) $params['rate'] : 0;
        $query    = 'SELECT DISTINCT id FROM ' . IMAGES_TABLE . ' INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id=image_id WHERE id=' . $pImageId . get_sql_condition_FandF(['forbidden_categories' => 'category_id', 'forbidden_images' => 'id'], '    AND') . ' LIMIT 1;';
        if (ServiceLocator::get(Connection::class)->executeQuery($query)->fetchOne() === false) {
            return new PwgError(404, 'Invalid image_id or access denied');
        }
        $res = rate_picture($pImageId, $pRate);
        if ($res === false) {
            return new PwgError(403, 'Forbidden or rate not in ' . implode(',', Config::rateItems()));
        }
        return $res;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function search(array $params, PwgServer $service): array
    {
        $pQuery    = is_scalar($params['query']) ? (string) $params['query'] : '';
        $pPage     = is_numeric($params['page']) ? (int) $params['page'] : 0;
        $pPerPage  = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $images    = [];
        /** @var array<string> $whereClauses */
        $whereClauses = ServiceLocator::get(WsHelper::class)->imageSqlFilter($params, 'i.');
        $orderBy      = ServiceLocator::get(WsHelper::class)->imageSqlOrder($params, 'i.');
        $superOrderBy = false;
        if (!empty($orderBy)) {
            Config::override('order_by', 'ORDER BY ' . $orderBy);
            $superOrderBy = true;
        }
        $searchResult = get_quick_search_results($pQuery, ['super_order_by' => $superOrderBy, 'images_where' => implode(' AND ', $whereClauses)]);
        $searchItems  = is_array($searchResult['items'] ?? null) ? $searchResult['items'] : [];
        $imageIds     = array_slice($searchItems, $pPage * $pPerPage, $pPerPage);
        if (count($imageIds)) {
            $imageIdsInt  = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $imageIds);
            $imageIdsFlip = array_flip($imageIdsInt);
            $favoriteIds  = get_user_favorites();
            foreach (ServiceLocator::get(ImageRepository::class)->findByIds($imageIdsInt) as $row) {
                $image       = [];
                $rowId       = is_scalar($row['id']) ? (string) $row['id'] : '';
                $image['is_favorite'] = $rowId !== '' && isset($favoriteIds[$rowId]);
                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($row[$k])) {
                        $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                    }
                }
                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $row[$k];
                }
                $nameRaw     = trigger_change('render_element_name', $image['name'] ?? '', __FUNCTION__);
                $image['name']    = strip_tags(is_string($nameRaw) ? $nameRaw : (is_scalar($nameRaw) ? (string) $nameRaw : ''));
                $image['comment'] = trigger_change('render_element_description', $image['comment'] ?? null, __FUNCTION__);
                $image = array_merge($image, ServiceLocator::get(WsHelper::class)->getUrls($row));
                $imgIdInt = is_numeric($image['id']) ? (int) $image['id'] : 0;
                if (isset($imageIdsFlip[$imgIdInt])) {
                    $images[$imageIdsFlip[$imgIdInt]] = $image;
                }
            }
            ksort($images, SORT_NUMERIC);
            $images = array_values($images);
        }
        return ['paging' => new PwgNamedStruct(['page' => $pPage, 'per_page' => $pPerPage, 'count' => count($images), 'total_count' => count($searchItems)]), 'images' => new PwgNamedArray($images, 'image', ServiceLocator::get(WsHelper::class)->getImageXmlAttributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function filteredSearchCreate(array $params, PwgServer $service): PwgError|array
    {
        $searchInfo = null;
        if (isset($params['search_id'])) {
            $pSearchId = is_scalar($params['search_id']) ? (string) $params['search_id'] : '';
            if (empty(get_search_id_pattern($pSearchId))) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid search_id input parameter.');
            }
            $searchInfo = get_search_info($pSearchId);
            if (empty($searchInfo)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'This search does not exist.');
            }
        }
        $search = ['mode' => 'AND'];
        if (isset($params['allwords'])) {
            $search['fields']['allwords'] = [];
            if (!isset($params['allwords_mode'])) {
                $params['allwords_mode'] = 'AND';
            }
            $pAllwordsMode = is_scalar($params['allwords_mode']) ? (string) $params['allwords_mode'] : '';
            if (!preg_match('/^(OR|AND)$/', $pAllwordsMode)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter allwords_mode');
            }
            $search['fields']['allwords']['mode'] = $pAllwordsMode;
            $allwordsFieldsAvailable = ['name', 'comment', 'file', 'author', 'tags', 'cat-title', 'cat-desc'];
            if (!isset($params['allwords_fields'])) {
                $params['allwords_fields'] = $allwordsFieldsAvailable;
            }
            $pAllwordsFields = is_array($params['allwords_fields']) ? $params['allwords_fields'] : [];
            foreach ($pAllwordsFields as $field) {
                if (!in_array($field, $allwordsFieldsAvailable)) {
                    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter allwords_fields');
                }
            }
            $search['fields']['allwords']['fields'] = $pAllwordsFields;
            $search['fields']['allwords']['words']  = split_allwords(is_scalar($params['allwords']) ? (string) $params['allwords'] : '');
        }
        if (isset($params['tags'])) {
            $pTags = is_array($params['tags']) ? $params['tags'] : [];
            foreach ($pTags as $tagId) {
                if (!preg_match('/^\d+$/', is_scalar($tagId) ? (string) $tagId : '')) {
                    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter tags');
                }
            }
            if (!isset($params['tags_mode'])) {
                $params['tags_mode'] = 'AND';
            }
            $pTagsMode = is_scalar($params['tags_mode']) ? (string) $params['tags_mode'] : '';
            if (!preg_match('/^(OR|AND)$/', $pTagsMode)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter tags_mode');
            }
            $search['fields']['tags'] = ['words' => $pTags, 'mode' => $pTagsMode];
        }
        if (isset($params['categories'])) {
            $pCategories = is_array($params['categories']) ? $params['categories'] : [];
            foreach ($pCategories as $catId) {
                if (!preg_match('/^\d+$/', is_scalar($catId) ? (string) $catId : '')) {
                    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter categories');
                }
            }
            $search['fields']['cat'] = ['words' => $pCategories, 'sub_inc' => $params['categories_withsubs'] ?? false];
        }
        if (isset($params['authors'])) {
            $authors   = [];
            $pAuthors  = is_array($params['authors']) ? $params['authors'] : [];
            foreach ($pAuthors as $author) {
                $authors[] = strip_tags(is_scalar($author) ? (string) $author : '');
            }
            $search['fields']['author'] = ['words' => $authors, 'mode' => 'OR'];
        }
        if (isset($params['filetypes'])) {
            $pFiletypes = is_array($params['filetypes']) ? $params['filetypes'] : [];
            foreach ($pFiletypes as $ext) {
                if (!preg_match('/^[a-z0-9]+$/i', is_scalar($ext) ? (string) $ext : '')) {
                    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter filetypes');
                }
            }
            $search['fields']['filetypes'] = $pFiletypes;
        }
        if (isset($params['added_by'])) {
            $pAddedBy = is_array($params['added_by']) ? $params['added_by'] : [];
            foreach ($pAddedBy as $userId) {
                if (!preg_match('/^\d+$/', is_scalar($userId) ? (string) $userId : '')) {
                    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter added_by');
                }
            }
            $search['fields']['added_by'] = $pAddedBy;
        }
        foreach (['date_posted_preset', 'date_created_preset'] as $presetParam) {
            if (isset($params[$presetParam])) {
                $pPreset    = is_scalar($params[$presetParam]) ? (string) $params[$presetParam] : '';
                $validPres  = $presetParam === 'date_posted_preset' ? '/^(24h|7d|30d|3m|6m|custom|)$/' : '/^(7d|30d|3m|6m|12m|custom|)$/';
                if (!preg_match($validPres, $pPreset)) {
                    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter ' . $presetParam);
                }
                $fieldKey = $presetParam === 'date_posted_preset' ? 'date_posted' : 'date_created';
                $search['fields'][$fieldKey]['preset'] = $pPreset;
            }
        }
        foreach (['date_posted_custom', 'date_created_custom'] as $customParam) {
            if (isset($params[$customParam])) {
                $fieldKey = $customParam === 'date_posted_custom' ? 'date_posted' : 'date_created';
                $pCustom  = is_array($params[$customParam]) ? $params[$customParam] : [];
                foreach ($pCustom as $date) {
                    $dateStr        = is_scalar($date) ? (string) $date : '';
                    $correctFormat  = false;
                    $ymd            = substr($dateStr, 0, 1);
                    if ($ymd === 'y' && preg_match('/^y(\d{4})$/', $dateStr)) {
                        $correctFormat = true;
                    } elseif ($ymd === 'm' && preg_match('/^m(\d{4}-\d{2})$/', $dateStr, $m)) {
                        [$year, $month] = explode('-', $m[1]);
                        if ($month >= 1 && $month <= 12) {
                            $correctFormat = true;
                        }
                    } elseif ($ymd === 'd' && preg_match('/^d(\d{4}-\d{2}-\d{2})$/', $dateStr, $m)) {
                        [$year, $month, $day] = explode('-', $m[1]);
                        if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year)) {
                            $correctFormat = true;
                        }
                    }
                    if (!$correctFormat) {
                        return new PwgError(WS_ERR_INVALID_PARAM, $customParam . ', invalid option ' . $dateStr);
                    }
                    $search['fields'][$fieldKey]['custom'][] = $dateStr;
                }
            }
        }
        foreach (['ratios', 'ratings', 'filesize_min', 'filesize_max', 'width_min', 'width_max', 'height_min', 'height_max', 'expert'] as $field) {
            if (isset($params[$field])) {
                if ($field === 'ratios') {
                    $pRatios = is_array($params[$field]) ? $params[$field] : [];
                    foreach ($pRatios as $ext) {
                        if (!preg_match('/^[a-z0-9]+$/i', is_scalar($ext) ? (string) $ext : '')) {
                            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid parameter ratios');
                        }
                    }
                    $search['fields']['ratios'] = $pRatios;
                } elseif ($field === 'expert') {
                    $search['fields']['expert'] = ['string' => $params[$field]];
                } elseif ($field === 'ratings' && Config::rateEnabled()) {
                    $search['fields']['ratings'] = $params[$field];
                } else {
                    $search['fields'][$field] = $params[$field];
                }
            }
        }
        $forkedFrom = isset($searchInfo['id']) && is_scalar($searchInfo['id']) ? (string) $searchInfo['id'] : null;
        [$searchUuid, $searchUrl] = save_search($search, $forkedFrom);
        return ['search_id' => $searchUuid, 'search_url' => $searchUrl];
    }

    /** @param array<mixed> $params */
    public function setPrivacyLevel(array $params, PwgServer $service): mixed
    {
        if (!in_array($params['level'], Config::availablePermissionLevels())) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
        }
        $pLevel    = is_numeric($params['level']) ? (int) $params['level'] : 0;
        $pImageIds = is_array($params['image_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
        $affected  = ServiceLocator::get(ImageRepository::class)->setLevelForIds($pLevel, $pImageIds);
        pwg_activity('photo', $pImageIds, 'edit');
        if ($affected) {
            ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
        }
        return $affected;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function setRank(array $params, PwgServer $service): array|PwgError
    {
        $pImageIdArr   = is_array($params['image_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
        $pCategoryId   = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        if (count($pImageIdArr) > 1) {
            ServiceLocator::get(CategoryAdminService::class)->saveImagesOrder($pCategoryId, $pImageIdArr);
            $imageIds = array_column(get_dbal_connection()->executeQuery('SELECT image_id FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id = ' . $pCategoryId . ' ORDER BY `rank` ASC;')->fetchAllAssociative(), 'image_id');
            return ['image_id' => $imageIds, 'category_id' => $pCategoryId];
        }
        $pImageId = $pImageIdArr[0] ?? 0;
        if (empty($params['rank'])) {
            return new PwgError(WS_ERR_MISSING_PARAM, 'rank is missing');
        }
        $catRepo = ServiceLocator::get(CategoryRepository::class);
        if (!ServiceLocator::get(ImageRepository::class)->existsById($pImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        if (!$catRepo->hasImageInCategory($pImageId, $pCategoryId)) {
            return new PwgError(404, 'This image is not associated to this category');
        }
        $pRank    = is_numeric($params['rank']) ? (int) $params['rank'] : 1;
        $maxRank  = $catRepo->findMaxRankInCategory($pCategoryId);
        if ($maxRank !== null) {
            if ($pRank > $maxRank) {
                $pRank = $maxRank + 1;
            }
        } else {
            $pRank = 1;
        }
        $catRepo->incrementRanksFrom($pCategoryId, $pRank);
        $catRepo->setImageRank($pImageId, $pCategoryId, $pRank);
        return ['image_id' => $pImageId, 'category_id' => $pCategoryId, 'rank' => $pRank];
    }

    /** @param array<mixed> $params */
    public function addChunk(array $params, PwgServer $service): mixed
    {
        $logger    = LoggerRegistry::current();
        $uploadDir = Config::uploadDir() . '/buffer';
        if (!mkgetdir($uploadDir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }
        $pOriginalSum = is_scalar($params['original_sum']) ? (string) $params['original_sum'] : '';
        $pType        = is_scalar($params['type']) ? (string) $params['type'] : '';
        $pPosition    = is_numeric($params['position']) ? (int) $params['position'] : 0;
        $pData        = is_scalar($params['data']) ? (string) $params['data'] : '';
        $filename     = sprintf('%s-%s-%05u.block', $pOriginalSum, $pType, $pPosition);
        $logger->debug('[addChunk] data length : ' . strlen($pData));
        $bytesWritten = file_put_contents($uploadDir . '/' . $filename, base64_decode($pData));
        if ($bytesWritten === false) {
            return new PwgError(500, 'an error has occured while writting chunk ' . $pPosition . ' for ' . $pType);
        }
        return null;
    }

    /** @param array<mixed> $params */
    public function addFile(array $params, PwgServer $service): mixed
    {
        $logger      = LoggerRegistry::current();
        $logger->debug(__FUNCTION__, $params);
        $pImageId    = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pTypeAf     = is_scalar($params['type']) ? (string) $params['type'] : '';
        $image       = ServiceLocator::get(ImageRepository::class)->findById($pImageId);
        if ($image === null) {
            return new PwgError(404, 'image_id not found');
        }
        $imageMd5sum = is_scalar($image['md5sum']) ? (string) $image['md5sum'] : '';
        $imageFile   = is_scalar($image['file']) ? (string) $image['file'] : '';
        if ($pTypeAf === 'thumb') {
            $this->removeChunks($imageMd5sum, $pTypeAf);
            return true;
        }
        $originalType = $pTypeAf === 'high' ? 'high' : 'file';
        $filePath     = Config::uploadDir() . '/buffer/' . $imageMd5sum . '-original';
        $this->mergeChunks($filePath, $imageMd5sum, $originalType);
        chmod($filePath, 0644);
        if ($pTypeAf === 'file') {
            $infos = ServiceLocator::get(UploadService::class)->pwgImageInfos($filePath);
            $doUpdate = false;
            foreach (['width', 'height', 'filesize'] as $imageInfo) {
                if ($infos[$imageInfo] > $image[$imageInfo]) {
                    $doUpdate = true;
                }
            }
            if (!$doUpdate) {
                unlink($filePath);
                return true;
            }
        }
        ServiceLocator::get(UploadService::class)->addUploadedFile($filePath, $imageFile, null, null, $pImageId, $imageMd5sum);
        return null;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function add(array $params, PwgServer $service): PwgError|array
    {
        $logger = LoggerRegistry::current();
        $pImageId        = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pOriginalSum    = is_scalar($params['original_sum']) ? (string) $params['original_sum'] : '';
        $pOriginalFilename = is_scalar($params['original_filename']) ? (string) $params['original_filename'] : null;
        $pLevel          = isset($params['level']) && is_numeric($params['level']) ? (int) $params['level'] : null;
        if ($pImageId > 0 && !ServiceLocator::get(ImageRepository::class)->existsById($pImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        if ($params['check_uniqueness']) {
            $whereClause = '1=1';
            if (Config::uniquenessMode() === 'md5sum') {
                $whereClause = "md5sum = '" . $pOriginalSum . "'";
            }
            if (Config::uniquenessMode() === 'filename') {
                $whereClause = "file = '" . (is_scalar($params['original_filename']) ? (string) $params['original_filename'] : '') . "'";
            }
            $counter = ServiceLocator::get(Connection::class)->executeQuery('SELECT COUNT(*) FROM ' . IMAGES_TABLE . ' WHERE ' . $whereClause)->fetchOne();
            if ((is_numeric($counter) ? (int) $counter : 0) !== 0) {
                return new PwgError(500, 'file already exists');
            }
        }
        $this->removeChunks($pOriginalSum, 'thumb');
        if (isset($params['high_sum'])) {
            $originalType = 'high';
            $this->removeChunks($pOriginalSum, 'file');
        } else {
            $originalType = 'file';
        }
        $filePath = Config::uploadDir() . '/buffer/' . $pOriginalSum . '-original';
        $this->mergeChunks($filePath, $pOriginalSum, $originalType);
        chmod($filePath, 0644);
        $imageId = ServiceLocator::get(UploadService::class)->addUploadedFile($filePath, $pOriginalFilename, null, $pLevel, $pImageId > 0 ? $pImageId : null, $pOriginalSum);
        $update  = [];
        foreach (['name', 'author', 'comment', 'date_creation'] as $key) {
            if (isset($params[$key])) {
                $update[$key] = $params[$key];
            }
        }
        if (count($update) > 0) {
            single_update(IMAGES_TABLE, $update, ['id' => $imageId]);
        }
        $urlParams = ['image_id' => $imageId];
        if (isset($params['categories'])) {
            $pCategoriesStr = is_scalar($params['categories']) ? (string) $params['categories'] : '';
            $this->addImageCategoryRelations($imageId, $pCategoriesStr);
            if (preg_match('/^\d+/', $pCategoriesStr, $matches)) {
                $category              = ServiceLocator::get(CategoryRepository::class)->findCategoryById((int) $matches[0]);
                $urlParams['section']  = 'categories';
                $urlParams['category'] = $category;
            }
        }
        if (isset($params['tag_ids']) && !empty($params['tag_ids'])) {
            ServiceLocator::get(TagAdminService::class)->setTags(explode(',', is_scalar($params['tag_ids']) ? (string) $params['tag_ids'] : ''), $imageId);
        }
        ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
        return ['image_id' => $imageId, 'url' => make_picture_url($urlParams)];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function addSimple(array $params, PwgServer $service): PwgError|array
    {
        $logger = LoggerRegistry::current();
        if (!isset($_FILES['image'])) {
            return new PwgError(405, 'The image (file) is missing');
        }
        $filesImage      = is_array($_FILES['image']) ? $_FILES['image'] : [];
        $filesImageError = is_numeric($filesImage['error']) ? (int) $filesImage['error'] : 0;
        if (isset($filesImage['error']) && $filesImageError !== 0) {
            $message = match ($filesImageError) {
                UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
                UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
                default               => "Error number {$filesImageError} occurred while uploading.",
            };
            $logger->error(__FUNCTION__ . ' ' . $message);
            return new PwgError(500, $message);
        }
        $pImageIdAs  = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pCategoryAs = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['category']) ? $params['category'] : []);
        if ($pImageIdAs > 0 && !ServiceLocator::get(ImageRepository::class)->existsById($pImageIdAs)) {
            return new PwgError(404, 'image_id not found');
        }
        $filesTmp  = is_scalar($filesImage['tmp_name']) ? (string) $filesImage['tmp_name'] : '';
        $filesName = is_scalar($filesImage['name']) ? (string) $filesImage['name'] : null;
        $imageId   = ServiceLocator::get(UploadService::class)->addUploadedFile($filesTmp, $filesName, $pCategoryAs, 8, $pImageIdAs > 0 ? $pImageIdAs : null);
        $update    = [];
        foreach (['name', 'author', 'comment', 'level', 'date_creation'] as $key) {
            if (isset($params[$key])) {
                $update[$key] = $params[$key];
            }
        }
        single_update(IMAGES_TABLE, $update, ['id' => $imageId]);
        if (isset($params['tags']) && !empty($params['tags'])) {
            $tagIds = [];
            if (is_array($params['tags'])) {
                foreach ($params['tags'] as $tagName) {
                    $tagIds[] = tag_id_from_tag_name(is_scalar($tagName) ? (string) $tagName : '');
                }
            } else {
                $tagNames = preg_split('~(?<!\\\),~', is_scalar($params['tags']) ? (string) $params['tags'] : '') ?: [];
                foreach ($tagNames as $tagName) {
                    $tagIds[] = ServiceLocator::get(TagAdminService::class)->tagIdFromTagName(preg_replace('#\\\\*,#', ',', $tagName) ?? '');
                }
            }
            ServiceLocator::get(TagAdminService::class)->addTags($tagIds, [$imageId]);
        }
        $urlParams = ['image_id' => $imageId];
        if (!empty($pCategoryAs)) {
            $firstCatId  = $pCategoryAs[0] ?? 0;
            $category    = ServiceLocator::get(CategoryRepository::class)->findCategoryById($firstCatId);
            $urlParams['section']  = 'categories';
            $urlParams['category'] = $category;
        }
        ServiceLocator::get(MetadataAdminService::class)->syncMetadata([$imageId]);
        return ['image_id' => $imageId, 'url' => make_picture_url($urlParams)];
    }

    /** @param array<mixed> $params */
    public function upload(array $params, PwgServer $service): mixed
    {
        $formatExt = null;
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (isset($params['format_of'])) {
            if (!Config::isFormatsEnabled()) {
                return new PwgError(401, 'formats are disabled');
            }
            $pName = is_scalar($params['name']) ? (string) $params['name'] : '';
            if (preg_match('/\.(' . implode('|', Config::formatExtensions()) . ')$/', $pName, $matches)) {
                $formatExt = $matches[1];
            }
            if (empty($formatExt)) {
                return new PwgError(401, 'unexpected format extension of file "' . $pName . '"');
            }
        }
        $uploadDir = Config::uploadDir() . '/buffer';
        if (!mkgetdir($uploadDir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }
        if (isset($_REQUEST['name'])) {
            $fileName = is_scalar($_REQUEST['name']) ? (string) $_REQUEST['name'] : uniqid('file_');
        } elseif (!empty($_FILES)) {
            $filesFile = is_array($_FILES['file']) ? $_FILES['file'] : [];
            $fileName  = is_scalar($filesFile['name'] ?? null) ? (string) $filesFile['name'] : uniqid('file_');
        } else {
            $fileName = uniqid('file_');
        }
        $fileName = md5($fileName);
        $filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
        $chunk    = isset($_REQUEST['chunk']) ? (is_numeric($_REQUEST['chunk']) ? (int) $_REQUEST['chunk'] : 0) : 0;
        $chunks   = isset($_REQUEST['chunks']) ? (is_numeric($_REQUEST['chunks']) ? (int) $_REQUEST['chunks'] : 0) : 0;
        if (!$out = Filesystem::tryFopen("{$filePath}.part", $chunks ? 'ab' : 'wb')) {
            return new PwgError(102, 'Failed to open output stream.');
        }
        if (!empty($_FILES)) {
            $filesFile     = is_array($_FILES['file']) ? $_FILES['file'] : [];
            $filesFileError = is_scalar($filesFile['error'] ?? null) ? $filesFile['error'] : 0;
            $filesFileTmpName = is_scalar($filesFile['tmp_name'] ?? null) ? (string) $filesFile['tmp_name'] : '';
            if ($filesFileError || !is_uploaded_file($filesFileTmpName)) {
                return new PwgError(103, 'Failed to move uploaded file.');
            }
            if (!$in = Filesystem::tryFopen($filesFileTmpName, 'rb')) {
                return new PwgError(101, 'Failed to open input stream.');
            }
        } else {
            if (!$in = Filesystem::tryFopen('php://input', 'rb')) {
                return new PwgError(101, 'Failed to open input stream.');
            }
        }
        if (is_resource($in) && is_resource($out)) {
            while ($buff = fread($in, 4096)) {
                fwrite($out, $buff);
            }
        }
        if (is_resource($out)) {
            fclose($out);
        }
        if (is_resource($in)) {
            fclose($in);
        }
        $addStatus = 'add';
        if (!$chunks || $chunk === $chunks - 1) {
            rename("{$filePath}.part", $filePath);
            if (isset($params['format_of'])) {
                $formatOfId = is_numeric($params['format_of']) ? (int) $params['format_of'] : 0;
                $images     = get_dbal_connection()->executeQuery('SELECT * FROM ' . IMAGES_TABLE . ' WHERE id = ' . $formatOfId . ';')->fetchAllAssociative();
                if (count($images) === 0) {
                    return new PwgError(404, __FUNCTION__ . ' : image_id not found');
                }
                $image      = $images[0];
                $imageIdStr = isset($image['id']) ? (is_scalar($image['id']) ? (string) $image['id'] : '') : '';
                $addStatus  = ServiceLocator::get(UploadService::class)->addFormat($filePath, $formatExt ?? '', $imageIdStr);
                return ['image_id' => $image['id'] ?? null, 'src' => DerivativeImage::thumb_url($image), 'square_src' => DerivativeImage::url(ImageStdParams::get_by_type(IMG_SQUARE), $image), 'name' => $image['name'] ?? null, 'add_status' => $addStatus];
            }
            $name          = stripslashes(is_scalar($params['name']) ? (string) $params['name'] : '');
            $idImage       = null;
            $pCategory     = is_array($params['category']) ? $params['category'] : [];
            $pCategoryInt  = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $pCategory);
            $pCategoryFirst = $pCategoryInt[0] ?? 0;
            if ($params['update_mode']) {
                $images = get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ' AS i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' as ic ON ic.image_id = i.id WHERE i.file = ' . get_dbal_connection()->quote($name) . ' AND ic.category_id = ' . $pCategoryFirst . ';')->fetchAllAssociative();
                if ($images != null) {
                    $img0      = $images[0];
                    $idImage   = isset($img0['id']) && is_numeric($img0['id']) ? (int) $img0['id'] : null;
                    $addStatus = 'update';
                }
            }
            $imageId = ServiceLocator::get(UploadService::class)->addUploadedFile($filePath, $name, $pCategoryInt, is_numeric($params['level']) ? (int) $params['level'] : null, $idImage);
            $catRepo2   = ServiceLocator::get(CategoryRepository::class);
            $imageInfos = ServiceLocator::get(ImageRepository::class)->findById($imageId);
            $categoryInfos = ['nb_photos' => $catRepo2->countImagesByCategoryId($pCategoryFirst)];
            $nbPhotosLounge = ServiceLocator::get(Connection::class)->executeQuery('SELECT COUNT(*) FROM ' . LOUNGE_TABLE . ' WHERE category_id = ? AND image_id NOT IN (SELECT image_id FROM ' . IMAGE_CATEGORY_TABLE . ')', [$pCategoryFirst])->fetchOne();
            $categoryName   = get_cat_display_name_from_id($pCategoryFirst, null);
            if ($imageInfos === null) {
                return null;
            }
            return ['image_id' => $imageId, 'src' => DerivativeImage::thumb_url($imageInfos), 'square_src' => DerivativeImage::url(ImageStdParams::get_by_type(IMG_SQUARE), $imageInfos), 'name' => $imageInfos['name'], 'category' => ['id' => $pCategoryFirst, 'nb_photos' => (int) $categoryInfos['nb_photos'] + (is_numeric($nbPhotosLounge) ? (int) $nbPhotosLounge : 0), 'label' => $categoryName], 'add_status' => $addStatus];
        }
        return null;
    }

    /** @param array<mixed> $params */
    public function uploadAsync(array $params, PwgServer &$service): mixed
    {
        $logger = LoggerRegistry::current();
        $pOriginalSum = is_scalar($params['original_sum']) ? (string) $params['original_sum'] : '';
        if (!preg_match('/^[a-fA-F0-9]{32}$/', $pOriginalSum)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid original_sum');
        }
        $pImageIdAsync = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pChunk        = is_numeric($params['chunk']) ? (int) $params['chunk'] : 0;
        $pChunks       = is_numeric($params['chunks']) ? (int) $params['chunks'] : 0;
        if ($pImageIdAsync > 0 && !ServiceLocator::get(ImageRepository::class)->existsById($pImageIdAsync)) {
            return new PwgError(404, __FUNCTION__ . ' : image_id not found');
        }
        $pUserId = (string) CurrentUser::get()->id;
        $outputFilepathPrefix  = Config::uploadDir() . '/buffer/' . $pOriginalSum . '-u' . $pUserId;
        $chunkfilePathPattern  = $outputFilepathPrefix . '-%03uof%03u.chunk';
        $chunkfilePath         = sprintf($chunkfilePathPattern, $pChunk + 1, $pChunks);
        if (!mkgetdir(dirname($chunkfilePath), MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }
        secure_directory(dirname($chunkfilePath));
        $filesFile2        = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
        $filesFile2TmpName = is_scalar($filesFile2['tmp_name'] ?? null) ? (string) $filesFile2['tmp_name'] : '';
        $chunkRoot     = PHPWG_ROOT_PATH . Config::uploadDir();
        $chunkAbsPath  = PHPWG_ROOT_PATH . ltrim(str_replace(['\\', '/./'], ['/', '/'], $chunkfilePath), '/');
        $chunkRelPath  = StorageRegistry::stripRoot($chunkRoot, $chunkAbsPath);
        $chunkStream   = fopen($filesFile2TmpName, 'rb');
        if ($chunkStream !== false) {
            StorageRegistry::disk('uploads')->writeStream($chunkRelPath, $chunkStream);
            fclose($chunkStream);
        }
        $logger->debug(__FUNCTION__ . ' uploaded ' . $chunkfilePath);
        $chunkMd5  = md5_file($chunkfilePath);
        $pChunkSum = is_scalar($params['chunk_sum']) ? (string) $params['chunk_sum'] : '';
        if ($chunkMd5 !== $pChunkSum) {
            unlink($chunkfilePath);
            $logger->error(__FUNCTION__ . ' ' . $chunkfilePath . ' MD5 checksum mismatched');
            return new PwgError(500, 'MD5 checksum chunk file mismatched');
        }
        $chunkIdsUploaded = [];
        for ($i = 1; $i <= $pChunks; $i++) {
            $chunkfile = sprintf($chunkfilePathPattern, $i, $pChunks);
            if (file_exists($chunkfile) && ($fp = fopen($chunkfile, 'rb')) !== false) {
                $chunkIdsUploaded[] = $i;
                fclose($fp);
            }
        }
        if ($pChunks !== count($chunkIdsUploaded)) {
            $logger->debug(__FUNCTION__ . ' all chunks are not uploaded yet, exit for now');
            return ['message' => 'chunks uploaded = ' . implode(',', $chunkIdsUploaded)];
        }
        $logger->debug(__FUNCTION__ . ' ' . $pOriginalSum . ' ' . $pChunks . ' chunks available, try now to get lock');
        $outputFilepath = $outputFilepathPrefix . '.merged';
        if (file_exists($outputFilepath) && ($fp = fopen($outputFilepath, 'rb')) !== false) {
            fclose($fp);
            $logger->error(__FUNCTION__ . ' ' . $outputFilepath . ' already exists');
            return ['message' => 'chunks uploaded = ' . implode(',', $chunkIdsUploaded)];
        }
        $fp = fopen($outputFilepath, 'wb');
        if (!$fp) {
            $logger->error(__FUNCTION__ . ' unable to create merge file');
            return new PwgError(500, 'error while creating merged ' . $chunkfilePath);
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            $logger->error(__FUNCTION__ . ' unable to obtain lock');
            return new PwgError(500, 'error while locking merged ' . $chunkfilePath);
        }
        $logger->debug(__FUNCTION__ . ' lock obtained to merge chunks');
        foreach ($chunkIdsUploaded as $chunkId) {
            $chunkfilePath = sprintf($chunkfilePathPattern, $chunkId, is_numeric($params['chunks']) ? (int) $params['chunks'] : 0);
            if (!file_exists($chunkfilePath)) {
                $logger->error(__FUNCTION__ . ' ' . $chunkfilePath . ' already merged');
                flock($fp, LOCK_UN);
                fclose($fp);
                return ['message' => 'chunks uploaded = ' . implode(',', $chunkIdsUploaded)];
            }
            $chunkdata = file_get_contents($chunkfilePath);
            if ($chunkdata === false || !fwrite($fp, $chunkdata)) {
                $logger->error(__FUNCTION__ . ' error merging chunk ' . $chunkfilePath);
                flock($fp, LOCK_UN);
                fclose($fp);
                Filesystem::tryUnlink($outputFilepath);
                return new PwgError(500, 'error while merging chunk ' . $chunkId);
            }
            $logger->debug(__FUNCTION__ . ' original_sum=' . $pOriginalSum . ', chunk ' . $chunkId . '/' . $pChunks . ' merged');
            unlink($chunkfilePath);
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $logger->debug(__FUNCTION__ . ' merged file ' . $outputFilepath . ' saved');
        $mergedMd5 = md5_file($outputFilepath);
        if ($mergedMd5 !== $pOriginalSum) {
            unlink($outputFilepath);
            $logger->error(__FUNCTION__ . ' ' . $outputFilepath . ' MD5 checksum mismatched!');
            return new PwgError(500, 'MD5 checksum merged file mismatched');
        }
        $logger->debug(__FUNCTION__ . ' ' . $outputFilepath . ' MD5 checksum OK');
        $pFilename       = is_scalar($params['filename']) ? (string) $params['filename'] : null;
        $pCategoryAsync  = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['category']) ? $params['category'] : []);
        $pLevelAsync     = is_numeric($params['level']) ? (int) $params['level'] : null;
        $pImageIdUpload  = is_numeric($params['image_id']) ? (int) $params['image_id'] : null;
        $imageId         = ServiceLocator::get(UploadService::class)->addUploadedFile($outputFilepath, $pFilename, $pCategoryAsync, $pLevelAsync, $pImageIdUpload, $pOriginalSum);
        $logger->debug(__FUNCTION__ . ' image_id after add_uploaded_file = ' . $imageId);
        if (isset($params['tag_ids']) && !empty($params['tag_ids'])) {
            ServiceLocator::get(TagAdminService::class)->setTags(explode(',', is_scalar($params['tag_ids']) ? (string) $params['tag_ids'] : ''), $imageId);
        }
        $update = [];
        foreach (['name', 'author', 'comment', 'date_creation'] as $key) {
            if (isset($params[$key])) {
                $update[$key] = $params[$key];
            }
        }
        if (count($update) > 0) {
            single_update(IMAGES_TABLE, $update, ['id' => $imageId]);
        }
        ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
        $userRef = &$GLOBALS['user'];
        if (is_array($userRef) && !empty($params['level']) && $params['level'] > ($userRef['level'] ?? 0)) {
            $userRef['level'] = $params['level'];
        }
        $now = time();
        foreach (glob(Config::uploadDir() . '/buffer/' . '*.chunk') ?: [] as $file) {
            if (is_file($file) && $now - filemtime($file) >= 60 * 60 * 24 * 7) {
                unlink($file);
            }
        }
        foreach (glob(Config::uploadDir() . '/buffer/' . '*.merged') ?: [] as $file) {
            if (is_file($file) && $now - filemtime($file) >= 60 * 60 * 24 * 7) {
                unlink($file);
            }
        }
        return $service->invoke('pwg.images.getInfo', ['image_id' => $imageId]);
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function exist(array $params, PwgServer $service): array
    {
        $splitPattern = '/[\s,;\|]/';
        $result       = [];
        if (Config::uniquenessMode() === 'md5sum') {
            $md5sums  = preg_split($splitPattern, is_scalar($params['md5sum_list']) ? (string) $params['md5sum_list'] : '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $idOfMd5  = array_column(get_dbal_connection()->executeQuery('SELECT id, md5sum FROM ' . IMAGES_TABLE . " WHERE md5sum IN ('" . implode("','", $md5sums) . "');")-> fetchAllAssociative(), 'id', 'md5sum');
            foreach ($md5sums as $md5sum) {
                $result[$md5sum] = $idOfMd5[$md5sum] ?? null;
            }
        } elseif (Config::uniquenessMode() === 'filename') {
            $filenames = preg_split($splitPattern, is_scalar($params['filename_list']) ? (string) $params['filename_list'] : '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $idOfFile  = array_column(get_dbal_connection()->executeQuery('SELECT id, file FROM ' . IMAGES_TABLE . " WHERE file IN ('" . implode("','", $filenames) . "');")-> fetchAllAssociative(), 'id', 'file');
            foreach ($filenames as $filename) {
                $result[$filename] = $idOfFile[$filename] ?? null;
            }
        }
        return $result;
    }

    /** @param array<mixed> $params */
    public function formatsSearchImage(array $params, PwgServer $service): mixed
    {
        $candidates = json_decode(stripslashes(is_scalar($params['filename_list']) ? (string) $params['filename_list'] : ''), true);
        $uniqueFilenamesDb = [];
        foreach (ServiceLocator::get(ImageRepository::class)->findAllIdFilename() as $row) {
            $filenameWoExt = get_filename_wo_extension(is_scalar($row['file']) ? (string) $row['file'] : '');
            $uniqueFilenamesDb[$filenameWoExt][] = $row['id'];
        }
        $formatExtensions = Config::formatExtensions();
        usort($formatExtensions, fn (mixed $a, mixed $b): int => strlen((string) $b) - strlen((string) $a));
        /** @var array<string, list<string>> $formatDb */
        $formatDb = [];
        foreach (ServiceLocator::get(ImageRepository::class)->findAllFormats() as $row) {
            $fmtImageId = is_scalar($row['image_id'] ?? null) ? (string) $row['image_id'] : '';
            $fmtExtVal  = is_scalar($row['ext'] ?? null) ? (string) $row['ext'] : '';
            $formatDb[$fmtImageId][] = $fmtExtVal;
        }
        $result        = [];
        $candidatesArr = is_array($candidates) ? $candidates : [];
        foreach ($candidatesArr as $formatExternalId => $formatFilename) {
            $fmtExternalIdStr  = (string) $formatExternalId;
            $fmtFilenameStr    = is_scalar($formatFilename) ? (string) $formatFilename : '';
            $candidateFilenameWoExt = null;
            if (preg_match('/^(.*?)\.(' . implode('|', Config::formatExtensions()) . ')$/', $fmtFilenameStr, $matches)) {
                $candidateFilenameWoExt = $matches[1];
            }
            if (empty($candidateFilenameWoExt)) {
                $result[$fmtExternalIdStr] = ['status' => 'not found'];
                continue;
            }
            if (isset($uniqueFilenamesDb[$candidateFilenameWoExt])) {
                if (count($uniqueFilenamesDb[$candidateFilenameWoExt]) > 1) {
                    $result[$fmtExternalIdStr] = ['status' => 'multiple'];
                    continue;
                }
                $imgIdRaw  = $uniqueFilenamesDb[$candidateFilenameWoExt][0];
                $imgIdStr  = is_scalar($imgIdRaw) ? (string) $imgIdRaw : '';
                $multForm  = false;
                if (isset($formatDb[$imgIdStr])) {
                    $fmtExt = pathinfo($fmtFilenameStr, PATHINFO_EXTENSION);
                    if (array_search($fmtExt, $formatDb[$imgIdStr]) !== false) {
                        $multForm = true;
                    }
                }
                $result[$fmtExternalIdStr] = ['status' => 'found', 'image_id' => $imgIdRaw, 'format_exist' => $multForm];
                continue;
            }
            $result[$fmtExternalIdStr] = ['status' => 'not found'];
        }
        return $result;
    }

    /** @param array<mixed> $params */
    public function formatsDelete(array $params, PwgServer $service): PwgError|bool
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!is_array($params['format_id'])) {
            $params['format_id'] = preg_split('/[\s,;\|]/', is_scalar($params['format_id']) ? (string) $params['format_id'] : '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $params['format_id'] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['format_id']);
        $formatIds = array_filter($params['format_id'], fn (int $v): bool => $v >= 0);
        $ok        = true;
        $imgRepo   = ServiceLocator::get(ImageRepository::class);
        /** @var array<string, list<string>> $formatsOf */
        $formatsOf = [];
        /** @var list<string> $imageIds */
        $imageIds  = [];
        foreach ($imgRepo->findFormatsByFormatIds(array_map(intval(...), $formatIds)) as $row) {
            $rowImageId = is_scalar($row['image_id'] ?? null) ? (string) $row['image_id'] : '';
            $rowExt     = is_scalar($row['ext'] ?? null) ? (string) $row['ext'] : '';
            if (!isset($formatsOf[$rowImageId])) {
                $imageIds[] = $rowImageId;
                $formatsOf[$rowImageId] = [];
            }
            $formatsOf[$rowImageId][] = $rowExt;
        }
        if (count($imageIds) === 0) {
            return new PwgError(404, 'No format found for the id(s) given');
        }
        foreach ($imgRepo->findByIds(array_map(intval(...), $imageIds)) as $row) {
            $rowPath = is_scalar($row['path'] ?? null) ? (string) $row['path'] : '';
            $rowId   = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
            if (url_is_remote($rowPath)) {
                continue;
            }
            $imagePath = get_element_path($row);
            $files     = [];
            if (isset($formatsOf[$rowId])) {
                foreach ($formatsOf[$rowId] as $formatExt) {
                    $files[] = original_to_format($imagePath, $formatExt);
                }
            }
            foreach ($files as $path) {
                if (is_file($path) && !unlink($path)) {
                    $ok = false;
                    trigger_error('"' . $path . '" cannot be removed', E_USER_WARNING);
                    break;
                }
            }
        }
        $imgRepo->deleteFormatsByFormatIds(array_map(intval(...), $formatIds));
        ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
        return $ok;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function checkFiles(array $params, PwgServer $service): PwgError|array
    {
        $checkImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $path         = ServiceLocator::get(ImageRepository::class)->findPathById($checkImageId);
        if ($path === null) {
            return new PwgError(404, 'image_id not found');
        }
        $ret = [];
        if (isset($params['thumbnail_sum'])) {
            $ret['thumbnail'] = 'equals';
        }
        $compareType = null;
        if (isset($params['high_sum'])) {
            $ret['file']  = 'equals';
            $compareType  = 'high';
        } elseif (isset($params['file_sum'])) {
            $compareType = 'file';
        }
        if ($compareType !== null) {
            $ret[$compareType] = md5_file($path) !== $params[$compareType . '_sum'] ? 'differs' : 'equals';
        }
        return $ret;
    }

    /** @param array<mixed> $params */
    public function setInfo(array $params, PwgServer $service): mixed
    {
        if (isset($params['pwg_token']) && get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $setImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $imageRow   = ServiceLocator::get(ImageRepository::class)->findById($setImageId);
        if ($imageRow === null) {
            return new PwgError(404, 'image_id not found');
        }
        $update              = [];
        $singleValueMode     = is_scalar($params['single_value_mode'] ?? null) ? (string) $params['single_value_mode'] : '';
        $multipleValueMode   = is_scalar($params['multiple_value_mode'] ?? null) ? (string) $params['multiple_value_mode'] : '';
        foreach (['name', 'author', 'comment', 'level', 'date_creation'] as $key) {
            if (isset($params[$key])) {
                if (!Config::allowHtmlDescriptions() || !isset($params['pwg_token'])) {
                    $params[$key] = strip_tags(is_scalar($params[$key]) ? (string) $params[$key] : '', '<b><strong><em><i>');
                }
                if ($singleValueMode === 'fill_if_empty') {
                    if (empty($imageRow[$key])) {
                        $update[$key] = $params[$key];
                    }
                } elseif ($singleValueMode === 'replace') {
                    $update[$key] = $params[$key];
                } else {
                    return new PwgError(500, '[ws_images_setInfo] invalid parameter single_value_mode "' . $singleValueMode . '", possible values are {fill_if_empty, replace}.');
                }
            }
        }
        if (isset($params['file'])) {
            if (!empty($imageRow['storage_category_id'])) {
                return new PwgError(500, '[ws_images_setInfo] updating "file" is forbidden on photos added by synchronization');
            }
            $update['file'] = strip_tags(is_scalar($params['file']) ? (string) $params['file'] : '');
            if (empty($update['file'])) {
                unset($update['file']);
            }
        }
        if (count($update) > 0) {
            $update['id'] = $setImageId;
            single_update(IMAGES_TABLE, $update, ['id' => $update['id']]);
            pwg_activity('photo', $setImageId, 'edit');
        }
        if (isset($params['categories'])) {
            $this->addImageCategoryRelations($setImageId, is_string($params['categories']) ? $params['categories'] : '', $multipleValueMode === 'replace');
        }
        if (isset($params['tag_ids'])) {
            $tagIds = [];
            foreach (explode(',', is_scalar($params['tag_ids']) ? (string) $params['tag_ids'] : '') as $candidate) {
                $candidate = trim($candidate);
                if (preg_match(PATTERN_ID, $candidate)) {
                    $tagIds[] = $candidate;
                }
            }
            if ($multipleValueMode === 'replace') {
                ServiceLocator::get(TagAdminService::class)->setTags($tagIds, $setImageId);
            } elseif ($multipleValueMode === 'append') {
                ServiceLocator::get(TagAdminService::class)->addTags($tagIds, [$setImageId]);
            } else {
                return new PwgError(500, '[ws_images_setInfo] invalid parameter multiple_value_mode "' . $multipleValueMode . '", possible values are {replace, append}.');
            }
        }
        if (isset($_REQUEST['tag_list'])) {
            if (isset($params['tag_ids'])) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Do not use tag_list and tag_ids at the same time.');
            }
            $requestTagList = is_array($_REQUEST['tag_list']) ? $_REQUEST['tag_list'] : [];
            foreach ($requestTagList as $idx => $tagCandidate) {
                $requestTagList[$idx] = strip_tags(stripslashes(is_scalar($tagCandidate) ? (string) $tagCandidate : ''));
            }
            $tagList = ServiceLocator::get(TagAdminService::class)->getTagIds($requestTagList);
            ServiceLocator::get(TagAdminService::class)->setTags($tagList, $setImageId);
        }
        ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
        return null;
    }

    /** @param array<mixed> $params */
    public function delete(array $params, PwgServer $service): PwgError|int
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $delImageIdsRaw = $params['image_id'];
        if (!is_array($delImageIdsRaw)) {
            $delImageIdsRaw = preg_split('/[\s,;\|]/', is_scalar($delImageIdsRaw) ? (string) $delImageIdsRaw : '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $delImageIdsRaw = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $delImageIdsRaw);
        $imageIds       = array_filter($delImageIdsRaw, fn (int $v): bool => $v > 0);
        $ret            = ServiceLocator::get(ImageAdminService::class)->deleteElements(array_values($imageIds), true);
        ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
        return $ret;
    }

    /** @param array<mixed> $params */
    public function checkUpload(mixed $params, PwgServer $service): mixed
    {
        $ret = [];
        $ret['message']        = ServiceLocator::get(UploadService::class)->readyForUploadMessage();
        $ret['ready_for_upload'] = empty($ret['message']);
        return $ret;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function emptyLounge(array $params, PwgServer $service): array
    {
        return ['rows' => ServiceLocator::get(CategoryAdminService::class)->emptyLounge()];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function uploadCompleted(array $params, PwgServer $service): PwgError|array
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $ucImageIdsRaw = $params['image_id'];
        if (!is_array($ucImageIdsRaw)) {
            $ucImageIdsRaw = preg_split('/[\s,;\|]/', is_scalar($ucImageIdsRaw) ? (string) $ucImageIdsRaw : '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $ucImageIdsRaw = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ucImageIdsRaw);
        $imageIds      = array_values(array_filter($ucImageIdsRaw, fn (int $v): bool => $v > 0));
        $ucCategoryId  = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $movedFromLounge  = ServiceLocator::get(CategoryAdminService::class)->emptyLounge();
        $categoryInfos    = ['nb_photos' => ServiceLocator::get(CategoryRepository::class)->countImagesByCategoryId($ucCategoryId)];
        $categoryName     = get_cat_display_name_from_id($ucCategoryId, null);
        trigger_notify('ws_images_uploadCompleted', ['image_ids' => $imageIds, 'category_id' => $ucCategoryId, 'moved_from_lounge' => $movedFromLounge]);
        return ['moved_from_lounge' => $movedFromLounge, 'category' => ['id' => $ucCategoryId, 'nb_photos' => $categoryInfos['nb_photos'], 'label' => $categoryName]];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function setMd5sum(array $params, PwgServer $service): PwgError|array
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $noMd5sumIds    = get_photos_no_md5sum();
        $addedCount     = 0;
        if (count($noMd5sumIds) > 0) {
            $md5sumIdsToAdd = array_slice($noMd5sumIds, 0, is_numeric($params['block_size']) ? (int) $params['block_size'] : null);
            $addedCount     = add_md5sum($md5sumIdsToAdd);
        }
        return ['nb_added' => $addedCount, 'nb_no_md5sum' => count(get_photos_no_md5sum())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function syncMetadata(array $params, PwgServer $service): PwgError|array
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $syncImageIdsRaw = $params['image_id'];
        if (!is_array($syncImageIdsRaw)) {
            $syncImageIdsRaw = preg_split('/[\s,;\|]/', is_scalar($syncImageIdsRaw) ? (string) $syncImageIdsRaw : '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $imageIds = [];
        foreach ($syncImageIdsRaw as $imageId) {
            $imageId = trim(is_scalar($imageId) ? (string) $imageId : '');
            if (!preg_match(PATTERN_ID, $imageId)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid image_id "' . $imageId . '"');
            }
            $imageIds[] = $imageId;
        }
        if (empty($imageIds)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid image_id (no value after filters)');
        }
        $imageIds = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ' WHERE id IN (' . implode(', ', $imageIds) . ');')->fetchAllAssociative(), 'id'));
        if (empty($imageIds)) {
            return new PwgError(403, 'No image found');
        }
        ServiceLocator::get(MetadataAdminService::class)->syncMetadata($imageIds);
        return ['nb_synchronized' => count($imageIds)];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function deleteOrphans(array $params, PwgServer $service): PwgError|array
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $orphanIdsToDelete = array_slice(get_orphans(), 0, is_numeric($params['block_size']) ? (int) $params['block_size'] : null);
        $deletedCount      = ServiceLocator::get(ImageAdminService::class)->deleteElements($orphanIdsToDelete, true);
        ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
        return ['nb_deleted' => $deletedCount, 'nb_orphans' => count(get_orphans())];
    }

    /** @param array<mixed> $params */
    public function setCategory(array $params, PwgServer $service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $scCategoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $scImageIds   = is_array($params['image_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
        $categories   = get_dbal_connection()->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE id = ' . $scCategoryId . ';')->fetchAllAssociative();
        if (count($categories) === 0) {
            return new PwgError(404, 'category_id not found');
        }
        $scAction = is_string($params['action'] ?? null) ? $params['action'] : '';
        if ($scAction === 'associate') {
            ServiceLocator::get(CategoryAdminService::class)->associateImagesToCategories($scImageIds, [$scCategoryId]);
        } elseif ($scAction === 'dissociate') {
            ServiceLocator::get(CategoryAdminService::class)->dissociateImagesFromCategory($scImageIds, (string) $scCategoryId);
        } elseif ($scAction === 'move') {
            ServiceLocator::get(CategoryAdminService::class)->moveImagesToCategories($scImageIds, [$scCategoryId]);
        }
        ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
        return null;
    }
}
