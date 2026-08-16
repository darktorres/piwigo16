<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Event\RenderCategoryName;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\PageState;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.categories.move` -- admin only. Move album(s). Set parent as 0 to
 * move to gallery root. Only virtual categories can be moved.
 */
final readonly class MoveHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private ActivityService $activityService,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private HtmlRenderingInterface $htmlRenderer,
        private WsCsrfGuard $wsCsrfGuard,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{new_ariane_string: string, updated_cats: array<int, array{cat_id: string, nb_sub_photos: int}>}
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = MoveParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $category_ids = $input->categoryIds;

        if (count($category_ids) === 0) {
            return new WsErrorResponse(403, 'Invalid category_id input parameter, no category to move');
        }

        // we can't move physical categories
        $categories_in_db = [];
        $update_cat_ids = [];

        foreach ($this->categoryService->getMoveDetailsByIds($category_ids) as $row) {
            $row_id = $row->id;
            $categories_in_db[$row_id] = $row;
            $update_cat_ids = array_merge($update_cat_ids, array_slice(explode(',', $row->uppercats), 0, -1));

            // we break on error at first physical category detected
            if (! in_array($row->dir, [null, '', '0'], true)) {
                $moveNameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName($row->name, 'ws_categories_move'));
                $row_name = strip_tags($moveNameEvent->categoryName);

                return new WsErrorResponse(
                    403,
                    sprintf(
                        'Category %s (%u) is not a virtual category, you cannot move it',
                        $row_name,
                        $row_id
                    )
                );
            }
        }

        if (count($categories_in_db) !== count($category_ids)) {
            $unknown_category_ids = array_diff($category_ids, array_keys($categories_in_db));

            return new WsErrorResponse(
                403,
                sprintf(
                    'Category %u does not exist',
                    $unknown_category_ids[0]
                )
            );
        }

        // does this parent exists? This check should be made in the
        // move_categories function, not here
        // 0 as parent means "move categories at gallery root"
        if ($input->parent !== 0) {
            $subcat_ids = $this->categoryService->getSubcatIds([$input->parent]);
            if (count($subcat_ids) === 0) {
                return new WsErrorResponse(403, 'Unknown parent category id');
            }
        }

        $pageState = $this->pageState;
        $pageState->infos = [];
        $pageState->errors = [];

        $this->categoryService->moveCategories(
            $category_ids,
            $this->activityService,
            $pageState,
            $this->entityManager,
            $input->parent
        );
        PermissionCacheInvalidator::invalidate();

        // moveCategories() writes onto the real, constructor-injected
        // PageState directly -- reading it back through the same
        // $pageState instance reflects the mutation without needing
        // get_defined_vars(). hasErrors() (a real method call, not
        // a bare property re-read) is what stops PHPStan from treating the
        // property as still statically `[]` from the reset a few lines
        // above -- it has no visibility into moveCategories()'s internals.
        if ($pageState->hasErrors()) {
            return new WsErrorResponse(403, implode('; ', $pageState->errors));
        }

        $cat_display_name = '';
        foreach ($this->categoryService->getUppercatsColumns($category_ids) as $uppercats) {
            $cat_display_name = $this->htmlRenderer
                ->getCatDisplayNameCache(
                    $uppercats,
                    'admin.php?page=album-'
                );
            $update_cat_ids = array_merge($update_cat_ids, array_slice(explode(',', $uppercats), 0, -1));
        }

        $nb_photos_in = $this->categoryService->getPhotoCountsByCategory();

        $update_cats = [];
        foreach (array_unique($update_cat_ids) as $update_cat) {
            $nb_sub_photos = 0;
            $sub_cat_without_parent = array_diff($this->categoryService->getSubcatIds([$update_cat]), [$update_cat]);

            foreach ($sub_cat_without_parent as $id_sub_cat) {
                $nb_sub_photos += $nb_photos_in[$id_sub_cat] ?? 0;
            }

            $update_cats[] = [
                'cat_id' => $update_cat,
                'nb_sub_photos' => $nb_sub_photos,
            ];
        }

        return [
            'new_ariane_string' => $cat_display_name,
            'updated_cats' => $update_cats,
        ];
    }
}
