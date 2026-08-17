<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Event\RenderCategoryName;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\PageState;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/categories/actions/move` -- `pwg.categories.move`'s real
 * replacement, admin + CSRF. Bulk by design: moving a set of albums
 * under a new parent is one atomic action, not a
 * per-album resource operation. Set `parentId` to 0 to move to the
 * gallery root. Only virtual albums can be moved.
 */
final readonly class CategoryMoveController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CategoryService $categoryService,
        private ActivityService $activityService,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private HtmlRenderingInterface $htmlRenderer,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $input = CategoryMoveInput::fromArray(JsonBody::decode($request));
        $categoryIds = $input->categoryIds;

        if ($categoryIds === []) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'No category to move.');
        }

        $categoriesInDb = [];
        $updateCatIds = [];

        foreach ($this->categoryService->getMoveDetailsByIds($categoryIds) as $row) {
            $categoriesInDb[$row->id] = $row;
            $updateCatIds = array_merge($updateCatIds, array_slice(explode(',', $row->uppercats), 0, -1));

            if (! in_array($row->dir, [null, '', '0'], true)) {
                $moveNameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName($row->name, 'api_v1_categories_move'));

                return ResponseFactory::problem(
                    'Unprocessable Entity',
                    422,
                    sprintf('Album %s (%u) is not a virtual album, you cannot move it.', strip_tags($moveNameEvent->categoryName), $row->id)
                );
            }
        }

        if (count($categoriesInDb) !== count($categoryIds)) {
            $unknownIds = array_values(array_diff($categoryIds, array_keys($categoriesInDb)));

            return ResponseFactory::problem('Not Found', 404, sprintf('Album %u does not exist.', $unknownIds[0] ?? 0));
        }

        if ($input->parentId !== 0 && $this->categoryService->getSubcatIds([$input->parentId]) === []) {
            return ResponseFactory::problem('Not Found', 404, 'Unknown parent album id.');
        }

        $pageState = $this->pageState;
        $pageState->infos = [];
        $pageState->errors = [];

        $this->categoryService->moveCategories($categoryIds, $this->activityService, $pageState, $this->entityManager, $input->parentId);
        PermissionCacheInvalidator::invalidate();

        if ($pageState->hasErrors()) {
            return ResponseFactory::problem('Unprocessable Entity', 422, implode('; ', $pageState->errors));
        }

        $newArianeString = '';
        foreach ($this->categoryService->getUppercatsColumns($categoryIds) as $uppercats) {
            $newArianeString = $this->htmlRenderer->getCatDisplayNameCache($uppercats, 'admin.php?page=album-');
            $updateCatIds = array_merge($updateCatIds, array_slice(explode(',', $uppercats), 0, -1));
        }

        $nbPhotosIn = $this->categoryService->getPhotoCountsByCategory();

        $updatedCategories = [];
        foreach (array_unique($updateCatIds) as $updateCatId) {
            $nbSubPhotos = 0;
            $subCatsWithoutParent = array_diff($this->categoryService->getSubcatIds([$updateCatId]), [$updateCatId]);

            foreach ($subCatsWithoutParent as $subCatId) {
                $nbSubPhotos += $nbPhotosIn[$subCatId] ?? 0;
            }

            $updatedCategories[] = [
                'categoryId' => $updateCatId,
                'nbSubPhotos' => $nbSubPhotos,
            ];
        }

        return ResponseFactory::json([
            'newArianeString' => $newArianeString,
            'updatedCategories' => $updatedCategories,
        ]);
    }
}
