<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Override;
use Piwigo\Category\CategoryAdminListCriteria;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Event\RenderCategoryDescription;
use Piwigo\Category\Event\RenderCategoryName;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/categories` -- `pwg.categories.getAdminList`'s real
 * replacement, admin only (permissions are not taken into account).
 * Query params: `parentId`, `search`,
 * `recursive` (default true). `additional_output=full_name_with_admin_links`
 * is dropped -- an XML-era opt-in for embedding admin-link HTML in every
 * row. `breadcrumb` replaces it with the same information as data: one
 * `{id, name}` per path segment, which is what a client actually needs to
 * rebuild the linked breadcrumb the admin templates render. `id` alone is
 * not enough -- the intermediate segments' names appear nowhere else in the
 * payload.
 */
final readonly class CategoryListController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CategoryService $categoryService,
        private HtmlRenderingInterface $htmlRenderer,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $query = $request->getQueryParams();
        $parentId = isset($query['parentId']) && is_numeric($query['parentId']) ? (int) $query['parentId'] : null;
        $search = isset($query['search']) && is_string($query['search']) && $query['search'] !== '' ? $query['search'] : null;
        $recursive = ! isset($query['recursive']) || $query['recursive'] !== 'false';

        $nbImagesOf = $this->categoryService->getPhotoCountsByCategory();

        $criteria = new CategoryAdminListCriteria(
            catId: $parentId !== null ? CategoryId::tryFrom($parentId) : null,
            recursive: $recursive,
        );

        $paginated = $this->categoryService->getAdminList($criteria, $search, $this->currentConfig->linkedAlbumSearchLimit);
        $rows = $paginated->rows;
        $counter = $paginated->total ?? 0;

        $categories = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            assert(is_int($id) || is_string($id));
            $nbImages = $nbImagesOf[$id] ?? 0;

            assert(is_string($row['uppercats']));

            $nameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName(is_string($row['name']) ? $row['name'] : '', 'api_v1_categories_list'));
            $commentEvent = $this->eventDispatcher->dispatch(new RenderCategoryDescription(is_string($row['comment']) ? $row['comment'] : '', 'api_v1_categories_list'));

            $imageOrder = is_string($row['image_order']) && $row['image_order'] !== ''
                ? $row['image_order']
                : $this->currentConfig->orderBy->toStoredBody();

            $row_global_rank = $row['global_rank'] ?? null;
            $categories[] = [
                'id' => is_string($id) ? (int) $id : $id,
                'name' => strip_tags($nameEvent->categoryName),
                'nameRaw' => is_string($row['name']) ? $row['name'] : '',
                'comment' => $commentEvent->categoryDescription,
                'commentRaw' => is_string($row['comment']) ? $row['comment'] : null,
                'fullname' => strip_tags($this->htmlRenderer->getCatDisplayNameCache($row['uppercats'], 'admin.php?page=album-')),
                // The same breadcrumb as `fullname`, per segment, so a
                // client can rebuild the linked form the admin pages render
                // server-side. `fullname` alone cannot: it is the joined
                // text, and `uppercats` carries ids with no names.
                'breadcrumb' => $this->htmlRenderer->getCatBreadcrumb($row['uppercats']),
                'uppercats' => $row['uppercats'],
                'globalRank' => is_scalar($row_global_rank) ? (string) $row_global_rank : null,
                'status' => $row['status'],
                'imageOrder' => $imageOrder,
                'nbImages' => $nbImages,
            ];
        }

        if (! $recursive) {
            $catIds = array_map(static fn (array $c): int => $c['id'], $categories);
            $nbSubcatsOf = $catIds !== [] ? $this->categoryService->getSubcategoryCountsByParent($catIds) : [];

            foreach ($categories as $idx => $category) {
                $categories[$idx]['nbCategories'] = $nbSubcatsOf[$category['id']] ?? 0;
            }
        }

        usort($categories, static fn (array $a, array $b): int => strnatcasecmp((string) $a['globalRank'], (string) $b['globalRank']));

        $limitedTo = $this->currentConfig->linkedAlbumSearchLimit;

        return ResponseFactory::json([
            'categories' => $categories,
            // Same rich shape as CategoryAvailableListController's own
            // conditional $limitInfo (unconditional here -- this endpoint
            // always applies linkedAlbumSearchLimit, there's no
            // client-supplied opt-in) -- album_selector.js's tree-prefill
            // path reads limitedTo/totalCats/remainingCats directly, its
            // free-text-search path derives a reached/not-reached flag as
            // remainingCats > 0.
            // Presentation config the client needs to join `breadcrumb`
            // exactly as the server does (`<span>{separator}</span>` between
            // segments, HtmlService::getCatDisplayNameCache()).
            'levelSeparator' => $this->currentConfig->levelSeparator,
            'limit' => [
                'limitedTo' => $limitedTo,
                'totalCats' => $counter,
                'remainingCats' => $counter > $limitedTo ? $counter - $limitedTo : 0,
            ],
        ]);
    }
}
