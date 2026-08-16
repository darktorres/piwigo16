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
use Piwigo\Category\CategoryAdminListCriteria;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Event\Template\RenderCategoryDescription;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\WsAction;

/**
 * `pwg.categories.getAdminList` -- admin only. Returns the list of
 * categories as you can see them in administration. Permissions are not
 * taken into account.
 */
final readonly class GetAdminListHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private HtmlRenderingInterface $htmlRenderer,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<mixed> $params
     * @return array<string, mixed>
     */
    #[Override]
    public function __invoke(array $params): array
    {
        $input = GetAdminListParams::fromArray($params);
        $additional_output = array_map(trim(...), explode(',', $input->additionalOutput ?? ''));

        $nb_images_of = $this->categoryService->getPhotoCountsByCategory();

        $criteria = new CategoryAdminListCriteria(
            catId: CategoryId::tryFrom($input->catId),
            recursive: $input->recursive,
        );

        $search_term = ($input->search !== null and $input->search !== '') ? $input->search : null;
        $paginated_admin_cats = $this->categoryService->getAdminListForWs(
            $criteria,
            $search_term,
            $this->currentConfig->linkedAlbumSearchLimit
        );
        $rows = $paginated_admin_cats->rows;
        $counter = $paginated_admin_cats->total ?? 0;

        $cats = [];
        foreach ($rows as $row) {
            // id/uppercats are NOT NULL columns of the categories table --
            // native int under DBAL (vs. guaranteed string under legacy
            // mysqli), so is_int()||is_string() instead of asserting string -- both
            // are valid array-key types (unlike is_numeric(), which also
            // allows float).
            $id = $row['id'];
            assert(is_int($id) || is_string($id));
            $row['nb_images'] = $nb_images_of[$id] ?? 0;

            assert(is_string($row['uppercats']));
            $cat_display_name = $this->htmlRenderer
                ->getCatDisplayNameCache(
                    $row['uppercats'],
                    'admin.php?page=album-'
                );

            $row['name_raw'] = $row['name'];

            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName(is_string($row['name']) ? $row['name'] : '', 'ws_categories_getAdminList'));
            $row['name'] = strip_tags($nameEvent->categoryName);
            $row['fullname'] = strip_tags($cat_display_name);

            $row['comment_raw'] = $row['comment'];
            $adminDescriptionEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryDescription(is_string($row['comment']) ? $row['comment'] : '', 'ws_categories_getAdminList'));
            $row['comment'] = $adminDescriptionEvent->categoryDescription;

            if (! is_string($row['image_order']) || $row['image_order'] === '') {
                $row['image_order'] = $this->currentConfig->orderBy->toSqlBody();
            }

            if (in_array('full_name_with_admin_links', $additional_output, true)) {
                $row['full_name_with_admin_links'] = $cat_display_name;
            }

            $cats[] = $row;
        }

        if (! $input->recursive) {
            $cats_ids = array_column($cats, 'id');
            $nb_subcats_of = [];
            if ($cats_ids !== []) {
                $cats_ids = array_map(intval(...), array_filter($cats_ids, is_numeric(...)));

                $nb_subcats_of = $this->categoryService->getSubcategoryCountsByParent(array_values($cats_ids));
            }

            foreach ($cats as $idx => $cat) {
                $cat_id = $cat['id'];
                $cats[$idx]['nb_categories'] = is_numeric($cat_id) ? ($nb_subcats_of[(string) $cat_id] ?? 0) : 0;
            }
        }

        $limit_reached = false;
        if ($counter > $this->currentConfig->linkedAlbumSearchLimit) {
            $limit_reached = true;
        }

        usort($cats, CategoryService::compareByGlobalRank(...));
        return [
            'categories' => new NamedArray(
                $cats,
                'category',
                ['id', 'nb_images', 'name', 'uppercats', 'global_rank', 'status', 'test']
            ),
            'limit' => $this->currentConfig->linkedAlbumSearchLimit,
            'limit_reached' => $limit_reached,
        ];
    }
}
