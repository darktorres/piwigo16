<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\PhotoSortOrder;
use Piwigo\Controller\Api\ImageFilterQueryInput;
use Piwigo\Core\OperationError;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Html\Event\RenderElementDescription;
use Piwigo\Html\Event\RenderElementName;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\CategoryImagesCriteria;
use Piwigo\Image\ImageFilterCriteriaBuilder;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageUrlBuilder;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\EventDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/categories/images` -- `pwg.categories.getImages`'s real
 * replacement. Public, permission-filtered. A collection-level query
 * (matching one or more albums via `catIds`), not a single-album
 * sub-resource.
 */
final readonly class CategoryImagesController implements ControllerInterface
{
    public function __construct(
        private CategoryService $categoryService,
        private PermissionService $permissionService,
        private ImageService $imageService,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private ImageFilterCriteriaBuilder $imageFilterCriteriaBuilder,
        private ImageUrlBuilder $imageUrlBuilder,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        $catIds = array_values(array_unique(self::intList($query['catIds'] ?? null)));
        $recursive = ($query['recursive'] ?? null) === 'true';
        $perPage = isset($query['perPage']) && is_numeric($query['perPage']) ? (int) $query['perPage'] : 100;
        $page = isset($query['page']) && is_numeric($query['page']) ? (int) $query['page'] : 0;
        $order = is_string($query['order'] ?? null) ? $query['order'] : '';

        if ($catIds !== []) {
            $missingCatIds = array_diff($catIds, $this->categoryService->getExistingIds($catIds));
            if ($missingCatIds !== []) {
                return ResponseFactory::problem('Not Found', 404, 'catIds {' . implode(',', $missingCatIds) . '} not found.');
            }
        }

        $images = [];
        $totalImages = 0;

        $catClauses = [];
        $catParams = [];
        foreach ($catIds as $i => $catId) {
            if ($recursive) {
                $catClauses[] = 'REGEXP(c.uppercats, :catUppercatsLike' . $i . ') = true';
                $catParams['catUppercatsLike' . $i] = '(^|,)' . $catId . '(,|$)';
            } else {
                $catClauses[] = 'c.id = :catId' . $i;
                $catParams['catId' . $i] = $catId;
            }
        }
        $catConditions = [];
        if ($catClauses !== []) {
            $catConditions[] = new SqlCondition('(' . implode("\n    OR ", $catClauses) . ')', $catParams);
        }
        $catConditions[] = $this->permissionService->getPermissionCriteria()->forbiddenCategoriesCondition('c.id');

        $cats = [];
        foreach ($this->categoryService->getIdsAndImageOrderWithConditions($catConditions) as $row) {
            $cats[$row->id] = $row;
        }

        if ($cats !== []) {
            $filterCriteria = $this->imageFilterCriteriaBuilder->stdImageSqlFilterCriteria(ImageFilterQueryInput::fromQueryParams($query));
            if ($filterCriteria instanceof OperationError) {
                return ResponseFactory::problem('Unprocessable Entity', 422, $filterCriteria->message());
            }

            $permissionCriteria = $this->permissionService->getPermissionCriteria();
            $imagesCriteria = new CategoryImagesCriteria(
                filterCriteria: $filterCriteria,
                categoryIds: array_keys($cats),
                visibleImagesCondition: SqlCondition::combine(
                    'AND',
                    $permissionCriteria->visibleImagesCondition('i.id'),
                    $permissionCriteria->maxLevelCondition('i.level'),
                ),
            );

            $orderBy = PhotoSortOrder::fromApiOrderParam($order);
            if ($orderBy->isEmpty() && count($catIds) === 1 && ($cats[$catIds[0]]->imageOrder ?? null) !== null) {
                $orderBy = PhotoSortOrder::fromConfigFragment($cats[$catIds[0]]->imageOrder);
            }
            $favoriteIds = $this->urlService->getUserFavorites();

            $paginatedImages = $this->imageService->getWithConditionsPaginated(
                $imagesCriteria,
                $orderBy->isEmpty() ? null : $orderBy,
                $perPage,
                $perPage * $page
            );
            $rows = $paginatedImages->rows;

            $imageIds = [];
            foreach ($rows as $imageRow) {
                assert(is_numeric($imageRow['id']));
                $imageRowId = (int) $imageRow['id'];
                $imageIds[] = $imageRowId;

                $nameEvent = $this->eventDispatcher->dispatch(new RenderElementName(is_string($imageRow['name'] ?? null) ? $imageRow['name'] : '', 'categoryImages'));
                $descriptionEvent = $this->eventDispatcher->dispatch(new RenderElementDescription(is_string($imageRow['comment'] ?? null) ? $imageRow['comment'] : '', 'categoryImages'));

                $images[$imageRowId] = array_merge(
                    [
                        'isFavorite' => isset($favoriteIds[$imageRowId]),
                        'id' => $imageRowId,
                        'width' => $imageRow['width'] ?? null,
                        'height' => $imageRow['height'] ?? null,
                        'hit' => $imageRow['hit'] ?? null,
                        'file' => $imageRow['file'] ?? null,
                        'name' => strip_tags($nameEvent->elementName),
                        'comment' => $descriptionEvent->elementDescription,
                        'dateCreation' => $imageRow['date_creation'] ?? null,
                        'dateAvailable' => $imageRow['date_available'] ?? null,
                    ],
                    $this->imageUrlBuilder->stdGetUrls($imageRow, $this->urlService)
                );
            }

            $totalImages = $paginatedImages->total ?? 0;

            if ($imageIds !== []) {
                $categoryIdsForImages = [];
                $categoriesOfImage = [];
                foreach ($this->imageService->getCategoryLinksForImageIdsWithCondition($imageIds, $this->permissionService->getPermissionCriteria()) as $link) {
                    $categoryIdsForImages[] = $link->categoryId;
                    $categoriesOfImage[$link->imageId][] = $link->categoryId;
                }

                $detailsForCategory = [];
                if ($categoryIdsForImages !== []) {
                    $detailsForCategory = array_column(
                        $this->categoryService->getCategoriesByIds(array_values(array_unique($categoryIdsForImages))),
                        null,
                        'id'
                    );
                }

                foreach ($images as $imageId => $img) {
                    $imageCats = [];
                    foreach ($categoriesOfImage[$imageId] ?? [] as $catId) {
                        $category = $detailsForCategory[$catId] ?? null;
                        if ($category === null) {
                            continue;
                        }

                        $imageCats[] = [
                            'id' => $catId,
                            'url' => $this->urlService->makeIndexUrl([
                                'category' => $category,
                            ]),
                            'pageUrl' => $this->urlService->makePictureUrl([
                                'category' => $category,
                                'image_id' => $imageId,
                                'image_file' => $img['file'],
                            ]),
                        ];
                    }

                    $images[$imageId]['categories'] = $imageCats;
                }
            }
        }

        return ResponseFactory::json([
            'images' => array_values($images),
            'page' => $page,
            'perPage' => $perPage,
            'totalCount' => $totalImages,
        ]);
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $v) {
            if (is_numeric($v)) {
                $ids[] = (int) $v;
            }
        }

        return $ids;
    }
}
