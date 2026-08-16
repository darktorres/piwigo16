<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Image\CategoryImagesCriteria;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Ws\ImageFilterCriteriaBuilder;
use Piwigo\Ws\ImageSqlOrderBuilder;
use Piwigo\Ws\ImageUrlBuilder;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\XmlAttributeLists;

/**
 * `pwg.categories.getImages` -- returns elements for the corresponding categories.
 */
final readonly class GetImagesHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private PermissionService $permissionService,
        private ImageService $imageService,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
        private ImageFilterCriteriaBuilder $imageFilterCriteriaBuilder,
        private ImageSqlOrderBuilder $imageSqlOrderBuilder,
        private ImageUrlBuilder $imageUrlBuilder,
        private XmlAttributeLists $xmlAttributeLists,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{paging: NamedStruct, images: NamedArray}
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = GetImagesParams::fromArray($params);
        $urlService = $this->urlService;

        $cat_ids = array_values(array_unique($input->catIds));

        if (count($cat_ids) > 0) {
            // do the categories really exist?
            $db_cat_ids = $this->categoryService->getExistingIds($cat_ids);
            $missing_cat_ids = array_diff($cat_ids, $db_cat_ids);

            if (count($missing_cat_ids) > 0) {
                return new WsErrorResponse(404, 'cat_id {' . implode(',', $missing_cat_ids) . '} not found');
            }
        }

        $images = [];
        $image_ids = [];
        $total_images = 0;

        // ------------------------------------------------- get the related categories
        $catClauses = [];
        $catParams = [];
        // CategoryRepository::findIdsAndImageOrderWithConditions() runs
        // these clauses as real DQL, so they're DQL property paths (`c.`-
        // prefixed) rather than raw SQL column names, and the regex match
        // uses the portable REGEXP() DQL function ({@see
        // \Piwigo\Db\DqlFunction\RegexpFunction}) rather than a
        // hand-resolved per-platform operator string.
        foreach ($cat_ids as $i => $cat_id) {
            if ($input->recursive) {
                $catClauses[] = 'REGEXP(c.uppercats, :catUppercatsLike' . $i . ') = true';
                $catParams['catUppercatsLike' . $i] = '(^|,)' . $cat_id . '(,|$)';
            } else {
                $catClauses[] = 'c.id = :catId' . $i;
                $catParams['catId' . $i] = $cat_id;
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

        // -------------------------------------------------------- get the images
        if ($cats !== []) {
            // MethodDefinition's own registration for this method merges
            // SharedImageFilterParams::get() plus 'order' into its
            // param list, so Server::invoke()'s generic
            // validation guarantees this exact shape before __invoke()
            // ever runs -- WsAction::__invoke()'s own $params type can't
            // express that (every handler shares the same loose
            // array<mixed> contract), so it's asserted locally at this
            // one call site instead.
            /** @var array{f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, order: string|null, ...} $filterParams */
            $filterParams = $params;

            $filterCriteria = $this->imageFilterCriteriaBuilder->stdImageSqlFilterCriteria($filterParams);
            if ($filterCriteria instanceof WsErrorResponse) {
                return $filterCriteria;
            }

            $permissionCriteria = $this->permissionService->getPermissionCriteria();
            $imagesCriteria = new CategoryImagesCriteria(
                filterCriteria: $filterCriteria,
                categoryIds: array_keys($cats),
                // visible_images's own old fallthrough into forbidden_images
                // (fieldName 'i.id' -> the images-table's own level check) --
                // see PermissionCriteria's own docblock.
                visibleImagesCondition: SqlCondition::combine(
                    'AND',
                    $permissionCriteria->visibleImagesCondition('i.id'),
                    $permissionCriteria->maxLevelCondition('i.level'),
                ),
            );

            $order_by = $this->imageSqlOrderBuilder->stdImageSqlOrder($filterParams, 'i.');
            if ($order_by === ''
                  and count($cat_ids) === 1
                  and ($cats[$cat_ids[0]]->imageOrder ?? null) !== null
            ) {
                $order_by = $cats[$cat_ids[0]]->imageOrder;
            }
            $order_by = $order_by === '' ? $this->currentConfig->orderBy->toSql() : 'ORDER BY ' . $order_by;
            $favorite_ids = $urlService->getUserFavorites();

            $paginated_images = $this->imageService->getWithConditionsPaginated(
                $imagesCriteria,
                $order_by,
                $input->perPage,
                $input->perPage * $input->page
            );
            $rows = $paginated_images->rows;

            foreach ($rows as $image_row) {
                // id is images.id, a NOT NULL primary key. Native int under DBAL
                // (vs. guaranteed string under legacy mysqli), so cast
                // instead of asserting string.
                assert(is_numeric($image_row['id']));
                $image_row_id = (int) $image_row['id'];
                $image_ids[] = $image_row_id;

                $image = [];
                $image['is_favorite'] = isset($favorite_ids[$image_row_id]);
                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($image_row[$k])) {
                        $image[$k] = is_numeric($image_row[$k]) ? (int) $image_row[$k] : 0;
                    }
                }
                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $image_row[$k] ?? null;
                }

                $nameEvent = $this->eventDispatcher->dispatchChange(new RenderElementName(is_string($image['name']) ? $image['name'] : '', __FUNCTION__));
                $image['name'] = strip_tags($nameEvent->elementName);
                $descriptionEvent = $this->eventDispatcher->dispatchChange(new RenderElementDescription(is_string($image['comment']) ? $image['comment'] : '', __FUNCTION__));
                $image['comment'] = $descriptionEvent->elementDescription;

                $image = array_merge($image, $this->imageUrlBuilder->stdGetUrls($image_row, $urlService));

                $images[] = $image;
            }

            $total_images = $paginated_images->total ?? 0;

            // let's take care of adding the related albums to each photo
            if (count($image_ids) > 0) {
                $category_ids = [];

                // find the complete list (given permissions) of albums linked to photos
                $image_category_rows = $this->imageService->getCategoryLinksForImageIdsWithCondition(
                    $image_ids,
                    $this->permissionService->getPermissionCriteria()
                );
                $categories_of_image = [];
                foreach ($image_category_rows as $image_category_row) {
                    $category_ids[] = $image_category_row->categoryId;
                    $categories_of_image[$image_category_row->imageId][] = $image_category_row->categoryId;
                }

                $details_for_category = [];
                if (count($category_ids) > 0) {
                    // find details (for URL generation) about each album
                    $details_for_category = array_column(
                        $this->categoryService->getCategoriesByIds(array_values(array_unique($category_ids))),
                        null,
                        'id'
                    );
                }

                foreach ($images as $idx => $img) {
                    $image_cats = [];

                    $image_id = $img['id'] ?? null;
                    if (! is_int($image_id)) {
                        continue;
                    }

                    // it should not be possible at this point, but let's consider a photo can be in no album
                    if (! isset($categories_of_image[$image_id])) {
                        continue;
                    }

                    foreach ($categories_of_image[$image_id] as $cat_id) {
                        $url = $urlService->makeIndexUrl([
                            'category' => $details_for_category[$cat_id],
                        ]);

                        $page_url = $urlService->makePictureUrl(
                            [
                                'category' => $details_for_category[$cat_id],
                                'image_id' => $image_id,
                                'image_file' => $img['file'] ?? null,
                            ]
                        );

                        $image_cats[] = [
                            'id' => $cat_id,
                            'url' => $url,
                            'page_url' => $page_url,
                        ];
                    }

                    $images[$idx]['categories'] = new NamedArray(
                        $image_cats,
                        'category',
                        ['id', 'url', 'page_url']
                    );
                }
            }
        }

        return [
            'paging' => new NamedStruct(
                [
                    'page' => $input->page,
                    'per_page' => $input->perPage,
                    'count' => count($images),
                    'total_count' => $total_images,
                ]
            ),
            'images' => new NamedArray(
                $images,
                'image',
                $this->xmlAttributeLists->stdGetImageXmlAttributes()
            ),
        ];
    }
}
