<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsHelper;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class CategoriesEndpoints
{
    public function __construct(
        private Connection $conn,
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private HtmlService $htmlService,
        private ImageAdminService $imageAdminService,
        private ImageRepository $imageRepository,
        private PermissionService $permissionService,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private UserAdminService $userAdminService,
        private UserRepository $userRepository,
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private WsHelper $wsHelper,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

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
            $dbCatIds     = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $catIds) . ');')->fetchAllAssociative(), 'id');
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
                $whereClauses[] = 'uppercats ' . Dml::REGEX_OPERATOR . " '(^|,)" . $catIdInt . "(,|$)'";
            } else {
                $whereClauses[] = 'id=' . $catIdInt;
            }
        }
        if (!empty($whereClauses)) {
            $whereClauses = ['(' . implode("\n    OR ", $whereClauses) . ')'];
        }
        $whereClauses[] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'id'], null, true);
        $catConn = $this->conn;
        $cats    = [];
        foreach ($catConn->executeQuery('SELECT id, image_order FROM ' . Tables::categories() . ' WHERE ' . implode("\n    AND ", $whereClauses) . ';')->fetchAllAssociative() as $row) {
            $row['id']       = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $cats[$row['id']] = $row;
        }
        if (!empty($cats)) {
            /** @var string[] $whereClauses2 */
            $whereClauses2   = $this->wsHelper->imageSqlFilter($params, 'i.');
            $whereClauses2[] = 'category_id IN (' . implode(',', array_keys($cats)) . ')';
            $whereClauses2[] = $this->permissionService->getSqlConditionFandF(['visible_images' => 'i.id'], null, true);
            $orderBy         = $this->wsHelper->imageSqlOrder($params, 'i.');
            if (empty($orderBy) && count($catIds) === 1 && isset($cats[$catIds[0]]['image_order'])) {
                $orderBy = is_scalar($cats[$catIds[0]]['image_order']) ? (string) $cats[$catIds[0]]['image_order'] : '';
            }
            $orderBy     = empty($orderBy) ? Config::orderBy() : 'ORDER BY ' . $orderBy;
            $favoriteIds = $this->urlService->getUserFavorites();
            $perPage     = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
            $page        = is_numeric($params['page']) ? (int) $params['page'] : 0;
            $query       = 'SELECT SQL_CALC_FOUND_ROWS i.* FROM ' . Tables::images() . ' i INNER JOIN ' . Tables::imageCategory() . ' ON i.id=image_id WHERE ' . implode("\n    AND ", $whereClauses2) . ' GROUP BY i.id ' . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . ($perPage * $page) . ';';
            $catImgRows  = $catConn->executeQuery($query)->fetchAllAssociative();
            foreach ($catImgRows as $row) {
                $imageIds[]  = $row['id'];
                $image       = [];
                $rowIdInt = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
                $image['is_favorite'] = isset($favoriteIds[$rowIdInt]);
                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($row[$k])) {
                        $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                    }
                }
                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $row[$k] ?? null;
                }
                $imageName   = is_scalar($image['name'] ?? null) ? (string) $image['name'] : '';
                $renderEvent = new RenderElementName($imageName, $image);
                $this->dispatcher->dispatch($renderEvent);
                $image['name']    = strip_tags($renderEvent->elementName);
                $image['comment'] = EventDispatcher::dispatch('render_element_description', $image['comment'] ?? null, __FUNCTION__);
                $image = array_merge($image, $this->wsHelper->getUrls($row));
                $images[] = $image;
            }
            $totalImagesRaw = $catConn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
            $totalImages    = is_numeric($totalImagesRaw) ? (int) $totalImagesRaw : 0;
            if (count($imageIds) > 0) {
                $categoryIds = [];
                $categoriesOfImage = [];
                foreach ($catConn->executeQuery('SELECT image_id, category_id FROM ' . Tables::imageCategory() . ' WHERE image_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $imageIds)) . ') AND ' . $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id'], null, true) . ';')->fetchAllAssociative() as $row) {
                    $categoryIds[] = $row['category_id'];
                    $rowImgId = is_scalar($row['image_id'] ?? null) ? (string) $row['image_id'] : '';
                    if ($rowImgId !== '') {
                        $categoriesOfImage[$rowImgId][] = $row['category_id'];
                    }
                }
                $detailsForCategory = [];
                if (count($categoryIds) > 0) {
                    $detailsForCategory = array_column($this->conn->executeQuery('SELECT id, name, permalink FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $categoryIds)) . ');')->fetchAllAssociative(), null, 'id');
                }
                foreach ($images as $idx => $image) {
                    $imageCats  = [];
                    $imageIdRaw = $image['id'] ?? null;
                    $imageIdKey = is_string($imageIdRaw) ? $imageIdRaw : '';
                    if (!isset($categoriesOfImage[$imageIdKey])) {
                        continue;
                    }
                    foreach ($categoriesOfImage[$imageIdKey] as $catId) {
                        $catIdKey = is_scalar($catId) ? (string) $catId : '';
                        if (!isset($detailsForCategory[$catIdKey])) {
                            continue;
                        }
                        $url     = $this->urlService->makeIndexUrl(['category' => $detailsForCategory[$catIdKey]]);
                        $pageUrl = $this->urlService->makePictureUrl(['category' => $detailsForCategory[$catIdKey], 'image_id' => $image['id'] ?? null, 'image_file' => $image['file']]);
                        $imageCats[] = ['id' => is_numeric($catId) ? (int) $catId : 0, 'url' => $url, 'page_url' => $pageUrl];
                    }
                    $images[$idx]['categories'] = new PwgNamedArray($imageCats, 'category', ['id', 'url', 'page_url']);
                }
            }
        }
        return ['paging' => new PwgNamedStruct(['page' => $params['page'], 'per_page' => $params['per_page'], 'count' => count($images), 'total_count' => $totalImages]), 'images' => new PwgNamedArray($images, 'image', $this->wsHelper->getImageXmlAttributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function getList(array $params, PwgServer &$service): PwgError|array
    {
        $currentUser = CurrentUser::get();
        $user        = $currentUser->rawAttributes;
        if (!in_array($params['thumbnail_size'], array_keys(ImageStdParams::getDefinedTypeMap()))) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid thumbnail_size');
        }
        if (!empty($params['limit']) && $params['recursive']) {
            return new PwgError(WsError::InvalidParam->value, 'Cannot use both recursive and limit parameters at the same time');
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
            $where[] = 'uppercats ' . Dml::REGEX_OPERATOR . " '(^|,)" . $getlistCatId . "(,|$)'";
        }
        if ($params['public']) {
            $where[]  = 'status = "public"';
            $where[]  = 'visible = "true"';
            $joinUser = Config::guestId();
        } elseif ($this->permissionService->isAdmin()) {
            $forbiddenCategories = $this->permissionService->calculatePermissions($currentUser->id, $currentUser->status);
            $where[]  = 'id NOT IN (' . $forbiddenCategories . ')';
            $joinType = 'LEFT';
        }
        $query = 'SELECT SQL_CALC_FOUND_ROWS id, name, comment, permalink, status, uppercats, global_rank, id_uppercat, nb_images, count_images AS total_nb_images, representative_picture_id, user_representative_picture_id, count_images, count_categories, date_last, max_date_last, count_categories AS nb_categories, image_order FROM ' . Tables::categories() . ' ' . $joinType . ' JOIN ' . Tables::userCacheCategories() . ' ON id=cat_id AND user_id=' . $joinUser . ' WHERE ' . implode("\n    AND ", $where);
        if (isset($params['search']) && $params['search'] !== '') {
            $query .= ' AND name LIKE ' . $this->conn->quote('%' . (is_string($params['search']) ? $params['search'] : '') . '%');
            if (!isset($params['limit'])) {
                $query .= ' LIMIT ' . Config::linkedAlbumSearchLimit();
            }
        }
        $limitRaw = $params['limit'] ?? null;
        $limitParam = is_numeric($limitRaw) ? (int) $limitRaw : 0;
        $catIdRaw = $params['cat_id'] ?? null;
        $catIdParam = is_numeric($catIdRaw) ? (int) $catIdRaw : 0;
        if (isset($params['limit'])) {
            $query .= ' ORDER BY `rank` ASC LIMIT ' . ($limitParam + ($catIdParam > 0 ? 1 : 0));
        }
        $query .= ';';
        $getListConn = $this->conn;
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
            $row['url'] = $this->urlService->makeIndexUrl(['category' => $row]);
            foreach (['id', 'nb_images', 'total_nb_images', 'nb_categories'] as $key) {
                $rowKeyVal = $row[$key] ?? null;
                $row[$key] = is_numeric($rowKeyVal) ? (int) $rowKeyVal : 0;
            }
            $fullnameParam = $params['fullname'] ?? false;
            if ($fullnameParam !== false && $fullnameParam !== '' && $fullnameParam !== 0) {
                $uppercatsRaw = $row['uppercats'] ?? null;
                $row['name'] = strip_tags($this->htmlService->getCatDisplayNameCache(is_string($uppercatsRaw) ? $uppercatsRaw : '', null));
            } else {
                $row['name_raw']  = $row['name'];
                $listRenderEvent  = new RenderCategoryName(is_string($row['name'] ?? null) ? $row['name'] : '', 'ws_categories_getList');
                $this->dispatcher->dispatch($listRenderEvent);
                $row['name']      = strip_tags($listRenderEvent->categoryName);
            }
            $row['comment_raw'] = $row['comment'];
            $row['comment']     = EventDispatcher::dispatch('render_category_description', is_string($row['comment'] ?? null) ? $row['comment'] : '', 'ws_categories_getList');
            $imageId            = null;
            if (!empty($row['user_representative_picture_id'])) {
                $imageId = $row['user_representative_picture_id'];
            } elseif (!empty($row['representative_picture_id'])) {
                $imageId = $row['representative_picture_id'];
            } elseif (Config::allowRandomRepresentative()) {
                $imageId = $this->categoryService->getRandomImageInCategory($row);
            } else {
                if ($row['count_categories'] > 0 && $row['count_images'] > 0) {
                    $rowUppercatsRaw = $row['uppercats'] ?? null;
                    $subQuery = 'SELECT representative_picture_id FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::userCacheCategories() . ' ON id=cat_id AND user_id=' . $currentUser->id . " WHERE uppercats LIKE '" . (is_string($rowUppercatsRaw) ? $rowUppercatsRaw : '') . ",%' AND representative_picture_id IS NOT NULL" . $this->permissionService->getSqlConditionFandF(['visible_categories' => 'id'], "\n  AND") . ' ORDER BY ' . Dml::RANDOM_FUNCTION . '() LIMIT 1;';
                    $subval = $this->conn->executeQuery($subQuery)->fetchOne();
                    if ($subval !== false) {
                        $imageId = is_numeric($subval) ? (int) $subval : null;
                    }
                }
            }
            if (isset($imageId)) {
                if (Config::representativeCacheOnSubcats() && ($row['user_representative_picture_id'] ?? null) != $imageId) {
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
        usort($cats, $this->categoryService->globalRankCompare(...));
        $thumbnailSrcOf = [];
        if (count($categories) > 0) {
            $newImageIds  = [];
            $thumbSizeRaw  = $params['thumbnail_size'] ?? null;
            $thumbnailSize = is_string($thumbSizeRaw) ? $thumbSizeRaw : '';
            $imgRepoWsCats = $this->imageRepository;
            foreach ($imgRepoWsCats->findByIds(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $imageIds)) as $row) {
                if ($row['level'] <= $user['level']) {
                    $thumbnailSrcOf[is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''] = DerivativeImage::url($thumbnailSize, $row);
                } else {
                    foreach ($categories as &$category) {
                        if ($row['id'] == $category['representative_picture_id']) {
                            $newImgId = $this->categoryService->getRandomImageInCategory($category);
                            if (isset($newImgId) && !in_array($newImgId, $imageIds)) {
                                $newImageIds[] = $newImgId;
                            }
                            if (Config::representativeCacheOnLevel()) {
                                $catIdKey = is_scalar($category['id'] ?? null) ? (string) $category['id'] : '';
                                $userRepresentativeUpdatesFor[$catIdKey] = $newImgId;
                            }
                            $category['representative_picture_id'] = $newImgId;
                        }
                    }
                    unset($category);
                }
            }
            if (count($newImageIds) > 0) {
                foreach ($imgRepoWsCats->findByIds(array_map(intval(...), $newImageIds)) as $row) {
                    $thumbnailSrcOf[is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''] = DerivativeImage::url($thumbnailSize, $row);
                }
            }
        }
        if (!$params['public'] && count($userRepresentativeUpdatesFor)) {
            $updates = [];
            foreach ($userRepresentativeUpdatesFor as $catId => $imageId) {
                $updates[] = ['user_id' => $user['id'], 'cat_id' => $catId, 'user_representative_picture_id' => $imageId];
            }
            Dml::massUpdates(Tables::userCacheCategories(), ['primary' => ['user_id', 'cat_id'], 'update' => ['user_representative_picture_id']], $updates);
        }
        foreach ($cats as &$cat) {
            foreach ($categories as $category) {
                if ($category['id'] == $cat['id'] && $category['representative_picture_id'] !== null) {
                    $repKey = is_scalar($category['representative_picture_id']) ? (string) $category['representative_picture_id'] : '';
                    $cat['tn_url'] = $thumbnailSrcOf[$repKey] ?? null;
                }
            }
            unset($cat['user_representative_picture_id'], $cat['count_images'], $cat['count_categories']);
        }
        unset($cat);
        if ($params['tree_output']) {
            return $this->wsHelper->categoriesFlatlistToTree($cats);
        }
        $output['categories'] = new PwgNamedArray($cats, 'category', $this->wsHelper->getCategoryXmlAttributes());
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
        $params['additional_output'] = array_map(trim(...), explode(',', is_string($params['additional_output']) ? $params['additional_output'] : ''));
        $nbImagesOf = array_column($this->conn->executeQuery('SELECT category_id, COUNT(*) AS counter FROM ' . Tables::imageCategory() . ' GROUP BY category_id;')->fetchAllAssociative(), 'counter', 'category_id');
        $where      = ['1=1'];
        $adminCatId = is_numeric($params['cat_id']) ? (int) $params['cat_id'] : 0;
        if (!$params['recursive']) {
            if ($adminCatId > 0) {
                $where[] = '(id_uppercat = ' . $adminCatId . ' OR id=' . $adminCatId . ')';
            } else {
                $where[] = 'id_uppercat IS NULL';
            }
        } elseif ($adminCatId > 0) {
            $where[] = 'uppercats ' . Dml::REGEX_OPERATOR . " '(^|,)" . $adminCatId . "(,|$)'";
        }
        $query = 'SELECT SQL_CALC_FOUND_ROWS id, name, comment, uppercats, global_rank, dir, status, image_order FROM ' . Tables::categories() . ' WHERE ' . implode("\n    AND ", $where);
        if (isset($params['search']) && $params['search'] !== '') {
            $query .= ' AND name LIKE ' . $this->conn->quote('%' . (is_string($params['search']) ? $params['search'] : '') . '%') . ' LIMIT ' . Config::linkedAlbumSearchLimit();
        }
        $query     .= ';';
        $searchConn = $this->conn;
        $searchRows = $searchConn->executeQuery($query)->fetchAllAssociative();
        $counter    = $searchConn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
        $cats       = [];
        foreach ($searchRows as $row) {
            $rowIdRaw        = $row['id'] ?? null;
            $id              = is_string($rowIdRaw) ? $rowIdRaw : '';
            $row['nb_images'] = $nbImagesOf[$id] ?? 0;
            $rowUppercatsRaw = $row['uppercats'] ?? null;
            $catDisplayName  = $this->htmlService->getCatDisplayNameCache(is_string($rowUppercatsRaw) ? $rowUppercatsRaw : '', $this->urlGenerator->admin() . '&page=album-');
            $row['name_raw'] = $row['name'];
            $adminRenderEvent = new RenderCategoryName(is_string($row['name'] ?? null) ? $row['name'] : '', 'ws_categories_getAdminList');
            $this->dispatcher->dispatch($adminRenderEvent);
            $row['name']     = strip_tags($adminRenderEvent->categoryName);
            $row['fullname'] = strip_tags($catDisplayName);
            $row['comment_raw'] = $row['comment'];
            $row['comment']  = EventDispatcher::dispatch('render_category_description', $row['comment'] ?? '', 'ws_categories_getAdminList');
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
                $nbSubcatsOf = array_column($this->conn->executeQuery('SELECT id_uppercat, COUNT(*) AS nb_subcats FROM ' . Tables::categories() . ' WHERE id_uppercat IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $catsIds)) . ') GROUP BY id_uppercat;')->fetchAllAssociative(), 'nb_subcats', 'id_uppercat');
            }
            foreach ($cats as $idx => $cat) {
                $catIdRaw2           = $cat['id'] ?? null;
                $catIdKey            = is_string($catIdRaw2) ? $catIdRaw2 : '';
                $nbSubcatsRaw        = $nbSubcatsOf[$catIdKey] ?? null;
                $cats[$idx]['nb_categories'] = is_numeric($nbSubcatsRaw) ? (int) $nbSubcatsRaw : 0;
            }
        }
        $limitReached = ($counter > Config::linkedAlbumSearchLimit());
        usort($cats, $this->categoryService->globalRankCompare(...));
        return ['categories' => new PwgNamedArray($cats, 'category', ['id', 'nb_images', 'name', 'uppercats', 'global_rank', 'status', 'test']), 'limit' => Config::linkedAlbumSearchLimit(), 'limit_reached' => $limitReached];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function add(array $params, PwgServer &$service): PwgError|array
    {
        if (isset($params['pwg_token']) && $this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!empty($params['position']) && in_array($params['position'], ['first', 'last'])) {
            Config::override('newcat_default_position', is_string($params['position']) ? $params['position'] : '');
        }
        $options = [];
        if (!empty($params['status']) && in_array($params['status'], ['private', 'public'])) {
            $options['status'] = $params['status'];
        }
        if (!empty($params['comment'])) {
            $commentStr    = is_string($params['comment']) ? $params['comment'] : '';
            $options['comment'] = (!Config::allowHtmlDescriptions() || !isset($params['pwg_token'])) ? strip_tags($commentStr) : $commentStr;
        }
        $catNameRaw = $params['name'] ?? null;
        $catNameStr = is_string($catNameRaw) ? $catNameRaw : '';
        $catName   = (!Config::allowHtmlDescriptions() || !isset($params['pwg_token'])) ? strip_tags($catNameStr) : $catNameStr;
        $catParent = is_numeric($params['parent']) ? (int) $params['parent'] : (is_string($params['parent']) ? $params['parent'] : null);
        $creationOutput = $this->categoryAdminService->createVirtualCategory($catName, $catParent, $options);
        if (isset($creationOutput['error'])) {
            return new PwgError(500, is_string($creationOutput['error']) ? $creationOutput['error'] : '');
        }
        $this->userAdminService->invalidateUserCache();
        return $creationOutput;
    }

    /** @param array<mixed> $params */
    public function setRank(array $params, PwgServer &$service): mixed
    {
        $rawSetrankIds  = is_array($params['category_id']) ? $params['category_id'] : [];
        /** @var int[] $setrankCategoryIds */
        $setrankCategoryIds = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawSetrankIds);
        $categories     = $this->conn->executeQuery('SELECT id, id_uppercat, `rank` FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $setrankCategoryIds) . ');')->fetchAllAssociative();
        if (count($categories) === 0) {
            return new PwgError(404, 'category_id not found');
        }
        $category = $categories[0];
        if (count($setrankCategoryIds) > 1) {
            $orderNew      = $setrankCategoryIds;
            $orderNewById  = $orderNew;
            sort($orderNewById, SORT_NUMERIC);
            $catAsc        = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::categories() . ' WHERE id_uppercat ' . (empty($category['id_uppercat']) ? 'IS NULL' : '= ' . (is_scalar($category['id_uppercat']) ? (string) $category['id_uppercat'] : '0')) . ' ORDER BY `id` ASC;')->fetchAllAssociative(), 'id');
            $catAscStr     = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $catAsc);
            $orderNewStr   = array_map(fn (int $v): string => (string) $v, $orderNewById);
            if (strcmp(implode(',', $catAscStr), implode(',', $orderNewStr)) !== 0) {
                return new PwgError(WsError::InvalidParam->value, 'you need to provide all sub-category ids for a given category');
            }
            $orderNew = $setrankCategoryIds;
        } else {
            $singleCatId    = implode('', array_map(fn (int $v): string => (string) $v, $setrankCategoryIds));
            $idUppercatRaw  = $category['id_uppercat'] ?? null;
            $idUppercatStr  = is_string($idUppercatRaw) ? $idUppercatRaw : '';
            $orderOld       = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::categories() . ' WHERE id_uppercat ' . ($idUppercatStr === '' ? 'IS NULL' : '= ' . $idUppercatStr) . ' AND id != ' . $singleCatId . ' ORDER BY `rank` ASC;')->fetchAllAssociative(), 'id');
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
        $this->categoryAdminService->saveCategoriesOrder($orderNew);
        return null;
    }

    /** @param array<mixed> $params */
    public function setInfo(array $params, PwgServer &$service): mixed
    {
        if (isset($params['pwg_token']) && $this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $categoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $categories = $this->conn->executeQuery('SELECT * FROM ' . Tables::categories() . ' WHERE id = ' . $categoryId . ';')->fetchAllAssociative();
        if (count($categories) === 0) {
            return new PwgError(404, 'category_id not found');
        }
        $category = $categories[0];
        if (!empty($params['status'])) {
            if (!in_array($params['status'], ['private', 'public'])) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid status, only public/private');
            }
            if ($params['status'] !== $category['status']) {
                $this->categoryAdminService->setCatStatus([$categoryId], is_string($params['status']) ? $params['status'] : '');
            }
        }
        $update = ['id' => $categoryId];
        foreach (['visible', 'commentable'] as $paramName) {
            $paramValStr = is_scalar($params[$paramName] ?? null) ? (string) $params[$paramName] : '';
            if (isset($params[$paramName]) && !preg_match('/^(true|false)$/i', $paramValStr)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid param ' . $paramName . ' : ' . $paramValStr);
            }
        }
        if (!empty($params['visible']) && ($params['visible'] !== $category['visible'])) {
            $this->categoryAdminService->setCatVisible([$categoryId], is_string($params['visible']) ? $params['visible'] : (is_bool($params['visible']) ? $params['visible'] : false));
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
            $subcats = $this->categoryService->getSubcatIds([$categoryId]);
            if (count($subcats) > 0) {
                $commentableVal = is_string($params['commentable']) ? $params['commentable'] : 'false';
                $this->categoryRepository->setCommentable(array_map(intval(...), $subcats), $commentableVal === 'true');
            }
        }
        if ($performUpdate) {
            Dml::singleUpdate(Tables::categories(), $update, ['id' => $update['id']]);
        }
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $categoryId, 'edit', ['fields' => implode(',', array_keys($update))]));
        return null;
    }

    /** @param array<mixed> $params */
    public function setRepresentative(array $params, PwgServer &$service): mixed
    {
        $categoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $imageId    = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $catRepo    = $this->categoryRepository;
        $imgRepo    = $this->imageRepository;
        if (!$catRepo->existsById($categoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        if (!$imgRepo->existsById($imageId)) {
            return new PwgError(404, 'image_id not found');
        }
        $catRepo->setRepresentativePicture([$categoryId], $imageId);
        $this->userRepository->clearUserRepresentativeForCategory($categoryId);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $categoryId, 'edit', ['image_id' => $imageId]));
        return null;
    }

    /** @param array<mixed> $params */
    public function deleteRepresentative(array $params, PwgServer &$service): mixed
    {
        $categoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $catRepo2   = $this->categoryRepository;
        if (!$catRepo2->existsById($categoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        $nbImages = $catRepo2->countImagesByCategoryId($categoryId);
        if (!Config::allowRandomRepresentative() && $nbImages !== 0) {
            return new PwgError(401, 'not permitted');
        }
        $catRepo2->clearRepresentatives([$categoryId]);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $categoryId, 'edit'));
        return null;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function refreshRepresentative(array $params, PwgServer &$service): PwgError|array
    {
        $categoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $catRepo3   = $this->categoryRepository;
        if (!$catRepo3->existsById($categoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        if (!$catRepo3->hasCategoryImages($categoryId)) {
            return new PwgError(401, 'not permitted');
        }
        $this->categoryAdminService->setRandomRepresentant([$categoryId]);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $categoryId, 'edit'));
        $category = $catRepo3->findCategoryById($categoryId);
        $repId    = isset($category['representative_picture_id']) ? (is_scalar($category['representative_picture_id']) ? (string) $category['representative_picture_id'] : '') : '';
        return $this->imageAdminService->getCategoryRepresentantProperties($repId, DerivativeSize::Small->value);
    }

    /** @param array<mixed> $params */
    public function delete(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $photoDeletionMode = is_string($params['photo_deletion_mode'] ?? null) ? $params['photo_deletion_mode'] : '';
        $modes = ['no_delete', 'delete_orphans', 'force_delete'];
        if (!in_array($photoDeletionMode, $modes)) {
            return new PwgError(500, '[ws_categories_delete] invalid parameter photo_deletion_mode "' . $photoDeletionMode . '", possible values are {' . implode(', ', $modes) . '}.');
        }
        if (!is_array($params['category_id'])) {
            $splitResult = preg_split('/[\s,;\|]/', is_string($params['category_id']) ? $params['category_id'] : '', -1, PREG_SPLIT_NO_EMPTY);
            $params['category_id'] = $splitResult !== false ? $splitResult : [];
        }
        $params['category_id'] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['category_id']);
        $categoryIds = array_filter($params['category_id'], fn (int $v): bool => $v > 0);
        if (count($categoryIds) === 0) {
            return null;
        }
        $rawCategoryIds = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $categoryIds) . ');')->fetchAllAssociative(), 'id');
        if (count($rawCategoryIds) === 0) {
            return null;
        }
        $this->categoryAdminService->deleteCategories(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawCategoryIds), $photoDeletionMode);
        $this->categoryAdminService->updateGlobalRank();
        $this->userAdminService->invalidateUserCache();
        return null;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function move(array $params, PwgServer &$service): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!is_array($params['category_id'])) {
            $splitResult = preg_split('/[\s,;\|]/', is_string($params['category_id']) ? $params['category_id'] : '', -1, PREG_SPLIT_NO_EMPTY);
            $params['category_id'] = $splitResult !== false ? $splitResult : [];
        }
        $params['category_id'] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['category_id']);
        $categoryIds = array_filter($params['category_id'], fn (int $v): bool => $v > 0);
        if (count($categoryIds) === 0) {
            return new PwgError(403, 'Invalid category_id input parameter, no category to move');
        }
        $categoriesInDb = [];
        $updateCatIds   = [];
        $parentId       = is_numeric($params['parent']) ? (int) $params['parent'] : 0;
        foreach ($this->categoryRepository->findByIds(array_map(intval(...), $categoryIds)) as $row) {
            $rowIdRaw3          = $row['id'] ?? null;
            $rowId              = is_string($rowIdRaw3) ? $rowIdRaw3 : '';
            $categoriesInDb[$rowId] = $row;
            $rowUppercatsRaw2   = $row['uppercats'] ?? null;
            $updateCatIds = array_merge($updateCatIds, array_slice(explode(',', is_string($rowUppercatsRaw2) ? $rowUppercatsRaw2 : ''), 0, -1));
            if (!empty($row['dir'])) {
                $moveRenderEvent = new RenderCategoryName(is_string($row['name']) ? $row['name'] : '', 'ws_categories_move');
                $this->dispatcher->dispatch($moveRenderEvent);
                $row['name'] = strip_tags($moveRenderEvent->categoryName);
                return new PwgError(403, sprintf('Category %s (%u) is not a virtual category, you cannot move it', $row['name'], is_numeric($row['id']) ? (int) $row['id'] : 0));
            }
        }
        if (count($categoriesInDb) !== count($categoryIds)) {
            $unknownCategoryIds = array_diff($categoryIds, array_keys($categoriesInDb));
            return new PwgError(403, sprintf('Category %u does not exist', $unknownCategoryIds[0]));
        }
        if ($parentId !== 0) {
            $subcatIds = $this->categoryService->getSubcatIds([$parentId]);
            if (count($subcatIds) === 0) {
                return new PwgError(403, 'Unknown parent category id');
            }
        }
        $this->categoryAdminService->moveCategories($categoryIds, $parentId);
        $this->userAdminService->invalidateUserCache();
        $catDisplayName = '';
        foreach ($this->categoryRepository->findUppercatsByIds(array_map(intval(...), $categoryIds)) as $uppercatsStr) {
            $catDisplayName = $this->htmlService->getCatDisplayNameCache($uppercatsStr, $this->urlGenerator->admin() . '&page=album-');
            $updateCatIds   = array_merge($updateCatIds, array_slice(explode(',', $uppercatsStr), 0, -1));
        }
        $nbPhotosIn = array_column($this->conn->executeQuery('SELECT category_id, COUNT(*) AS nb_photos FROM ' . Tables::imageCategory() . ' GROUP BY category_id;')->fetchAllAssociative(), 'nb_photos', 'category_id');
        $updateCats = [];
        foreach (array_unique($updateCatIds) as $updateCat) {
            $nbSubPhotos      = 0;
            $subCatWithoutParent = array_diff($this->categoryService->getSubcatIds([$updateCat]), [$updateCat]);
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
        $category['has_images'] = $this->categoryRepository->hasCategoryImages($categoryId);
        $subcatIds     = $this->categoryService->getSubcatIds([$categoryId]);
        $category['nb_subcats'] = count($subcatIds) - 1;
        $imageIdsRecursive = array_column($this->conn->executeQuery('SELECT DISTINCT(image_id) FROM ' . Tables::imageCategory() . ' WHERE category_id IN (' . implode(',', $subcatIds) . ');')->fetchAllAssociative(), 'image_id');
        $category['nb_images_recursive'] = count($imageIdsRecursive);
        $category['nb_images_becoming_orphan']     = 0;
        $category['nb_images_associated_outside']  = 0;
        if ($category['nb_images_recursive'] > 0) {
            if ($category['nb_images_recursive'] < 1000) {
                $imageIdsAssociatedOutside = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column($this->conn->executeQuery('SELECT DISTINCT(image_id) FROM ' . Tables::imageCategory() . ' WHERE category_id NOT IN (' . implode(',', $subcatIds) . ') AND image_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $imageIdsRecursive)) . ');')->fetchAllAssociative(), 'image_id'));
                $category['nb_images_associated_outside'] = count($imageIdsAssociatedOutside);
                $imageIdsBecomingOrphan = array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $imageIdsRecursive), $imageIdsAssociatedOutside);
                $category['nb_images_becoming_orphan'] = count($imageIdsBecomingOrphan);
            } else {
                $imageIdsRecursiveKeys = array_flip(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $imageIdsRecursive));
                $imageIdsAssociatedOutside2 = array_column($this->conn->executeQuery('SELECT image_id FROM ' . Tables::imageCategory() . ' WHERE category_id NOT IN (' . implode(',', $subcatIds) . ');')->fetchAllAssociative(), 'image_id');
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
