<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Event\Template\RenderCategoryDescription;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\OrderByService;
use Piwigo\Image\SrcImage;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsHelper;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.categories.getList` — primary album list with permission filtering, thumbnails, and tree-output. */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private EventDispatcherInterface $dispatcher,
        private HtmlService $htmlService,
        private ImageRepository $imageRepository,
        private OrderByService $orderByService,
        private PermissionService $permissionService,
        private UrlService $urlService,
        private WsHelper $wsHelper,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $currentUser = CurrentUser::get();
        $user        = $currentUser->rawAttributes;
        if (!in_array($params['thumbnail_size'], array_keys(ImageStdParams::getDefinedTypeMap()))) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid thumbnail_size');
        }
        if (!empty($params['limit']) && $params['recursive']) {
            return new PwgError(WsError::InvalidParam->value, 'Cannot use both recursive and limit parameters at the same time');
        }
        $output       = [];
        $where        = ['1=1'];
        $joinType     = 'INNER';
        $joinUser     = $currentUser->id;
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
            $where[]  = 'visible = 1';
            $joinUser = Config::guestId();
        } elseif ($this->permissionService->isAdmin()) {
            $forbiddenCategories = $this->permissionService->calculatePermissions($currentUser->id, $currentUser->status);
            $where[]             = 'id NOT IN (?)';
            $listPermParams      = [$forbiddenCategories];
            $listPermTypes       = [ArrayParameterType::INTEGER];
            $joinType            = 'LEFT';
        }
        if (isset($params['search']) && $params['search'] !== '') {
            $where[]          = 'name LIKE ?';
            $listPermParams[] = '%' . (is_string($params['search']) ? $params['search'] : '') . '%';
            $listPermTypes[]  = ParameterType::STRING;
        }
        $limitRaw     = $params['limit'] ?? null;
        $limitParam   = is_numeric($limitRaw) ? (int) $limitRaw : 0;
        $catIdRaw     = $params['cat_id'] ?? null;
        $catIdParam   = is_numeric($catIdRaw) ? (int) $catIdRaw : 0;
        $orderLimit   = '';
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
        $imageIds                     = [];
        $categories                   = [];
        $userRepresentativeUpdatesFor = [];
        $cats                         = [];
        foreach ($getListRows as $row) {
            $row['url'] = $this->urlService->makeIndexUrl(['category' => $row]);
            $fullnameParam = $params['fullname'] ?? false;
            if ($fullnameParam !== false && $fullnameParam !== '' && $fullnameParam !== 0) {
                $row['name']  = strip_tags($this->htmlService->getCatDisplayNameCache($row['uppercats'], null));
            } else {
                $row['name_raw'] = $row['name'];
                $listRenderEvent = new RenderCategoryName($row['name'], 'ws_categories_getList');
                $this->dispatcher->dispatch($listRenderEvent);
                $row['name'] = strip_tags($listRenderEvent->categoryName);
            }
            $row['comment_raw'] = $row['comment'];
            $catDescEvent       = new RenderCategoryDescription($row['comment'] ?? '', 'ws_categories_getList');
            $this->dispatcher->dispatch($catDescEvent);
            $row['comment'] = $catDescEvent->categoryDescription;
            $imageId        = null;
            if ($row['user_representative_picture_id'] !== null && $row['user_representative_picture_id'] !== 0) {
                $imageId = $row['user_representative_picture_id'];
            } elseif ($row['representative_picture_id'] !== null && $row['representative_picture_id'] !== 0) {
                $imageId = $row['representative_picture_id'];
            } elseif (Config::allowRandomRepresentative()) {
                $imageId = $this->categoryService->getRandomImageInCategory($row);
            } else {
                if ($row['count_categories'] > 0 && $row['count_images'] > 0) {
                    [$permSubSql, $permSubParams, $permSubTypes] = $this->permissionService->getSqlConditionFandF(['visible_categories' => 'id'], "\n  AND");
                    $imageId = $this->categoryRepository->findRandomSubcatRepresentativeForUser(
                        $currentUser->id,
                        $row['uppercats'],
                        $permSubSql,
                        $permSubParams,
                        $permSubTypes,
                    );
                }
            }
            if (isset($imageId)) {
                if (Config::representativeCacheOnSubcats() && $row['user_representative_picture_id'] !== $imageId) {
                    $userRepresentativeUpdatesFor[$row['id']] = $imageId;
                }
                $row['representative_picture_id'] = $imageId;
                $imageIds[]                       = $imageId;
                $categories[]                     = $row;
            }
            unset($imageId);
            if ($row['image_order'] === null || $row['image_order'] === '') {
                $row['image_order'] = $this->orderByService->buildBareOrderByClause(Config::orderBy());
            }
            $cats[] = $row;
        }
        usort($cats, $this->categoryService->globalRankCompare(...));
        $thumbnailSrcOf = [];
        if (count($categories) > 0) {
            $newImageIds   = [];
            $thumbSizeRaw  = $params['thumbnail_size'] ?? null;
            $thumbnailSize = is_string($thumbSizeRaw) ? $thumbSizeRaw : '';
            $userLevel     = is_numeric($user['level'] ?? null) ? (int) $user['level'] : 0;
            foreach ($this->imageRepository->findByIds($imageIds) as $img) {
                $imgIdStr = (string) $img->id->value;
                if ($img->level <= $userLevel) {
                    $thumbnailSrcOf[$imgIdStr] = DerivativeImage::url($thumbnailSize, SrcImage::fromImage($img));
                } else {
                    foreach ($categories as &$category) {
                        if ($img->id->value === $category['representative_picture_id']) {
                            $newImgId = $this->categoryService->getRandomImageInCategory($category);
                            if (isset($newImgId) && !in_array($newImgId, $imageIds)) {
                                $newImageIds[] = $newImgId;
                            }
                            if (Config::representativeCacheOnLevel()) {
                                $userRepresentativeUpdatesFor[(string) $category['id']] = $newImgId;
                            }
                            $category['representative_picture_id'] = $newImgId;
                        }
                    }
                    unset($category);
                }
            }
            if (count($newImageIds) > 0) {
                foreach ($this->imageRepository->findByIds(array_map(intval(...), $newImageIds)) as $img) {
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
                if ($category['id'] === $cat['id'] && $category['representative_picture_id'] !== null) {
                    $cat['tn_url'] = $thumbnailSrcOf[(string) $category['representative_picture_id']] ?? null;
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
}
