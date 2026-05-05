<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

final class CategoriesEndpoints
{
    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function getImages(array $params, PwgServer &$service): PwgError|array
    {
        $rawCatId = is_array($params['cat_id']) ? $params['cat_id'] : [];
        /** @var int[] $catIds */
        $catIds = array_values(array_unique(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawCatId)));
        if (count($catIds) > 0) {
            $dbCatIds     = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', $catIds) . ');')->fetchAllAssociative(), 'id');
            $missingCatIds = array_diff($catIds, array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $dbCatIds));
            if (count($missingCatIds) > 0) {
                return new PwgError(404, 'cat_id {' . implode(',', $missingCatIds) . '} not found');
            }
        }
        $images      = [];
        $imageIds    = [];
        $totalImages = 0;
        $whereClauses = [];
        foreach ($catIds as $catIdInt) {
            if ($params['recursive']) {
                $whereClauses[] = 'uppercats ' . DB_REGEX_OPERATOR . " '(^|,)" . $catIdInt . "(,|$)'";
            } else {
                $whereClauses[] = 'id=' . $catIdInt;
            }
        }
        if (!empty($whereClauses)) {
            $whereClauses = ['(' . implode("\n    OR ", $whereClauses) . ')'];
        }
        $whereClauses[] = get_sql_condition_FandF(['forbidden_categories' => 'id'], null, true);
        $catConn = ServiceLocator::get(Connection::class);
        $cats    = [];
        foreach ($catConn->executeQuery('SELECT id, image_order FROM ' . CATEGORIES_TABLE . ' WHERE ' . implode("\n    AND ", $whereClauses) . ';')->fetchAllAssociative() as $row) {
            $row['id']       = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $cats[$row['id']] = $row;
        }
        if (!empty($cats)) {
            /** @var string[] $whereClauses2 */
            $whereClauses2   = ws_std_image_sql_filter($params, 'i.');
            $whereClauses2[] = 'category_id IN (' . implode(',', array_keys($cats)) . ')';
            $whereClauses2[] = get_sql_condition_FandF(['visible_images' => 'i.id'], null, true);
            $orderBy         = ws_std_image_sql_order($params, 'i.');
            if (empty($orderBy) && count($catIds) === 1 && isset($cats[$catIds[0]]['image_order'])) {
                $orderBy = is_scalar($cats[$catIds[0]]['image_order']) ? (string) $cats[$catIds[0]]['image_order'] : '';
            }
            $orderBy     = empty($orderBy) ? Config::orderBy() : 'ORDER BY ' . $orderBy;
            $favoriteIds = get_user_favorites();
            $perPage     = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
            $page        = is_numeric($params['page']) ? (int) $params['page'] : 0;
            $query       = 'SELECT SQL_CALC_FOUND_ROWS i.* FROM ' . IMAGES_TABLE . ' i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ON i.id=image_id WHERE ' . implode("\n    AND ", $whereClauses2) . ' GROUP BY i.id ' . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . ($perPage * $page) . ';';
            $catImgRows  = $catConn->executeQuery($query)->fetchAllAssociative();
            foreach ($catImgRows as $row) {
                $imageIds[]  = $row['id'];
                $image       = [];
                $rowIdKey    = is_scalar($row['id']) ? (string) $row['id'] : '';
                $image['is_favorite'] = isset($favoriteIds[$rowIdKey]);
                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($row[$k])) {
                        $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                    }
                }
                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $row[$k];
                }
                $imageName   = is_scalar($image['name'] ?? null) ? (string) $image['name'] : '';
                $renderedName = trigger_change('render_element_name', $imageName, __FUNCTION__);
                $image['name']    = strip_tags($renderedName);
                $image['comment'] = trigger_change('render_element_description', $image['comment'] ?? null, __FUNCTION__);
                $image = array_merge($image, ws_std_get_urls($row));
                $images[] = $image;
            }
            $totalImagesRaw = $catConn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
            $totalImages    = is_numeric($totalImagesRaw) ? (int) $totalImagesRaw : 0;
            if (count($imageIds) > 0) {
                $categoryIds = [];
                $categoriesOfImage = [];
                foreach ($catConn->executeQuery('SELECT image_id, category_id FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE image_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $imageIds)) . ') AND ' . get_sql_condition_FandF(['forbidden_categories' => 'category_id'], null, true) . ';')->fetchAllAssociative() as $row) {
                    $categoryIds[] = $row['category_id'];
                    $rowImgId = is_scalar($row['image_id']) ? (string) $row['image_id'] : '';
                    if ($rowImgId !== '') {
                        $categoriesOfImage[$rowImgId][] = $row['category_id'];
                    }
                }
                $detailsForCategory = [];
                if (count($categoryIds) > 0) {
                    $detailsForCategory = array_column(get_dbal_connection()->executeQuery('SELECT id, name, permalink FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $categoryIds)) . ');')->fetchAllAssociative(), null, 'id');
                }
                foreach ($images as $idx => $image) {
                    $imageCats  = [];
                    $imageIdKey = is_scalar($image['id']) ? (string) $image['id'] : '';
                    if (!isset($categoriesOfImage[$imageIdKey])) {
                        continue;
                    }
                    foreach ($categoriesOfImage[$imageIdKey] as $catId) {
                        $catIdKey = is_scalar($catId) ? (string) $catId : '';
                        if (!isset($detailsForCategory[$catIdKey])) {
                            continue;
                        }
                        $url     = make_index_url(['category' => $detailsForCategory[$catIdKey]]);
                        $pageUrl = make_picture_url(['category' => $detailsForCategory[$catIdKey], 'image_id' => $image['id'], 'image_file' => $image['file']]);
                        $imageCats[] = ['id' => is_numeric($catId) ? (int) $catId : 0, 'url' => $url, 'page_url' => $pageUrl];
                    }
                    $images[$idx]['categories'] = new PwgNamedArray($imageCats, 'category', ['id', 'url', 'page_url']);
                }
            }
        }
        return ['paging' => new PwgNamedStruct(['page' => $params['page'], 'per_page' => $params['per_page'], 'count' => count($images), 'total_count' => $totalImages]), 'images' => new PwgNamedArray($images, 'image', ws_std_get_image_xml_attributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function getList(array $params, PwgServer &$service): PwgError|array
    {
        $currentUser = CurrentUser::get();
        $user        = $currentUser->rawAttributes;
        if (!in_array($params['thumbnail_size'], array_keys(ImageStdParams::get_defined_type_map()))) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid thumbnail_size');
        }
        if (!empty($params['limit']) && $params['recursive']) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Cannot use both recursive and limit parameters at the same time');
        }
        $output    = [];
        $where     = ['1=1'];
        $joinType  = 'INNER';
        $joinUser  = $currentUser->id;
        $getlistCatId = is_numeric($params['cat_id']) ? (int) $params['cat_id'] : 0;
        if (!$params['recursive']) {
            if ($getlistCatId > 0) {
                $where[] = '(id_uppercat = ' . $getlistCatId . ' OR id=' . $getlistCatId . ')';
            } else {
                $where[] = 'id_uppercat IS NULL';
            }
        } elseif ($getlistCatId > 0) {
            $where[] = 'uppercats ' . DB_REGEX_OPERATOR . " '(^|,)" . $getlistCatId . "(,|$)'";
        }
        if ($params['public']) {
            $where[]  = 'status = "public"';
            $where[]  = 'visible = "true"';
            $joinUser = Config::guestId();
        } elseif (is_admin()) {
            $forbiddenCategories = calculate_permissions($currentUser->id, $currentUser->status);
            $where[]  = 'id NOT IN (' . $forbiddenCategories . ')';
            $joinType = 'LEFT';
        }
        $query = 'SELECT SQL_CALC_FOUND_ROWS id, name, comment, permalink, status, uppercats, global_rank, id_uppercat, nb_images, count_images AS total_nb_images, representative_picture_id, user_representative_picture_id, count_images, count_categories, date_last, max_date_last, count_categories AS nb_categories, image_order FROM ' . CATEGORIES_TABLE . ' ' . $joinType . ' JOIN ' . USER_CACHE_CATEGORIES_TABLE . ' ON id=cat_id AND user_id=' . $joinUser . ' WHERE ' . implode("\n    AND ", $where);
        if (isset($params['search']) && $params['search'] !== '') {
            $query .= ' AND name LIKE ' . get_dbal_connection()->quote('%' . (is_scalar($params['search']) ? (string) $params['search'] : '') . '%');
            if (!isset($params['limit'])) {
                $query .= ' LIMIT ' . Config::linkedAlbumSearchLimit();
            }
        }
        $limitParam = is_numeric($params['limit'] ?? null) ? (int) $params['limit'] : 0;
        $catIdParam = is_numeric($params['cat_id'] ?? null) ? (int) $params['cat_id'] : 0;
        if (isset($params['limit'])) {
            $query .= ' ORDER BY `rank` ASC LIMIT ' . ($limitParam + ($catIdParam > 0 ? 1 : 0));
        }
        $query .= ';';
        $getListConn = ServiceLocator::get(Connection::class);
        $getListRows = $getListConn->executeQuery($query)->fetchAllAssociative();
        if (isset($params['limit'])) {
            $resultCount    = $getListConn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
            $resultCountInt = is_numeric($resultCount) ? (int) $resultCount : 0;
            if ($catIdParam > 0) {
                $resultCountInt--;
            }
            $output['limit'] = ['limited_to' => $limitParam, 'total_cats' => $resultCountInt, 'remaining_cats' => $resultCountInt > $limitParam ? $resultCountInt - $limitParam : 0];
        }
        $imageIds                    = [];
        $categories                  = [];
        $userRepresentativeUpdatesFor = [];
        $cats                        = [];
        foreach ($getListRows as $row) {
            $row['url'] = make_index_url(['category' => $row]);
            foreach (['id', 'nb_images', 'total_nb_images', 'nb_categories'] as $key) {
                $row[$key] = is_numeric($row[$key]) ? (int) $row[$key] : 0;
            }
            if ($params['fullname']) {
                $row['name'] = strip_tags(get_cat_display_name_cache(is_scalar($row['uppercats']) ? (string) $row['uppercats'] : '', null));
            } else {
                $row['name_raw']  = $row['name'];
                $renderedListName = trigger_change('render_category_name', is_scalar($row['name']) ? (string) $row['name'] : '', 'ws_categories_getList');
                $row['name']      = strip_tags($renderedListName);
            }
            $row['comment_raw'] = $row['comment'];
            $row['comment']     = trigger_change('render_category_description', is_scalar($row['comment']) ? (string) $row['comment'] : '', 'ws_categories_getList');
            $imageId            = null;
            if (!empty($row['user_representative_picture_id'])) {
                $imageId = $row['user_representative_picture_id'];
            } elseif (!empty($row['representative_picture_id'])) {
                $imageId = $row['representative_picture_id'];
            } elseif (Config::allowRandomRepresentative()) {
                $imageId = get_random_image_in_category($row);
            } else {
                if ($row['count_categories'] > 0 && $row['count_images'] > 0) {
                    $subQuery = 'SELECT representative_picture_id FROM ' . CATEGORIES_TABLE . ' INNER JOIN ' . USER_CACHE_CATEGORIES_TABLE . ' ON id=cat_id AND user_id=' . $currentUser->id . " WHERE uppercats LIKE '" . (is_scalar($row['uppercats']) ? (string) $row['uppercats'] : '') . ",%' AND representative_picture_id IS NOT NULL" . get_sql_condition_FandF(['visible_categories' => 'id'], "\n  AND") . ' ORDER BY ' . DB_RANDOM_FUNCTION . '() LIMIT 1;';
                    $subval = ServiceLocator::get(Connection::class)->executeQuery($subQuery)->fetchOne();
                    if ($subval !== false) {
                        $imageId = is_numeric($subval) ? (int) $subval : null;
                    }
                }
            }
            if (isset($imageId)) {
                if (Config::representativeCacheOnSubcats() && $row['user_representative_picture_id'] != $imageId) {
                    $userRepresentativeUpdatesFor[$row['id']] = $imageId;
                }
                $row['representative_picture_id'] = $imageId;
                $imageIds[]   = $imageId;
                $categories[] = $row;
            }
            unset($imageId);
            if (empty($row['image_order'])) {
                $row['image_order'] = str_replace('ORDER BY ', '', Config::orderBy());
            }
            $cats[] = $row;
        }
        usort($cats, global_rank_compare(...));
        $thumbnailSrcOf = [];
        if (count($categories) > 0) {
            $newImageIds  = [];
            $thumbnailSize = is_scalar($params['thumbnail_size']) ? (string) $params['thumbnail_size'] : '';
            $imgRepoWsCats = ServiceLocator::get(ImageRepository::class);
            foreach ($imgRepoWsCats->findByIds(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $imageIds)) as $row) {
                if ($row['level'] <= $user['level']) {
                    $thumbnailSrcOf[is_scalar($row['id']) ? (string) $row['id'] : ''] = DerivativeImage::url($thumbnailSize, $row);
                } else {
                    foreach ($categories as &$category) {
                        if ($row['id'] == $category['representative_picture_id']) {
                            $newImgId = get_random_image_in_category($category);
                            if (isset($newImgId) && !in_array($newImgId, $imageIds)) {
                                $newImageIds[] = $newImgId;
                            }
                            if (Config::representativeCacheOnLevel()) {
                                $userRepresentativeUpdatesFor[is_numeric($category['id']) ? (int) $category['id'] : 0] = $newImgId;
                            }
                            $category['representative_picture_id'] = $newImgId;
                        }
                    }
                    unset($category);
                }
            }
            if (count($newImageIds) > 0) {
                foreach ($imgRepoWsCats->findByIds(array_map('intval', $newImageIds)) as $row) {
                    $thumbnailSrcOf[is_scalar($row['id']) ? (string) $row['id'] : ''] = DerivativeImage::url($thumbnailSize, $row);
                }
            }
        }
        if (!$params['public'] && count($userRepresentativeUpdatesFor)) {
            $updates = [];
            foreach ($userRepresentativeUpdatesFor as $catId => $imageId) {
                $updates[] = ['user_id' => $user['id'], 'cat_id' => $catId, 'user_representative_picture_id' => $imageId];
            }
            mass_updates(USER_CACHE_CATEGORIES_TABLE, ['primary' => ['user_id', 'cat_id'], 'update' => ['user_representative_picture_id']], $updates);
        }
        foreach ($cats as &$cat) {
            foreach ($categories as $category) {
                if ($category['id'] == $cat['id'] && isset($category['representative_picture_id'])) {
                    $repKey = is_scalar($category['representative_picture_id']) ? (string) $category['representative_picture_id'] : '';
                    $cat['tn_url'] = $thumbnailSrcOf[$repKey] ?? null;
                }
            }
            unset($cat['user_representative_picture_id'], $cat['count_images'], $cat['count_categories']);
        }
        unset($cat);
        if ($params['tree_output']) {
            return categories_flatlist_to_tree($cats);
        }
        $output['categories'] = new PwgNamedArray($cats, 'category', ws_std_get_category_xml_attributes());
        return $output;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function getAdminList(array $params, PwgServer &$service): array
    {
        if (!isset($params['additional_output'])) {
            $params['additional_output'] = '';
        }
        $params['additional_output'] = array_map(trim(...), explode(',', is_scalar($params['additional_output']) ? (string) $params['additional_output'] : ''));
        $nbImagesOf = array_column(get_dbal_connection()->executeQuery('SELECT category_id, COUNT(*) AS counter FROM ' . IMAGE_CATEGORY_TABLE . ' GROUP BY category_id;')->fetchAllAssociative(), 'counter', 'category_id');
        $where      = ['1=1'];
        $adminCatId = is_numeric($params['cat_id']) ? (int) $params['cat_id'] : 0;
        if (!$params['recursive']) {
            if ($adminCatId > 0) {
                $where[] = '(id_uppercat = ' . $adminCatId . ' OR id=' . $adminCatId . ')';
            } else {
                $where[] = 'id_uppercat IS NULL';
            }
        } elseif ($adminCatId > 0) {
            $where[] = 'uppercats ' . DB_REGEX_OPERATOR . " '(^|,)" . $adminCatId . "(,|$)'";
        }
        $query = 'SELECT SQL_CALC_FOUND_ROWS id, name, comment, uppercats, global_rank, dir, status, image_order FROM ' . CATEGORIES_TABLE . ' WHERE ' . implode("\n    AND ", $where);
        if (isset($params['search']) && $params['search'] !== '') {
            $query .= ' AND name LIKE ' . get_dbal_connection()->quote('%' . (is_scalar($params['search']) ? (string) $params['search'] : '') . '%') . ' LIMIT ' . Config::linkedAlbumSearchLimit();
        }
        $query     .= ';';
        $searchConn = ServiceLocator::get(Connection::class);
        $searchRows = $searchConn->executeQuery($query)->fetchAllAssociative();
        $counter    = $searchConn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
        $cats       = [];
        foreach ($searchRows as $row) {
            $id              = is_scalar($row['id']) ? (string) $row['id'] : '';
            $row['nb_images'] = $nbImagesOf[$id] ?? 0;
            $catDisplayName  = get_cat_display_name_cache(is_scalar($row['uppercats']) ? (string) $row['uppercats'] : '', 'admin.php?page=album-');
            $row['name_raw'] = $row['name'];
            $renderedAdminName = trigger_change('render_category_name', is_scalar($row['name']) ? (string) $row['name'] : '', 'ws_categories_getAdminList');
            $row['name']     = strip_tags($renderedAdminName);
            $row['fullname'] = strip_tags($catDisplayName);
            $row['comment_raw'] = $row['comment'];
            $row['comment']  = trigger_change('render_category_description', $row['comment'] ?? '', 'ws_categories_getAdminList');
            if (empty($row['image_order'])) {
                $row['image_order'] = str_replace('ORDER BY ', '', Config::orderBy());
            }
            if (in_array('full_name_with_admin_links', $params['additional_output'])) {
                $row['full_name_with_admin_links'] = $catDisplayName;
            }
            $cats[] = $row;
        }
        if (!$params['recursive']) {
            $catsIds    = array_column($cats, 'id');
            $nbSubcatsOf = [];
            if (!empty($catsIds)) {
                $nbSubcatsOf = array_column(get_dbal_connection()->executeQuery('SELECT id_uppercat, COUNT(*) AS nb_subcats FROM ' . CATEGORIES_TABLE . ' WHERE id_uppercat IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $catsIds)) . ') GROUP BY id_uppercat;')->fetchAllAssociative(), 'nb_subcats', 'id_uppercat');
            }
            foreach ($cats as $idx => $cat) {
                $catIdKey            = is_scalar($cat['id']) ? (string) $cat['id'] : '';
                $cats[$idx]['nb_categories'] = is_numeric($nbSubcatsOf[$catIdKey] ?? null) ? (int) $nbSubcatsOf[$catIdKey] : 0;
            }
        }
        $limitReached = ($counter > Config::linkedAlbumSearchLimit());
        usort($cats, global_rank_compare(...));
        return ['categories' => new PwgNamedArray($cats, 'category', ['id', 'nb_images', 'name', 'uppercats', 'global_rank', 'status', 'test']), 'limit' => Config::linkedAlbumSearchLimit(), 'limit_reached' => $limitReached];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function add(array $params, PwgServer &$service): PwgError|array
    {
        if (isset($params['pwg_token']) && get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!empty($params['position']) && in_array($params['position'], ['first', 'last'])) {
            Config::override('newcat_default_position', is_scalar($params['position']) ? (string) $params['position'] : '');
        }
        $options = [];
        if (!empty($params['status']) && in_array($params['status'], ['private', 'public'])) {
            $options['status'] = $params['status'];
        }
        if (!empty($params['comment'])) {
            $commentStr    = is_scalar($params['comment']) ? (string) $params['comment'] : '';
            $options['comment'] = (!Config::allowHtmlDescriptions() || !isset($params['pwg_token'])) ? strip_tags($commentStr) : $commentStr;
        }
        $catName   = (!Config::allowHtmlDescriptions() || !isset($params['pwg_token'])) ? strip_tags(is_scalar($params['name']) ? (string) $params['name'] : '') : (is_scalar($params['name']) ? (string) $params['name'] : '');
        $catParent = is_numeric($params['parent']) ? (int) $params['parent'] : (is_string($params['parent']) ? $params['parent'] : null);
        $creationOutput = create_virtual_category($catName, $catParent, $options);
        if (isset($creationOutput['error'])) {
            return new PwgError(500, is_scalar($creationOutput['error']) ? (string) $creationOutput['error'] : '');
        }
        invalidate_user_cache();
        return $creationOutput;
    }

    /** @param array<mixed> $params */
    public function setRank(array $params, PwgServer &$service): mixed
    {
        $rawSetrankIds  = is_array($params['category_id']) ? $params['category_id'] : [];
        /** @var int[] $setrankCategoryIds */
        $setrankCategoryIds = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawSetrankIds);
        $categories     = get_dbal_connection()->executeQuery('SELECT id, id_uppercat, `rank` FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', $setrankCategoryIds) . ');')->fetchAllAssociative();
        if (count($categories) === 0) {
            return new PwgError(404, 'category_id not found');
        }
        $category = $categories[0];
        if (count($setrankCategoryIds) > 1) {
            $orderNew      = $setrankCategoryIds;
            $orderNewById  = $orderNew;
            sort($orderNewById, SORT_NUMERIC);
            $catAsc        = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE id_uppercat ' . (empty($category['id_uppercat']) ? 'IS NULL' : '= ' . (is_scalar($category['id_uppercat']) ? (string) $category['id_uppercat'] : '0')) . ' ORDER BY `id` ASC;')->fetchAllAssociative(), 'id');
            $catAscStr     = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $catAsc);
            $orderNewStr   = array_map(fn (int $v): string => (string) $v, $orderNewById);
            if (strcmp(implode(',', $catAscStr), implode(',', $orderNewStr)) !== 0) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'you need to provide all sub-category ids for a given category');
            }
            $orderNew = $setrankCategoryIds;
        } else {
            $singleCatId    = implode('', array_map(fn (int $v): string => (string) $v, $setrankCategoryIds));
            $idUppercatStr  = is_scalar($category['id_uppercat']) ? (string) $category['id_uppercat'] : '';
            $orderOld       = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE id_uppercat ' . (empty($idUppercatStr) ? 'IS NULL' : '= ' . $idUppercatStr) . ' AND id != ' . $singleCatId . ' ORDER BY `rank` ASC;')->fetchAllAssociative(), 'id');
            $rankTarget     = is_numeric($params['rank']) ? (int) $params['rank'] : 0;
            $orderNew       = [];
            $wasInserted    = false;
            $i              = 1;
            foreach ($orderOld as $categoryId) {
                if ($i === $rankTarget) {
                    $orderNew[]  = $singleCatId;
                    $wasInserted = true;
                }
                $orderNew[] = $categoryId;
                ++$i;
            }
            if (!$wasInserted) {
                $orderNew[] = $singleCatId;
            }
        }
        save_categories_order($orderNew);
        return null;
    }

    /** @param array<mixed> $params */
    public function setInfo(array $params, PwgServer &$service): mixed
    {
        if (isset($params['pwg_token']) && get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $categoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $categories = get_dbal_connection()->executeQuery('SELECT * FROM ' . CATEGORIES_TABLE . ' WHERE id = ' . $categoryId . ';')->fetchAllAssociative();
        if (count($categories) === 0) {
            return new PwgError(404, 'category_id not found');
        }
        $category = $categories[0];
        if (!empty($params['status'])) {
            if (!in_array($params['status'], ['private', 'public'])) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid status, only public/private');
            }
            if ($params['status'] !== $category['status']) {
                set_cat_status([$categoryId], is_scalar($params['status']) ? (string) $params['status'] : '');
            }
        }
        $update = ['id' => $categoryId];
        foreach (['visible', 'commentable'] as $paramName) {
            $paramValStr = is_scalar($params[$paramName] ?? null) ? (string) $params[$paramName] : '';
            if (isset($params[$paramName]) && !preg_match('/^(true|false)$/i', $paramValStr)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid param ' . $paramName . ' : ' . $paramValStr);
            }
        }
        if (!empty($params['visible']) && ($params['visible'] !== $category['visible'])) {
            set_cat_visible([$categoryId], is_string($params['visible']) ? $params['visible'] : (is_bool($params['visible']) ? $params['visible'] : false));
        }
        $infoColumns    = ['name', 'comment', 'commentable'];
        $performUpdate  = false;
        foreach ($infoColumns as $key) {
            if (isset($params[$key])) {
                $performUpdate = true;
                $keyValStr = is_scalar($params[$key]) ? (string) $params[$key] : '';
                $update[$key] = (!Config::allowHtmlDescriptions() || !isset($params['pwg_token'])) ? strip_tags($keyValStr) : $keyValStr;
            }
        }
        if (isset($params['commentable']) && isset($params['apply_commentable_to_subalbums']) && $params['apply_commentable_to_subalbums']) {
            $subcats = get_subcat_ids([$categoryId]);
            if (count($subcats) > 0) {
                $commentableVal = is_scalar($params['commentable']) ? (string) $params['commentable'] : 'false';
                ServiceLocator::get(CategoryRepository::class)->setCommentable(array_map('intval', $subcats), $commentableVal === 'true');
            }
        }
        if ($performUpdate) {
            single_update(CATEGORIES_TABLE, $update, ['id' => $update['id']]);
        }
        pwg_activity('album', $categoryId, 'edit', ['fields' => implode(',', array_keys($update))]);
        return null;
    }

    /** @param array<mixed> $params */
    public function setRepresentative(array $params, PwgServer &$service): mixed
    {
        $categoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $imageId    = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $catRepo    = ServiceLocator::get(CategoryRepository::class);
        $imgRepo    = ServiceLocator::get(ImageRepository::class);
        if (!$catRepo->existsById($categoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        if (!$imgRepo->existsById($imageId)) {
            return new PwgError(404, 'image_id not found');
        }
        $catRepo->setRepresentativePicture([$categoryId], $imageId);
        ServiceLocator::get(\Piwigo\Users\UserRepository::class)->clearUserRepresentativeForCategory($categoryId);
        pwg_activity('album', $categoryId, 'edit', ['image_id' => $imageId]);
        return null;
    }

    /** @param array<mixed> $params */
    public function deleteRepresentative(array $params, PwgServer &$service): mixed
    {
        $categoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $catRepo2   = ServiceLocator::get(CategoryRepository::class);
        if (!$catRepo2->existsById($categoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        $nbImages = $catRepo2->countImagesByCategoryId($categoryId);
        if (!Config::allowRandomRepresentative() && $nbImages !== 0) {
            return new PwgError(401, 'not permitted');
        }
        $catRepo2->clearRepresentatives([$categoryId]);
        pwg_activity('album', $categoryId, 'edit');
        return null;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function refreshRepresentative(array $params, PwgServer &$service): PwgError|array
    {
        $categoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $catRepo3   = ServiceLocator::get(CategoryRepository::class);
        if (!$catRepo3->existsById($categoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        if (!$catRepo3->hasCategoryImages($categoryId)) {
            return new PwgError(401, 'not permitted');
        }
        set_random_representant([$categoryId]);
        pwg_activity('album', $categoryId, 'edit');
        $category = $catRepo3->findCategoryById($categoryId);
        $repId    = isset($category['representative_picture_id']) ? (is_scalar($category['representative_picture_id']) ? (string) $category['representative_picture_id'] : '') : '';
        return get_category_representant_properties($repId, IMG_SMALL);
    }

    /** @param array<mixed> $params */
    public function delete(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $photoDeletionMode = is_scalar($params['photo_deletion_mode']) ? (string) $params['photo_deletion_mode'] : '';
        $modes = ['no_delete', 'delete_orphans', 'force_delete'];
        if (!in_array($photoDeletionMode, $modes)) {
            return new PwgError(500, '[ws_categories_delete] invalid parameter photo_deletion_mode "' . $photoDeletionMode . '", possible values are {' . implode(', ', $modes) . '}.');
        }
        if (!is_array($params['category_id'])) {
            $params['category_id'] = preg_split('/[\s,;\|]/', is_scalar($params['category_id']) ? (string) $params['category_id'] : '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $params['category_id'] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['category_id']);
        $categoryIds = array_filter($params['category_id'], fn (int $v): bool => $v > 0);
        if (count($categoryIds) === 0) {
            return null;
        }
        $rawCategoryIds = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', $categoryIds) . ');')->fetchAllAssociative(), 'id');
        if (count($rawCategoryIds) === 0) {
            return null;
        }
        delete_categories(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawCategoryIds), $photoDeletionMode);
        update_global_rank();
        invalidate_user_cache();
        return null;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function move(array $params, PwgServer &$service): PwgError|array
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!is_array($params['category_id'])) {
            $params['category_id'] = preg_split('/[\s,;\|]/', is_scalar($params['category_id']) ? (string) $params['category_id'] : '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $params['category_id'] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['category_id']);
        $categoryIds = array_filter($params['category_id'], fn (int $v): bool => $v > 0);
        if (count($categoryIds) === 0) {
            return new PwgError(403, 'Invalid category_id input parameter, no category to move');
        }
        $categoriesInDb = [];
        $updateCatIds   = [];
        $parentId       = is_numeric($params['parent']) ? (int) $params['parent'] : 0;
        foreach (ServiceLocator::get(CategoryRepository::class)->findByIds(array_map('intval', $categoryIds)) as $row) {
            $rowId              = is_scalar($row['id']) ? (string) $row['id'] : '';
            $categoriesInDb[$rowId] = $row;
            $updateCatIds = array_merge($updateCatIds, array_slice(explode(',', is_scalar($row['uppercats']) ? (string) $row['uppercats'] : ''), 0, -1));
            if (!empty($row['dir'])) {
                $renderedMoveName = trigger_change('render_category_name', is_scalar($row['name']) ? (string) $row['name'] : '', 'ws_categories_move');
                $row['name'] = strip_tags($renderedMoveName);
                return new PwgError(403, sprintf('Category %s (%u) is not a virtual category, you cannot move it', $row['name'], is_numeric($row['id']) ? (int) $row['id'] : 0));
            }
        }
        if (count($categoriesInDb) !== count($categoryIds)) {
            $unknownCategoryIds = array_diff($categoryIds, array_keys($categoriesInDb));
            return new PwgError(403, sprintf('Category %u does not exist', (int) $unknownCategoryIds[0]));
        }
        if ($parentId !== 0) {
            $subcatIds = get_subcat_ids([$parentId]);
            if (count($subcatIds) === 0) {
                return new PwgError(403, 'Unknown parent category id');
            }
        }
        move_categories($categoryIds, $parentId);
        invalidate_user_cache();
        $catDisplayName = '';
        foreach (ServiceLocator::get(CategoryRepository::class)->findUppercatsByIds(array_map('intval', $categoryIds)) as $uppercatsStr) {
            $catDisplayName = get_cat_display_name_cache($uppercatsStr, 'admin.php?page=album-');
            $updateCatIds   = array_merge($updateCatIds, array_slice(explode(',', $uppercatsStr), 0, -1));
        }
        $nbPhotosIn = array_column(get_dbal_connection()->executeQuery('SELECT category_id, COUNT(*) AS nb_photos FROM ' . IMAGE_CATEGORY_TABLE . ' GROUP BY category_id;')->fetchAllAssociative(), 'nb_photos', 'category_id');
        $updateCats = [];
        foreach (array_unique($updateCatIds) as $updateCat) {
            $nbSubPhotos      = 0;
            $subCatWithoutParent = array_diff(get_subcat_ids([$updateCat]), [$updateCat]);
            foreach ($subCatWithoutParent as $idSubCat) {
                $nbSubPhotos += is_numeric($nbPhotosIn[(string) $idSubCat] ?? null) ? (int) $nbPhotosIn[(string) $idSubCat] : 0;
            }
            $updateCats[] = ['cat_id' => $updateCat, 'nb_sub_photos' => $nbSubPhotos];
        }
        return ['new_ariane_string' => $catDisplayName, 'updated_cats' => $updateCats];
    }

    /** @param array<mixed> $param */
    public function calculateOrphans(array $param, PwgServer &$service): mixed
    {
        $paramCatId    = is_array($param['category_id']) ? $param['category_id'] : [];
        $categoryId    = is_numeric($paramCatId[0] ?? null) ? (int) $paramCatId[0] : 0;
        $category      = [];
        $category['has_images'] = ServiceLocator::get(CategoryRepository::class)->hasCategoryImages($categoryId);
        $subcatIds     = get_subcat_ids([$categoryId]);
        $category['nb_subcats'] = count($subcatIds) - 1;
        $imageIdsRecursive = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(image_id) FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id IN (' . implode(',', $subcatIds) . ');')->fetchAllAssociative(), 'image_id');
        $category['nb_images_recursive'] = count($imageIdsRecursive);
        $category['nb_images_becoming_orphan']     = 0;
        $category['nb_images_associated_outside']  = 0;
        if ($category['nb_images_recursive'] > 0) {
            if ($category['nb_images_recursive'] < 1000) {
                $imageIdsAssociatedOutside = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(image_id) FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id NOT IN (' . implode(',', $subcatIds) . ') AND image_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $imageIdsRecursive)) . ');')->fetchAllAssociative(), 'image_id'));
                $category['nb_images_associated_outside'] = count($imageIdsAssociatedOutside);
                $imageIdsBecomingOrphan = array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $imageIdsRecursive), $imageIdsAssociatedOutside);
                $category['nb_images_becoming_orphan'] = count($imageIdsBecomingOrphan);
            } else {
                $imageIdsRecursiveKeys = array_flip(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $imageIdsRecursive));
                $imageIdsAssociatedOutside2 = array_column(get_dbal_connection()->executeQuery('SELECT image_id FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id NOT IN (' . implode(',', $subcatIds) . ');')->fetchAllAssociative(), 'image_id');
                $imageIdsNotOrphan = [];
                foreach ($imageIdsAssociatedOutside2 as $imageId) {
                    if (isset($imageIdsRecursiveKeys[is_scalar($imageId) ? (string) $imageId : ''])) {
                        $imageIdsNotOrphan[] = $imageId;
                    }
                }
                $category['nb_images_associated_outside'] = count(array_unique(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $imageIdsNotOrphan)));
                $imageIdsBecomingOrphan2 = array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $imageIdsRecursive), array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $imageIdsNotOrphan));
                $category['nb_images_becoming_orphan'] = count($imageIdsBecomingOrphan2);
            }
        }
        return [['nb_images_associated_outside' => $category['nb_images_associated_outside'], 'nb_images_becoming_orphan' => $category['nb_images_becoming_orphan'], 'nb_images_recursive' => $category['nb_images_recursive']]];
    }
}
