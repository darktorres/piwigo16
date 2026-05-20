<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Doctrine\DBAL\ParameterType;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Event\Template\RenderCategoryDescription;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Html\HtmlService;
use Piwigo\Image\OrderByService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.categories.getAdminList` — admin album browser with subcat counts. */
final readonly class GetAdminListHandler implements WsAction
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private EventDispatcherInterface $dispatcher,
        private HtmlService $htmlService,
        private OrderByService $orderByService,
        private UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): array
    {
        $input      = GetAdminListParams::fromArray($params);
        $nbImagesOf = $this->categoryRepository->findNbPhotosPerCategoryKeyedById();
        $where      = ['1=1'];
        $adminCatId = $input->catId;
        if (!$input->recursive) {
            if ($adminCatId > 0) {
                $where[] = '(id_uppercat = ' . $adminCatId . ' OR id=' . $adminCatId . ')';
            } else {
                $where[] = 'id_uppercat IS NULL';
            }
        } elseif ($adminCatId > 0) {
            $where[] = "uppercats REGEXP '(^|,)" . $adminCatId . "(,|$)'";
        }
        $listParams = [];
        $listTypes  = [];
        $tail       = '';
        if ($input->search !== null) {
            $where[]      = 'name LIKE ?';
            $listParams[] = '%' . $input->search . '%';
            $listTypes[]  = ParameterType::STRING;
            $tail         = 'LIMIT ' . Config::linkedAlbumSearchLimit();
        }
        $adminPage  = $this->categoryRepository->findAdminListPage($where, $tail, $listParams, $listTypes);
        $searchRows = $adminPage['rows'];
        $counter    = $adminPage['total'];
        $cats       = [];
        foreach ($searchRows as $row) {
            $row['nb_images']  = $nbImagesOf[$row['id']] ?? 0;
            $catDisplayName    = $this->htmlService->getCatDisplayNameCache($row['uppercats'], $this->urlGenerator->admin() . '&page=album-');
            $row['name_raw']   = $row['name'];
            $adminRenderEvent  = new RenderCategoryName($row['name'], 'ws_categories_getAdminList');
            $this->dispatcher->dispatch($adminRenderEvent);
            $row['name']        = strip_tags($adminRenderEvent->categoryName);
            $row['fullname']    = strip_tags($catDisplayName);
            $row['comment_raw'] = $row['comment'];
            $adminCatDescEvent  = new RenderCategoryDescription($row['comment'] ?? '', 'ws_categories_getAdminList');
            $this->dispatcher->dispatch($adminCatDescEvent);
            $row['comment']     = $adminCatDescEvent->categoryDescription;
            if ($row['image_order'] === null || $row['image_order'] === '') {
                $row['image_order'] = $this->orderByService->buildBareOrderByClause(Config::orderBy());
            }
            if (in_array('full_name_with_admin_links', $input->additionalOutput)) {
                $row['full_name_with_admin_links'] = $catDisplayName;
            }
            $cats[] = $row;
        }
        if (!$input->recursive) {
            $catsIds     = array_column($cats, 'id');
            $nbSubcatsOf = $this->categoryRepository->countSubcatsByParentIdsKeyedByParent($catsIds);
            foreach ($cats as $idx => $cat) {
                $cats[$idx]['nb_categories'] = $nbSubcatsOf[$cat['id']] ?? 0;
            }
        }
        $limitReached = ($counter > Config::linkedAlbumSearchLimit());
        usort($cats, $this->categoryService->globalRankCompare(...));
        return ['categories' => new PwgNamedArray($cats, 'category', ['id', 'nb_images', 'name', 'uppercats', 'global_rank', 'status', 'test']), 'limit' => Config::linkedAlbumSearchLimit(), 'limit_reached' => $limitReached];
    }
}
