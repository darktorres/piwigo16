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
        $input       = GetListParams::fromArray($params);
        if (!in_array($input->thumbnailSize, array_keys(ImageStdParams::getDefinedTypeMap()))) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid thumbnail_size');
        }
        if ($input->limit !== null && $input->limit !== 0 && $input->recursive) {
            return new PwgError(WsError::InvalidParam->value, 'Cannot use both recursive and limit parameters at the same time');
        }
        $output       = [];
        $where        = ['1=1'];
        $joinType     = 'INNER';
        $joinUser     = $currentUser->id;
        $getlistCatId = $input->catId;
        if (!$input->recursive) {
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
        if ($input->public) {
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
        if ($input->search !== null) {
            $where[]          = 'name LIKE ?';
            $listPermParams[] = '%' . $input->search . '%';
            $listPermTypes[]  = ParameterType::STRING;
        }
        $limitParam   = $input->limit ?? 0;
        $catIdParam   = $input->catId;
        $orderLimit   = '';
        $useFoundRows = false;
        if ($input->limit !== null) {
            $orderLimit   = 'ORDER BY `rank` ASC LIMIT ' . ($limitParam + ($catIdParam > 0 ? 1 : 0));
            $useFoundRows = true;
        } elseif ($input->search !== null) {
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
            if ($input->fullname) {
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
                $imageId = $this->categoryService->getRandomImageInCategory($row['id'], $row['uppercats'], $row['count_images'], true);
            } else {
                if ($row['count_categories'] > 0 && $row['count_images'] > 0) {
                    $permSub = $this->permissionService->getSqlConditionFandF(['visible_categories' => 'id'], "\n  AND");
                    $imageId = $this->categoryRepository->findRandomSubcatRepresentativeForUser(
                        $currentUser->id,
                        $row['uppercats'],
                        $permSub->where,
                        $permSub->params,
                        $permSub->types,
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
            $thumbnailSize = $input->thumbnailSize;
            $userLevel     = is_numeric($user['level'] ?? null) ? (int) $user['level'] : 0;
            foreach ($this->imageRepository->findByIds($imageIds) as $img) {
                $imgIdStr = (string) $img->id->value;
                if ($img->level <= $userLevel) {
                    $thumbnailSrcOf[$imgIdStr] = DerivativeImage::url($thumbnailSize, SrcImage::fromImage($img));
                } else {
                    foreach ($categories as $catIdx => $category) {
                        if ($img->id->value === $category['representative_picture_id']) {
                            $newImgId = $this->categoryService->getRandomImageInCategory($category['id'], $category['uppercats'], $category['count_images'], true);
                            if ($newImgId !== null && !in_array($newImgId, $imageIds)) {
                                $newImageIds[] = $newImgId;
                            }
                            if (Config::representativeCacheOnLevel()) {
                                $userRepresentativeUpdatesFor[(string) $category['id']] = $newImgId;
                            }
                            $categories[$catIdx]['representative_picture_id'] = $newImgId;
                        }
                    }
                }
            }
            if (count($newImageIds) > 0) {
                foreach ($this->imageRepository->findByIds(array_map(intval(...), $newImageIds)) as $img) {
                    $thumbnailSrcOf[(string) $img->id->value] = DerivativeImage::url($thumbnailSize, SrcImage::fromImage($img));
                }
            }
        }
        if (!$input->public && count($userRepresentativeUpdatesFor)) {
            $updates = [];
            foreach ($userRepresentativeUpdatesFor as $catId => $imageId) {
                $updates[] = ['cat_id' => $catId, 'image_id' => $imageId];
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
        if ($input->treeOutput) {
            return $this->wsHelper->categoriesFlatlistToTree($cats);
        }
        $output['categories'] = new PwgNamedArray($cats, 'category', $this->wsHelper->getCategoryXmlAttributes());
        return $output;
    }
}
