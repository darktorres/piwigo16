<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Cache\CategoryTreeCachePool;
use Piwigo\Cache\PermissionsCachePool;
use Piwigo\Category\CategoryListCriteria;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\CategoryTreeCache;
use Piwigo\Category\Event\RenderCategoryDescription;
use Piwigo\Category\Event\RenderCategoryName;
use Piwigo\Category\Projection\RandomImageCategoryQuery;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Permission\ForbiddenCategoriesCache;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/categories/available` -- `pwg.categories.getList`'s real
 * replacement. Public, permission-filtered -- one of 3 forbidden-
 * categories computations applies depending on the caller (an explicit
 * `public=true` browse-as-guest mode, an admin session which sees
 * locked/private-but-not-permitted albums too, or a normal user session),
 * same branching as its WS predecessor. Includes the same multi-tier
 * album-thumbnail resolution (remembered user representative -> the
 * album's own representative -> a random in-category/sub-category photo,
 * with a same-or-lower-privacy-level substitution when the chosen photo
 * outranks the caller) -- this is real, security-relevant behavior
 * (never surface a thumbnail from a photo the caller couldn't otherwise
 * see), not legacy cruft, so it's ported in full rather than simplified.
 *
 * `tree_output` is dropped: `Ws\CategoryTreeBuilder::
 * categoriesFlatlistToTree()` injects `Ws\NamedArray` instances for each
 * node's `sub_categories`, an XML/WS-encoder-pipeline type this JSON
 * surface has no use for. Every row already carries `uppercats` (the
 * full ancestor-id chain), which a client can already use to reconstruct
 * a tree itself -- always returns the flat list.
 */
final readonly class CategoryAvailableListController implements ControllerInterface
{
    public function __construct(
        private CategoryService $categoryService,
        private PermissionService $permissionService,
        private UserService $userService,
        private ImageService $imageService,
        private CategoryRepository $categoryRepository,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
        private CurrentUser $currentUser,
        private AccessControl $accessControl,
        private ImageStdParams $imageStdParams,
        private PermissionsCachePool $permissionsCachePool,
        private CategoryTreeCachePool $categoryTreeCachePool,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        $catId = isset($query['catId']) && is_numeric($query['catId']) ? (int) $query['catId'] : null;
        $recursive = ($query['recursive'] ?? null) === 'true';
        $public = ($query['public'] ?? null) === 'true';
        $fullname = ($query['fullname'] ?? null) === 'true';
        $thumbnailSize = is_string($query['thumbnailSize'] ?? null) ? $query['thumbnailSize'] : ImageStdParams::THUMB;
        $search = isset($query['search']) && is_string($query['search']) && $query['search'] !== '' ? $query['search'] : null;
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : null;

        if (! in_array($thumbnailSize, array_keys($this->imageStdParams->getDefinedTypeMap()), true)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid thumbnailSize.');
        }

        if ($limit !== null && $recursive) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Cannot use both recursive and limit parameters at the same time.');
        }

        $currentUser = $this->currentUser->get();
        $userId = $currentUser->id->value;
        $reprUserId = $userId;

        $rollupByCatId = [];
        $forbiddenCategoryIds = [];
        $publicOnly = false;

        if ($public) {
            $publicOnly = true;
            $reprUserId = $this->currentConfig->guestId;

            $guestUserdata = $this->userService->getUserData(UserId::from($reprUserId));
            $guestForbiddenCategories = $guestUserdata['forbidden_categories'] ?? '0';
            $guestForbiddenCategories = is_string($guestForbiddenCategories) ? $guestForbiddenCategories : '0';
            $forbiddenCategoryIds = self::csvToIntList($guestForbiddenCategories);
            $rollupByCatId = $this->categoryTreeCache()
                ->getForUser($guestUserdata);
        } elseif ($this->accessControl->isAdmin()) {
            $forbiddenCategories = new ForbiddenCategoriesCache($this->permissionService, $this->permissionsCachePool)
                ->getForUser($userId, $currentUser->status->value);
            $forbiddenCategoryIds = self::csvToIntList($forbiddenCategories);

            $adminUserdata = $currentUser->toUserArray();
            $adminUserdata['forbidden_categories'] = $forbiddenCategories;
            $rollupByCatId = $this->categoryService->getComputedCategories($adminUserdata, null)['categories'];
        } else {
            $forbiddenCategoryIds = self::csvToIntList($currentUser->forbiddenCategories);
            $rollupByCatId = $this->categoryTreeCache()
                ->getForUser($currentUser->toUserArray());
        }

        $catIdVo = CategoryId::tryFrom($catId);

        $criteria = new CategoryListCriteria(
            catId: $catIdVo,
            recursive: $recursive,
            forbiddenCategoryIds: $forbiddenCategoryIds,
            publicOnly: $publicOnly,
        );

        $paginatedCats = $this->categoryService->getAvailableList(
            $criteria,
            $search,
            $this->currentConfig->linkedAlbumSearchLimit,
            $limit,
            $catIdVo instanceof CategoryId
        );
        $rows = $paginatedCats->rows;

        $limitInfo = null;
        if ($limit !== null) {
            $resultCount = $paginatedCats->total ?? 0;
            if ($catIdVo instanceof CategoryId) {
                --$resultCount;
            }
            $limitInfo = [
                'limitedTo' => $limit,
                'totalCats' => $resultCount,
                'remainingCats' => $resultCount > $limit ? $resultCount - $limit : 0,
            ];
        }

        $imageIds = [];
        $categoriesWithRepresentative = [];
        $userRepresentativeUpdatesFor = [];

        $cats = [];
        foreach ($rows as $row) {
            $rowCatId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $rollup = $rollupByCatId[$rowCatId] ?? [];
            $row['nb_images'] = $rollup['nb_images'] ?? 0;
            $row['total_nb_images'] = $rollup['count_images'] ?? 0;
            $row['count_images'] = $rollup['count_images'] ?? 0;
            $row['count_categories'] = $rollup['count_categories'] ?? 0;
            $row['nb_categories'] = $rollup['count_categories'] ?? 0;
            $row['date_last'] = $rollup['date_last'] ?? null;
            $row['max_date_last'] = $rollup['max_date_last'] ?? null;

            $row['url'] = $this->urlService->makeIndexUrl([
                'category' => $row,
            ]);
            foreach (['id', 'nb_images', 'total_nb_images', 'nb_categories'] as $key) {
                $row[$key] = is_numeric($row[$key] ?? null) ? (int) ($row[$key] ?? 0) : 0;
            }

            $row['user_representative_picture_id'] = $this->getCachedRepresentative($reprUserId, $row['id']);

            assert(is_string($row['uppercats']));

            if ($fullname) {
                $row['name'] = strip_tags($this->htmlRenderer->getCatDisplayNameCache($row['uppercats'], null));
            } else {
                $row['name_raw'] = $row['name'];
                $nameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName(is_string($row['name']) ? $row['name'] : '', 'api_v1_categories_available'));
                $row['name'] = strip_tags($nameEvent->categoryName);
            }

            $row['comment_raw'] = $row['comment'];
            $descriptionEvent = $this->eventDispatcher->dispatch(new RenderCategoryDescription(is_string($row['comment']) ? $row['comment'] : null, 'api_v1_categories_available'));
            $row['comment'] = $descriptionEvent->categoryDescription ?? '';

            $imageId = null;
            if (is_numeric($row['user_representative_picture_id']) && (int) $row['user_representative_picture_id'] !== 0) {
                $imageId = $row['user_representative_picture_id'];
            } elseif (is_numeric($row['representative_picture_id']) && (int) $row['representative_picture_id'] !== 0) {
                $imageId = $row['representative_picture_id'];
            } elseif ($this->currentConfig->allowRandomRepresentative) {
                $catIdVoForRandom = CategoryId::tryFrom($rowCatId);
                $imageId = $catIdVoForRandom instanceof CategoryId
                    ? $this->categoryService->getRandomImageInCategory(new RandomImageCategoryQuery(
                        id: $catIdVoForRandom,
                        uppercats: $row['uppercats'],
                        countImages: $row['count_images'],
                    ))
                    : null;
            } elseif ($row['count_categories'] > 0 && $row['count_images'] > 0) {
                $imageId = $this->categoryService->getRandomRepresentativeIdAmongSubcategories(
                    $row['uppercats'],
                    $this->permissionService->getPermissionCriteria()
                );
            }

            if ($imageId !== null && is_numeric($imageId)) {
                $imageId = (int) $imageId;

                $cachedRepresentativeId = is_numeric($row['user_representative_picture_id'])
                    ? (int) $row['user_representative_picture_id']
                    : null;

                if ($this->currentConfig->representativeCacheOnSubcats && $cachedRepresentativeId !== $imageId) {
                    $userRepresentativeUpdatesFor[$row['id']] = $imageId;
                }

                $row['representative_picture_id'] = $imageId;
                $imageIds[] = $imageId;
                $categoriesWithRepresentative[] = $row;
            }

            if (! is_string($row['image_order']) || $row['image_order'] === '') {
                $row['image_order'] = $this->currentConfig->orderBy->toStoredBody();
            }

            $cats[] = $row;
        }
        usort($cats, static function (array $a, array $b): int {
            $aRank = $a['global_rank'] ?? null;
            $bRank = $b['global_rank'] ?? null;

            return strnatcasecmp(is_scalar($aRank) ? (string) $aRank : '', is_scalar($bRank) ? (string) $bRank : '');
        });

        if ($categoriesWithRepresentative !== []) {
            $thumbnailSrcOf = [];
            $newImageIds = [];

            foreach ($this->imageService->getPathsAndLevelForIds($imageIds) as $pathRow) {
                if ($pathRow->level <= $currentUser->level) {
                    $thumbnailSrcOf[$pathRow->id] = DerivativeImage::url($thumbnailSize, $pathRow->toArray());

                    continue;
                }

                foreach ($categoriesWithRepresentative as &$category) {
                    if ($pathRow->id !== $category['representative_picture_id']) {
                        continue;
                    }

                    $categoryIdVoForRandom = is_int($category['id']) ? CategoryId::tryFrom($category['id']) : null;
                    $substituteImageId = $categoryIdVoForRandom instanceof CategoryId
                        ? $this->categoryService->getRandomImageInCategory(new RandomImageCategoryQuery(
                            id: $categoryIdVoForRandom,
                            uppercats: $category['uppercats'],
                            countImages: $category['count_images'],
                        ))
                        : null;

                    if ($substituteImageId !== null && ! in_array($substituteImageId, $imageIds, true)) {
                        $newImageIds[] = $substituteImageId;
                    }
                    if ($this->currentConfig->representativeCacheOnLevel && is_int($category['id'])) {
                        $userRepresentativeUpdatesFor[$category['id']] = $substituteImageId;
                    }

                    $category['representative_picture_id'] = $substituteImageId;
                }
                unset($category);
            }

            if ($newImageIds !== []) {
                foreach ($this->imageService->getPathsForFileDeletion($newImageIds) as $pathRow) {
                    $thumbnailSrcOf[$pathRow->id] = DerivativeImage::url($thumbnailSize, $pathRow->toArray());
                }
            }
        }

        if (! $public && $userRepresentativeUpdatesFor !== []) {
            foreach ($userRepresentativeUpdatesFor as $updateCatId => $updateImageId) {
                $this->setCachedRepresentative(
                    $reprUserId,
                    $updateCatId,
                    is_scalar($updateImageId) ? (string) $updateImageId : null
                );
            }
        }

        $result = [];
        foreach ($cats as $cat) {
            $tnUrl = null;
            foreach ($categoriesWithRepresentative as $category) {
                if ($category['id'] === $cat['id'] && isset($category['representative_picture_id'])) {
                    $tnUrl = $thumbnailSrcOf[$category['representative_picture_id']] ?? null;
                }
            }

            $result[] = [
                'id' => $cat['id'],
                'parentId' => $cat['id_uppercat'] ?? null,
                'name' => $cat['name'],
                'nameRaw' => $cat['name_raw'] ?? null,
                'comment' => $cat['comment'],
                'commentRaw' => $cat['comment_raw'],
                'status' => $cat['status'] ?? null,
                'permalink' => $cat['permalink'] ?? null,
                'uppercats' => $cat['uppercats'],
                'globalRank' => $cat['global_rank'] ?? null,
                'url' => $cat['url'],
                'tnUrl' => $tnUrl,
                'nbImages' => $cat['nb_images'],
                'totalNbImages' => $cat['total_nb_images'],
                'nbCategories' => $cat['nb_categories'],
                'dateLast' => $cat['date_last'],
                'maxDateLast' => $cat['max_date_last'],
                'imageOrder' => $cat['image_order'],
            ];
        }

        $body = [
            'categories' => $result,
        ];
        if ($limitInfo !== null) {
            $body['limit'] = $limitInfo;
        }

        return ResponseFactory::json($body);
    }

    private function categoryTreeCache(): CategoryTreeCache
    {
        return new CategoryTreeCache($this->categoryService, $this->categoryRepository, $this->categoryTreeCachePool);
    }

    /**
     * @return list<int>
     */
    private static function csvToIntList(string $csv): array
    {
        $ids = array_map(intval(...), array_filter(explode(',', $csv), is_numeric(...)));

        return $ids === [] ? [0] : array_values($ids);
    }

    private function getCachedRepresentative(int $userId, int $catId): ?string
    {
        $item = $this->categoryTreeCachePool->getItem('repr_' . $userId . '_' . $catId);
        if (! $item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_string($value) ? $value : null;
    }

    private function setCachedRepresentative(int $userId, int $catId, ?string $imageId): void
    {
        $pool = $this->categoryTreeCachePool;
        $item = $pool->getItem('repr_' . $userId . '_' . $catId);
        $item->set($imageId);
        $pool->save($item);
    }
}
