<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
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
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Event\Template\RenderCategoryDescription;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\OrderByService;
use Piwigo\Image\SrcImage;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Piwigo\Ws\OpenApi\ApiMethod;
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
        private OrderByService $orderByService,
    ) {
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Returns elements for the corresponding categories. cat_id can be empty if recursive is true.', tags: ['categories'])]
    public function getImages(array $params, PwgServer &$service): PwgError|array
    {
        $rawCatId = is_array($params['cat_id']) ? $params['cat_id'] : [];
        /** @var int[] $catIds */
        $catIds = array_values(array_unique(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawCatId)));
        if (count($catIds) > 0) {
            $dbCatIds = $this->categoryRepository->findExistingIdsAmong($catIds);
            $missingCatIds = array_values(array_diff($catIds, $dbCatIds));
            if (count($missingCatIds) > 0) {
                return new PwgError(404, 'cat_id {' . implode(',', $missingCatIds) . '} not found');
            }
        }
        $images      = [];
        $imageIds    = [];
        $totalImages = 0;
        [$permSql1, $permParams1, $permTypes1] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'id'], null, true);
        $cats = $this->categoryRepository->findIdAndImageOrderForGetImages(
            $catIds,
            (bool) $params['recursive'],
            'AND ' . $permSql1,
            $permParams1,
            $permTypes1,
        );
        if (!empty($cats)) {
            /** @var list<string> $whereClauses2 */
            $whereClauses2   = $this->wsHelper->imageSqlFilter($params, 'i.');
            $whereClauses2[] = 'category_id IN (' . implode(',', array_keys($cats)) . ')';
            [$permSql2, $permParams2, $permTypes2] = $this->permissionService->getSqlConditionFandF(['visible_images' => 'i.id'], null, true);
            $whereClauses2[] = $permSql2;
            $orderBy         = $this->wsHelper->imageSqlOrder($params, 'i.');
            if (empty($orderBy) && count($catIds) === 1 && isset($cats[$catIds[0]]['image_order'])) {
                $orderBy = is_scalar($cats[$catIds[0]]['image_order']) ? (string) $cats[$catIds[0]]['image_order'] : '';
            }
            $orderBy     = empty($orderBy) ? $this->orderByService->buildOrderByClause(Config::orderBy()) : 'ORDER BY ' . $orderBy;
            $favoriteIds = $this->urlService->getUserFavorites();
            $perPage     = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
            $page        = is_numeric($params['page']) ? (int) $params['page'] : 0;
            $paginated   = $this->imageRepository->findCategoryImagesPaginated(
                $whereClauses2,
                $orderBy,
                $perPage,
                $perPage * $page,
                $permParams2,
                $permTypes2,
            );
            foreach ($paginated['rows'] as $img) {
                $imgIdInt    = $img->id->value;
                $imageIds[]  = $imgIdInt;
                $image       = [
                    'is_favorite'    => isset($favoriteIds[$imgIdInt]),
                    'id'             => $imgIdInt,
                    'width'          => $img->width ?? 0,
                    'height'         => $img->height ?? 0,
                    'hit'            => $img->hit,
                    'file'           => $img->file->value,
                    'name'           => $img->name,
                    'comment'        => $img->comment,
                    'date_creation'  => $img->dateCreation?->value,
                    'date_available' => $img->dateAvailable?->value,
                ];
                $renderEvent = new RenderElementName($img->name ?? '', $image);
                $this->dispatcher->dispatch($renderEvent);
                $image['name']    = strip_tags($renderEvent->elementName);
                $descEvent        = new RenderElementDescription($img->comment ?? '', __FUNCTION__);
                $this->dispatcher->dispatch($descEvent);
                $image['comment'] = $descEvent->elementDescription;
                $image = array_merge($image, $this->wsHelper->getUrls($img->toRow()));
                $images[] = $image;
            }
            $totalImages = $paginated['total'];
            if (count($imageIds) > 0) {
                $categoryIds = [];
                $categoriesOfImage = [];
                [$permSql3, $permParams3, $permTypes3] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id'], null, true);
                foreach ($this->categoryRepository->findImageCategoryPairsWithPermissions($imageIds, 'AND ' . $permSql3, $permParams3, $permTypes3) as $row) {
                    $categoryIds[]     = $row['category_id'];
                    $rowImgId          = (string) $row['image_id'];
                    $categoriesOfImage[$rowImgId][] = $row['category_id'];
                }
                $detailsForCategory = [];
                if (count($categoryIds) > 0) {
                    $detailsForCategory = $this->categoryRepository->findNamePermalinkByIdsKeyedById($categoryIds);
                }
                foreach ($images as $idx => $image) {
                    $imageCats  = [];
                    $imageIdRaw = $image['id'] ?? null;
                    $imageIdKey = is_string($imageIdRaw) ? $imageIdRaw : '';
                    if (!isset($categoriesOfImage[$imageIdKey])) {
                        continue;
                    }
                    foreach ($categoriesOfImage[$imageIdKey] as $catId) {
                        $catIdKey = (string) $catId;
                        if (!isset($detailsForCategory[$catIdKey])) {
                            continue;
                        }
                        $url     = $this->urlService->makeIndexUrl(['category' => $detailsForCategory[$catIdKey]]);
                        $pageUrl = $this->urlService->makePictureUrl(['category' => $detailsForCategory[$catIdKey], 'image_id' => $image['id'] ?? null, 'image_file' => $image['file']]);
                        $imageCats[] = ['id' => $catId, 'url' => $url, 'page_url' => $pageUrl];
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
    #[ApiMethod(summary: 'Returns a list of categories.', tags: ['categories'])]
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
            $where[] = "uppercats REGEXP '(^|,)" . $getlistCatId . "(,|$)'";
        }
        $listPermParams = [];
        $listPermTypes  = [];
        if ($params['public']) {
            $where[]  = 'status = "public"';
            // `visible` is TINYINT(1) post-E2; 1 = shown, 0 = locked.
            // The pre-E2 'visible = "true"' silently coerced to `visible = 0`,
            // returning LOCKED rows for guest queries — fixed to `visible = 1`.
            $where[]  = 'visible = 1';
            $joinUser = Config::guestId();
        } elseif ($this->permissionService->isAdmin()) {
            $forbiddenCategories = $this->permissionService->calculatePermissions($currentUser->id, $currentUser->status);
            $where[]  = 'id NOT IN (?)';
            $listPermParams = [$forbiddenCategories];
            $listPermTypes  = [ArrayParameterType::INTEGER];
            $joinType       = 'LEFT';
        }
        if (isset($params['search']) && $params['search'] !== '') {
            $where[] = 'name LIKE ?';
            $listPermParams[] = '%' . (is_string($params['search']) ? $params['search'] : '') . '%';
            $listPermTypes[]  = ParameterType::STRING;
        }
        $limitRaw = $params['limit'] ?? null;
        $limitParam = is_numeric($limitRaw) ? (int) $limitRaw : 0;
        $catIdRaw = $params['cat_id'] ?? null;
        $catIdParam = is_numeric($catIdRaw) ? (int) $catIdRaw : 0;
        $orderLimit = '';
        $useFoundRows = false;
        if (isset($params['limit'])) {
            $orderLimit   = 'ORDER BY `rank` ASC LIMIT ' . ($limitParam + ($catIdParam > 0 ? 1 : 0));
            $useFoundRows = true;
        } elseif (isset($params['search']) && $params['search'] !== '') {
            $orderLimit = 'LIMIT ' . Config::linkedAlbumSearchLimit();
        }
        $page = $this->categoryRepository->findGetListPage(
            $joinType,
            $joinUser,
            $where,
            $orderLimit,
            $useFoundRows,
            $listPermParams,
            $listPermTypes,
        );
        $getListRows = $page['rows'];
        if ($useFoundRows && $page['total'] !== null) {
            $resultCountInt = $page['total'];
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
                $rawName          = $row['name'] ?? null;
                $listRenderEvent  = new RenderCategoryName(is_string($rawName) ? $rawName : '', 'ws_categories_getList');
                $this->dispatcher->dispatch($listRenderEvent);
                $row['name']      = strip_tags($listRenderEvent->categoryName);
            }
            $row['comment_raw'] = $row['comment'];
            $rawComment         = $row['comment'] ?? null;
            $catDescEvent       = new RenderCategoryDescription(is_string($rawComment) ? $rawComment : '', 'ws_categories_getList');
            $this->dispatcher->dispatch($catDescEvent);
            $row['comment']     = $catDescEvent->categoryDescription;
            $imageId            = null;
            if (!empty($row['user_representative_picture_id'])) {
                $imageId = $row['user_representative_picture_id'];
            } elseif (!empty($row['representative_picture_id'])) {
                $imageId = $row['representative_picture_id'];
            } elseif (Config::allowRandomRepresentative()) {
                $imageId = $this->categoryService->getRandomImageInCategory($row);
            } else {
                if ($row['count_categories'] > 0 && $row['count_images'] > 0) {
                    $rowUppercatsAny = $row['uppercats'] ?? null;
                    $rowUppercatsRaw = is_string($rowUppercatsAny) ? $rowUppercatsAny : '';
                    [$permSubSql, $permSubParams, $permSubTypes] = $this->permissionService->getSqlConditionFandF(['visible_categories' => 'id'], "\n  AND");
                    $imageId = $this->categoryRepository->findRandomSubcatRepresentativeForUser(
                        $currentUser->id,
                        $rowUppercatsRaw,
                        $permSubSql,
                        $permSubParams,
                        $permSubTypes,
                    );
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
                $row['image_order'] = $this->orderByService->buildBareOrderByClause(Config::orderBy());
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
            $userLevel = is_numeric($user['level'] ?? null) ? (int) $user['level'] : 0;
            foreach ($imgRepoWsCats->findByIds(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $imageIds)) as $img) {
                $imgIdStr = (string) $img->id->value;
                if ($img->level <= $userLevel) {
                    $thumbnailSrcOf[$imgIdStr] = DerivativeImage::url($thumbnailSize, SrcImage::fromImage($img));
                } else {
                    foreach ($categories as &$category) {
                        if ($img->id->value == $category['representative_picture_id']) {
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
                foreach ($imgRepoWsCats->findByIds(array_map(intval(...), $newImageIds)) as $img) {
                    $thumbnailSrcOf[(string) $img->id->value] = DerivativeImage::url($thumbnailSize, SrcImage::fromImage($img));
                }
            }
        }
        if (!$params['public'] && count($userRepresentativeUpdatesFor)) {
            $updates = [];
            foreach ($userRepresentativeUpdatesFor as $catId => $imageId) {
                $updates[] = ['cat_id' => $catId, 'image_id' => is_numeric($imageId) ? (int) $imageId : null];
            }
            $userIdInt = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
            $this->categoryRepository->setUserRepresentativeBatch($userIdInt, $updates);
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
    #[ApiMethod(summary: 'Get albums list as displayed on admin page.', tags: ['categories'])]
    public function getAdminList(array $params, PwgServer &$service): array
    {
        if (!isset($params['additional_output'])) {
            $params['additional_output'] = '';
        }
        $params['additional_output'] = array_map(trim(...), explode(',', is_string($params['additional_output']) ? $params['additional_output'] : ''));
        $nbImagesOf = $this->categoryRepository->findNbPhotosPerCategoryKeyedById();
        $where      = ['1=1'];
        $adminCatId = is_numeric($params['cat_id']) ? (int) $params['cat_id'] : 0;
        if (!$params['recursive']) {
            if ($adminCatId > 0) {
                $where[] = '(id_uppercat = ' . $adminCatId . ' OR id=' . $adminCatId . ')';
            } else {
                $where[] = 'id_uppercat IS NULL';
            }
        } elseif ($adminCatId > 0) {
            $where[] = "uppercats REGEXP '(^|,)" . $adminCatId . "(,|$)'";
        }
        $listParams = [];
        $listTypes  = [];
        $tail       = '';
        if (isset($params['search']) && $params['search'] !== '') {
            $where[]      = 'name LIKE ?';
            $listParams[] = '%' . (is_string($params['search']) ? $params['search'] : '') . '%';
            $listTypes[]  = ParameterType::STRING;
            $tail         = 'LIMIT ' . Config::linkedAlbumSearchLimit();
        }
        $adminPage = $this->categoryRepository->findAdminListPage($where, $tail, $listParams, $listTypes);
        $searchRows = $adminPage['rows'];
        $counter    = $adminPage['total'];
        $cats       = [];
        foreach ($searchRows as $row) {
            $rowIdRaw        = $row['id'] ?? null;
            $id              = is_string($rowIdRaw) ? $rowIdRaw : '';
            $row['nb_images'] = $nbImagesOf[$id] ?? 0;
            $rowUppercatsRaw = $row['uppercats'] ?? null;
            $catDisplayName  = $this->htmlService->getCatDisplayNameCache(is_string($rowUppercatsRaw) ? $rowUppercatsRaw : '', $this->urlGenerator->admin() . '&page=album-');
            $row['name_raw'] = $row['name'];
            $rawAdminName    = $row['name'] ?? null;
            $adminRenderEvent = new RenderCategoryName(is_string($rawAdminName) ? $rawAdminName : '', 'ws_categories_getAdminList');
            $this->dispatcher->dispatch($adminRenderEvent);
            $row['name']     = strip_tags($adminRenderEvent->categoryName);
            $row['fullname'] = strip_tags($catDisplayName);
            $row['comment_raw']  = $row['comment'];
            $adminCommentRaw     = $row['comment'] ?? '';
            $adminCatDescEvent   = new RenderCategoryDescription(is_string($adminCommentRaw) ? $adminCommentRaw : '', 'ws_categories_getAdminList');
            $this->dispatcher->dispatch($adminCatDescEvent);
            $row['comment']      = $adminCatDescEvent->categoryDescription;
            if (empty($row['image_order'])) {
                $row['image_order'] = $this->orderByService->buildBareOrderByClause(Config::orderBy());
            }
            if (in_array('full_name_with_admin_links', $params['additional_output'])) {
                $row['full_name_with_admin_links'] = $catDisplayName;
            }
            $cats[] = $row;
        }
        if (!$params['recursive']) {
            $catsIds    = array_column($cats, 'id');
            $catsIdsInt = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catsIds);
            $nbSubcatsOf = $this->categoryRepository->countSubcatsByParentIdsKeyedByParent($catsIdsInt);
            foreach ($cats as $idx => $cat) {
                $catIdRaw2           = $cat['id'] ?? null;
                $catIdKey            = is_string($catIdRaw2) ? $catIdRaw2 : '';
                $cats[$idx]['nb_categories'] = $nbSubcatsOf[$catIdKey] ?? 0;
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
    #[ApiMethod(summary: 'Adds an album. pwg_token required if you want to use HTML in name/comment.', tags: ['categories'])]
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
    #[ApiMethod(summary: 'Changes the rank of an album.', tags: ['categories'])]
    public function setRank(array $params, PwgServer &$service): mixed
    {
        $rawSetrankIds  = is_array($params['category_id']) ? $params['category_id'] : [];
        /** @var int[] $setrankCategoryIds */
        $setrankCategoryIds = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawSetrankIds);
        $categories = $this->categoryRepository->findIdIdUppercatRankByIds($setrankCategoryIds);
        if (count($categories) === 0) {
            return new PwgError(404, 'category_id not found');
        }
        $category = $categories[0];
        if (count($setrankCategoryIds) > 1) {
            $orderNew      = $setrankCategoryIds;
            $orderNewById  = $orderNew;
            sort($orderNewById, SORT_NUMERIC);
            $parentForAsc  = empty($category['id_uppercat']) ? null : (is_numeric($category['id_uppercat']) ? (int) $category['id_uppercat'] : 0);
            $catAsc        = $this->categoryRepository->findIdsByParentOrderedById($parentForAsc);
            if ($catAsc !== $orderNewById) {
                return new PwgError(WsError::InvalidParam->value, 'you need to provide all sub-category ids for a given category');
            }
            $orderNew = $setrankCategoryIds;
        } else {
            $singleCatId    = $setrankCategoryIds[0];
            $idUppercatRaw  = $category['id_uppercat'] ?? null;
            $parentForOld   = is_numeric($idUppercatRaw) ? (int) $idUppercatRaw : null;
            $orderOld       = $this->categoryRepository->findOtherIdsByParentOrderedByRank($parentForOld, $singleCatId);
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
    #[ApiMethod(summary: 'Changes properties of an album. pwg_token required if you want to use HTML in name/comment.', tags: ['categories'])]
    public function setInfo(array $params, PwgServer &$service): mixed
    {
        if (isset($params['pwg_token']) && $this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $categoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $category   = $this->categoryRepository->findCategoryById($categoryId);
        if ($category === null) {
            return new PwgError(404, 'category_id not found');
        }
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
            $updateFields = $update;
            unset($updateFields['id']);
            $this->categoryRepository->updateById($categoryId, $updateFields);
        }
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $categoryId, 'edit', ['fields' => implode(',', array_keys($update))]));
        return null;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Sets the representative photo for an album. The photo does not have to belong to the album.', tags: ['categories'])]
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
    #[ApiMethod(summary: "Deletes the album thumbnail. Only possible if conf['allow_random_representative']", tags: ['categories'])]
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
    #[ApiMethod(summary: 'Find a new album thumbnail.', tags: ['categories'])]
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
    #[ApiMethod(summary: 'Deletes album(s). photo_deletion_mode can be no_delete, delete_orphans (default), or force_delete_orphans.', tags: ['categories'])]
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
        $rawCategoryIds = $this->categoryRepository->findExistingIdsAmong(array_values($categoryIds));
        if (count($rawCategoryIds) === 0) {
            return null;
        }
        $this->categoryAdminService->deleteCategories($rawCategoryIds, $photoDeletionMode);
        $this->categoryAdminService->updateGlobalRank();
        $this->userAdminService->invalidateUserCache();
        return null;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Move album(s). Set parent as 0 to move to gallery root. Only virtual categories can be moved.', tags: ['categories'])]
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
        $nbPhotosIn = $this->categoryRepository->findNbPhotosPerCategoryKeyedById();
        $updateCats = [];
        foreach (array_unique($updateCatIds) as $updateCat) {
            $nbSubPhotos      = 0;
            $subCatWithoutParent = array_diff($this->categoryService->getSubcatIds([$updateCat]), [$updateCat]);
            foreach ($subCatWithoutParent as $idSubCat) {
                $nbSubPhotos += $nbPhotosIn[(string) $idSubCat] ?? 0;
            }
            $updateCats[] = ['cat_id' => $updateCat, 'nb_sub_photos' => $nbSubPhotos];
        }
        return ['new_ariane_string' => $catDisplayName, 'updated_cats' => $updateCats];
    }

    /** @param array<mixed> $param */
    #[ApiMethod(summary: 'Return the number of orphan photos if an album is deleted.', tags: ['categories'])]
    public function calculateOrphans(array $param, PwgServer &$service): mixed
    {
        $paramCatId    = is_array($param['category_id']) ? $param['category_id'] : [];
        $categoryId    = is_numeric($paramCatId[0] ?? null) ? (int) $paramCatId[0] : 0;
        $category      = [];
        $category['has_images'] = $this->categoryRepository->hasCategoryImages($categoryId);
        $subcatIds     = $this->categoryService->getSubcatIds([$categoryId]);
        $category['nb_subcats'] = count($subcatIds) - 1;
        $subcatIdsInt      = array_values($subcatIds);
        $imageIdsRecursive = $this->categoryRepository->findImageIdsLinkedToCategories($subcatIdsInt);
        $category['nb_images_recursive'] = count($imageIdsRecursive);
        $category['nb_images_becoming_orphan']     = 0;
        $category['nb_images_associated_outside']  = 0;
        if ($category['nb_images_recursive'] > 0) {
            if ($category['nb_images_recursive'] < 1000) {
                $imageIdsAssociatedOutside = $this->categoryRepository->findImageIdsAssociatedOutsideCategories($subcatIdsInt, $imageIdsRecursive);
                $category['nb_images_associated_outside'] = count($imageIdsAssociatedOutside);
                $category['nb_images_becoming_orphan']    = count(array_diff($imageIdsRecursive, $imageIdsAssociatedOutside));
            } else {
                $recursiveKeys     = array_flip($imageIdsRecursive);
                $outsideAll        = $this->categoryRepository->findImageIdsAssociatedOutsideCategoriesAll($subcatIdsInt);
                $imageIdsNotOrphan = [];
                foreach ($outsideAll as $imageId) {
                    if (isset($recursiveKeys[$imageId])) {
                        $imageIdsNotOrphan[] = $imageId;
                    }
                }
                $category['nb_images_associated_outside'] = count(array_unique($imageIdsNotOrphan));
                $category['nb_images_becoming_orphan']    = count(array_diff($imageIdsRecursive, $imageIdsNotOrphan));
            }
        }
        return [['nb_images_associated_outside' => $category['nb_images_associated_outside'], 'nb_images_becoming_orphan' => $category['nb_images_becoming_orphan'], 'nb_images_recursive' => $category['nb_images_recursive']]];
    }
}
