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
use Piwigo\Ws\WsParamException;
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

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): MoveResult|PwgError
    {
        try {
            $input = MoveParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $categoriesInDb = [];
        $updateCatIds   = [];
        foreach ($this->categoryRepository->findByIds($input->categoryIds) as $category) {
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
        if (count($categoriesInDb) !== count($input->categoryIds)) {
            $unknownCategoryIds = array_values(array_diff($input->categoryIds, array_map(intval(...), array_keys($categoriesInDb))));
            return new PwgError(403, sprintf('Category %u does not exist', $unknownCategoryIds[0]));
        }
        if ($input->parentId !== 0) {
            $subcatIds = $this->categoryService->getSubcatIds([$input->parentId]);
            if (count($subcatIds) === 0) {
                return new PwgError(403, 'Unknown parent category id');
            }
        }
        $this->categoryAdminService->moveCategories($input->categoryIds, $input->parentId);
        $this->userAdminService->invalidateUserCache();
        $catDisplayName = '';
        foreach ($this->categoryRepository->findUppercatsByIds($input->categoryIds) as $uppercatsStr) {
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
            $updateCats[] = ['cat_id' => (int) $updateCat, 'nb_sub_photos' => $nbSubPhotos];
        }
        return new MoveResult(
            newArianeString: $catDisplayName,
            updatedCats:     $updateCats,
        );
    }
}
