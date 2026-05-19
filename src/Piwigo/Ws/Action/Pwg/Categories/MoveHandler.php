<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.categories.move` — move virtual albums under a new parent (or root). */
final readonly class MoveHandler implements WsAction
{
    public function __construct(
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private CsrfService $csrfService,
        private EventDispatcherInterface $dispatcher,
        private HtmlService $htmlService,
        private UrlGenerator $urlGenerator,
        private UserAdminService $userAdminService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!is_array($params['category_id'])) {
            $splitResult           = preg_split('/[\s,;\|]/', is_string($params['category_id']) ? $params['category_id'] : '', -1, PREG_SPLIT_NO_EMPTY);
            $params['category_id'] = $splitResult !== false ? $splitResult : [];
        }
        $params['category_id'] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['category_id']);
        $categoryIds           = array_filter($params['category_id'], fn (int $v): bool => $v > 0);
        if (count($categoryIds) === 0) {
            return new PwgError(403, 'Invalid category_id input parameter, no category to move');
        }
        $categoriesInDb = [];
        $updateCatIds   = [];
        $parentId       = is_numeric($params['parent']) ? (int) $params['parent'] : 0;
        foreach ($this->categoryRepository->findByIds(array_map(intval(...), $categoryIds)) as $category) {
            $rowId                  = (string) $category->id->value;
            $categoriesInDb[$rowId] = $category;
            $updateCatIds           = array_merge($updateCatIds, array_slice(explode(',', $category->uppercats), 0, -1));
            if ($category->dir !== null && $category->dir !== '') {
                $moveRenderEvent = new RenderCategoryName($category->name, 'ws_categories_move');
                $this->dispatcher->dispatch($moveRenderEvent);
                $renderedName = strip_tags($moveRenderEvent->categoryName);
                return new PwgError(403, sprintf('Category %s (%u) is not a virtual category, you cannot move it', $renderedName, $category->id->value));
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
            $nbSubPhotos         = 0;
            $subCatWithoutParent = array_diff($this->categoryService->getSubcatIds([$updateCat]), [$updateCat]);
            foreach ($subCatWithoutParent as $idSubCat) {
                $nbSubPhotos += $nbPhotosIn[(string) $idSubCat] ?? 0;
            }
            $updateCats[] = ['cat_id' => $updateCat, 'nb_sub_photos' => $nbSubPhotos];
        }
        return ['new_ariane_string' => $catDisplayName, 'updated_cats' => $updateCats];
    }
}
