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
        if (!isset($params['additional_output'])) {
            $params['additional_output'] = '';
        }
        $params['additional_output'] = array_map(trim(...), explode(',', is_string($params['additional_output']) ? $params['additional_output'] : ''));
        $nbImagesOf = $this->categoryRepository->findNbPhotosPerCategoryKeyedById();
        $where      = ['1=1'];
        $adminCatId = is_numeric($params['cat_id']) ? (int) $params['cat_id'] : 0;
        if (!$params['recursive']) {
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
        if (isset($params['search']) && $params['search'] !== '') {
            $where[]      = 'name LIKE ?';
            $listParams[] = '%' . (is_string($params['search']) ? $params['search'] : '') . '%';
            $listTypes[]  = ParameterType::STRING;
            $tail         = 'LIMIT ' . Config::linkedAlbumSearchLimit();
        }
        $adminPage  = $this->categoryRepository->findAdminListPage($where, $tail, $listParams, $listTypes);
        $searchRows = $adminPage['rows'];
        $counter    = $adminPage['total'];
        $cats       = [];
        foreach ($searchRows as $row) {
            $rowIdRaw          = $row['id'] ?? null;
            $id                = is_string($rowIdRaw) ? $rowIdRaw : '';
            $row['nb_images']  = $nbImagesOf[$id] ?? 0;
            $rowUppercatsRaw   = $row['uppercats'] ?? null;
            $catDisplayName    = $this->htmlService->getCatDisplayNameCache(is_string($rowUppercatsRaw) ? $rowUppercatsRaw : '', $this->urlGenerator->admin() . '&page=album-');
            $row['name_raw']   = $row['name'];
            $rawAdminName      = $row['name'] ?? null;
            $adminRenderEvent  = new RenderCategoryName(is_string($rawAdminName) ? $rawAdminName : '', 'ws_categories_getAdminList');
            $this->dispatcher->dispatch($adminRenderEvent);
            $row['name']        = strip_tags($adminRenderEvent->categoryName);
            $row['fullname']    = strip_tags($catDisplayName);
            $row['comment_raw'] = $row['comment'];
            $adminCommentRaw    = $row['comment'] ?? '';
            $adminCatDescEvent  = new RenderCategoryDescription(is_string($adminCommentRaw) ? $adminCommentRaw : '', 'ws_categories_getAdminList');
            $this->dispatcher->dispatch($adminCatDescEvent);
            $row['comment']     = $adminCatDescEvent->categoryDescription;
            if (empty($row['image_order'])) {
                $row['image_order'] = $this->orderByService->buildBareOrderByClause(Config::orderBy());
            }
            if (in_array('full_name_with_admin_links', $params['additional_output'])) {
                $row['full_name_with_admin_links'] = $catDisplayName;
            }
            $cats[] = $row;
        }
        if (!$params['recursive']) {
            $catsIds     = array_column($cats, 'id');
            $catsIdsInt  = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catsIds);
            $nbSubcatsOf = $this->categoryRepository->countSubcatsByParentIdsKeyedByParent($catsIdsInt);
            foreach ($cats as $idx => $cat) {
                $catIdRaw2                   = $cat['id'] ?? null;
                $catIdKey                    = is_string($catIdRaw2) ? $catIdRaw2 : '';
                $cats[$idx]['nb_categories'] = $nbSubcatsOf[$catIdKey] ?? 0;
            }
        }
        $limitReached = ($counter > Config::linkedAlbumSearchLimit());
        usort($cats, $this->categoryService->globalRankCompare(...));
        return ['categories' => new PwgNamedArray($cats, 'category', ['id', 'nb_images', 'name', 'uppercats', 'global_rank', 'status', 'test']), 'limit' => Config::linkedAlbumSearchLimit(), 'limit_reached' => $limitReached];
    }
}
