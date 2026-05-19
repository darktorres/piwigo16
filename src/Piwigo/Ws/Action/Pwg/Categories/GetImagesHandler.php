<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\OrderByService;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsHelper;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.categories.getImages` — paginated image list scoped to one or more albums (with permissions). */
final readonly class GetImagesHandler implements WsAction
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private EventDispatcherInterface $dispatcher,
        private ImageRepository $imageRepository,
        private OrderByService $orderByService,
        private PermissionService $permissionService,
        private UrlService $urlService,
        private WsHelper $wsHelper,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $rawCatId = is_array($params['cat_id']) ? $params['cat_id'] : [];
        /** @var int[] $catIds */
        $catIds = array_values(array_unique(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawCatId)));
        if (count($catIds) > 0) {
            $dbCatIds      = $this->categoryRepository->findExistingIdsAmong($catIds);
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
            if (empty($orderBy) && count($catIds) === 1 && isset($cats[$catIds[0]]) && $cats[$catIds[0]]['image_order'] !== null) {
                $orderBy = $cats[$catIds[0]]['image_order'];
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
                $imgIdInt   = $img->id->value;
                $imageIds[] = $imgIdInt;
                $image      = [
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
                $image['name'] = strip_tags($renderEvent->elementName);
                $descEvent     = new RenderElementDescription($img->comment ?? '', __FUNCTION__);
                $this->dispatcher->dispatch($descEvent);
                $image['comment'] = $descEvent->elementDescription;
                $image            = array_merge($image, $this->wsHelper->getUrls($img->toRow()));
                $images[]         = $image;
            }
            $totalImages = $paginated['total'];
            if (count($imageIds) > 0) {
                $categoryIds       = [];
                $categoriesOfImage = [];
                [$permSql3, $permParams3, $permTypes3] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id'], null, true);
                foreach ($this->categoryRepository->findImageCategoryPairsWithPermissions($imageIds, 'AND ' . $permSql3, $permParams3, $permTypes3) as $row) {
                    $categoryIds[]                  = $row['category_id'];
                    $rowImgId                       = (string) $row['image_id'];
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
                        if (!isset($detailsForCategory[$catId])) {
                            continue;
                        }
                        $categoryRow = $detailsForCategory[$catId]->toRow();
                        $url         = $this->urlService->makeIndexUrl(['category' => $categoryRow]);
                        $pageUrl     = $this->urlService->makePictureUrl(['category' => $categoryRow, 'image_id' => $image['id'] ?? null, 'image_file' => $image['file']]);
                        $imageCats[] = ['id' => $catId, 'url' => $url, 'page_url' => $pageUrl];
                    }
                    $images[$idx]['categories'] = new PwgNamedArray($imageCats, 'category', ['id', 'url', 'page_url']);
                }
            }
        }
        return ['paging' => new PwgNamedStruct(['page' => $params['page'], 'per_page' => $params['per_page'], 'count' => count($images), 'total_count' => $totalImages]), 'images' => new PwgNamedArray($images, 'image', $this->wsHelper->getImageXmlAttributes())];
    }
}
