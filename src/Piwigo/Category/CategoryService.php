<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Db\SqlExpr;
use Piwigo\Event\Album\GetCategoriesMenuSqlWhere;
use Piwigo\Event\Album\GetCategoryPreferredImageOrders;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Filter\FilterContextRegistry;
use Piwigo\Filter\FilterService;
use Piwigo\Html\HtmlService;
use Piwigo\Image\OrderByService;
use Piwigo\Lang\Translator;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class CategoryService
{
    public function __construct(
        private CategoryRepository $catRepo,
        private FilterService $filterService,
        private PermissionService $permissionService,
        private EventDispatcherInterface $dispatcher,
        private OrderByService $orderByService,
    ) {
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function globalRankCompare(array $a, array $b): int
    {
        return strnatcasecmp(is_scalar($a['global_rank'] ?? null) ? (string) $a['global_rank'] : '', is_scalar($b['global_rank'] ?? null) ? (string) $b['global_rank'] : '');
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function rankCompare(array $a, array $b): int
    {
        return (int) ((is_numeric($a['rank']) ? (int) $a['rank'] : 0) - (is_numeric($b['rank']) ? (int) $b['rank'] : 0));
    }

    public function checkRestrictions(int $categoryId): void
    {
        $forbidden = CurrentUser::get()->rawAttributes['forbidden_categories'] ?? [];
        if (is_array($forbidden) && in_array($categoryId, $forbidden, true)) {
            Kernel::service(HtmlService::class)->accessDenied();
        }
    }

    /** @return array<mixed> */
    public function getCategoriesMenu(): array
    {
        $filter      = FilterContextRegistry::current();
        $ctx         = SectionContextRegistry::current();
        $currentUser = CurrentUser::get();
        $userExpand  = $currentUser->rawAttributes['expand'] ?? false;

        $permParams = [];
        $permTypes  = [];
        if (($userExpand === false || $userExpand === 0 || $userExpand === '') and !$filter->enabled) {
            $where = '(id_uppercat IS NULL';
            $category = $ctx->category;
            if ($category !== null) {
                $uppercats = is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '';
                $where .= ' OR id_uppercat IN (' . $uppercats . ')';
            }
            $where .= ')';
        } else {
            [$permSql, $permParams, $permTypes] = $this->permissionService->getSqlConditionFandF(['visible_categories' => 'id'], null, true);
            $where = $permSql;
        }

        $whereEvent = new GetCategoriesMenuSqlWhere($where, (bool) $userExpand, $filter->enabled);
        $this->dispatcher->dispatch($whereEvent);
        $where = $whereEvent->where;

        $cats             = [];
        $selectedCategory = $ctx->category;
        foreach ($this->catRepo->findCategoriesMenuRows($currentUser->id, $where, $permParams, $permTypes) as $entity) {
            $row             = $entity->toRow();
            $childDateLast   = (string) $entity->maxDateLast > (string) $entity->dateLast;
            $menuRenderEvent = new RenderCategoryName($entity->name, 'get_categories_menu');
            $this->dispatcher->dispatch($menuRenderEvent);
            $row = array_merge($row, [
                'NAME'        => $menuRenderEvent->categoryName,
                'TITLE'       => $this->getDisplayImagesCount(
                    $entity->nbImages,
                    $entity->countImages,
                    $entity->countCategories,
                    false,
                    ' / ',
                ),
                'URL'         => Kernel::service(UrlService::class)->makeIndexUrl(['category' => $row]),
                'LEVEL'       => substr_count($entity->globalRank ?? '', '.') + 1,
                'SELECTED'    => $selectedCategory !== null && $selectedCategory['id'] == $entity->id->value,
                'IS_UPPERCAT' => $selectedCategory !== null && $selectedCategory['id_uppercat'] == $entity->id->value,
            ]);
            if (Config::indexNewIcon()) {
                $row['icon_ts'] = Kernel::service(HtmlService::class)->getIcon($entity->maxDateLast, $childDateLast);
            }
            $cats[] = $row;
        }
        usort($cats, $this->globalRankCompare(...));

        $this->filterService->updateCategoriesWithFilteredData($cats);

        return $cats;
    }

    /** @return array<mixed>|null */
    public function getCatInfo(int|string $id): ?array
    {
        $entity = $this->catRepo->findCategoryById((int) $id);
        if ($entity === null) {
            return null;
        }
        $cat = $entity->toRow();

        foreach ($cat as $k => $v) {
            if ($cat[$k] == 'true' or $cat[$k] == 'false') {
                $catKv = $cat[$k];
                if (is_string($catKv) || is_int($catKv) || is_float($catKv) || $catKv === null) {
                    $cat[$k] = BoolUtil::fromMixed($catKv);
                }
            }
        }

        $upperIds = explode(',', $entity->uppercats);
        if (count($upperIds) == 1) {
            $cat['upper_names'] = [[
                'id'        => $entity->id->value,
                'name'      => $entity->name,
                'permalink' => $entity->permalink?->value,
            ]];
        } else {
            $upperIdsInt = array_map(static fn (string $v): int => (int) $v, $upperIds);
            $names = $this->catRepo->findNamePermalinkByIdsKeyedById($upperIdsInt);

            $cat['upper_names'] = [];
            foreach ($upperIdsInt as $catIdInt) {
                if (isset($names[$catIdInt])) {
                    $cat['upper_names'][] = $names[$catIdInt]->toRow();
                }
            }
        }
        return $cat;
    }

    /** @return array<mixed> */
    public function getCategoryPreferredImageOrders(): array
    {
        $ordersEvent = new GetCategoryPreferredImageOrders([
            [Lang::t('Default'),                        '',                     true],
            [Lang::t('Photo title, A &rarr; Z'),        'name ASC',             true],
            [Lang::t('Photo title, Z &rarr; A'),        'name DESC',            true],
            [Lang::t('Date created, new &rarr; old'),   'date_creation DESC',   true],
            [Lang::t('Date created, old &rarr; new'),   'date_creation ASC',    true],
            [Lang::t('Date posted, new &rarr; old'),    'date_available DESC',  true],
            [Lang::t('Date posted, old &rarr; new'),    'date_available ASC',   true],
            [Lang::t('Rating score, high &rarr; low'),  'rating_score DESC',    Config::rateEnabled()],
            [Lang::t('Rating score, low &rarr; high'),  'rating_score ASC',     Config::rateEnabled()],
            [Lang::t('Visits, high &rarr; low'),        'hit DESC',             true],
            [Lang::t('Visits, low &rarr; high'),        'hit ASC',              true],
            [Lang::t('Permissions'),                    'level DESC',           $this->permissionService->isAdmin()],
        ]);
        $this->dispatcher->dispatch($ordersEvent);
        return $ordersEvent->value;
    }

    /**
     * @param list<array<string, mixed>> $categories
     * @param int[]|string               $selecteds
     */
    public function displaySelectCategories(array $categories, array|string $selecteds, string $blockname, bool|string $fullname = true): void
    {
        $template = TemplateRegistry::current();
        $tplCats  = [];
        foreach ($categories as $category) {
            if ($fullname !== false && $fullname !== '') {
                $option = strip_tags(Kernel::service(HtmlService::class)->getCatDisplayNameCache(is_string($category['uppercats'] ?? null) ? $category['uppercats'] : '', null));
            } else {
                $option  = str_repeat('&nbsp;', (3 * substr_count(is_string($category['global_rank'] ?? null) ? $category['global_rank'] : '', '.')));
                $option .= '- ';
                $selectRenderEvent = new RenderCategoryName(is_string($category['name'] ?? null) ? $category['name'] : '', 'display_select_categories');
                $this->dispatcher->dispatch($selectRenderEvent);
                $option .= strip_tags($selectRenderEvent->categoryName);
            }
            $tplCats[is_scalar($category['id'] ?? null) ? (string) $category['id'] : ''] = $option;
        }
        $template->assign($blockname, $tplCats);
        $template->assign($blockname . '_selected', $selecteds);
    }

    /** @param int[]|string $selecteds */
    /**
     * @param array<int>|string                       $selecteds
     * @param list<mixed>                             $params
     * @param list<ArrayParameterType|ParameterType>  $types
     */
    public function displaySelectCatWrapper(string $query, array|string $selecteds, string $blockname, bool|string $fullname = true, array $params = [], array $types = []): void
    {
        $categories = $this->catRepo->executeListingQuery($query, $params, $types);
        usort($categories, $this->globalRankCompare(...));
        $this->displaySelectCategories($categories, $selecteds, $blockname, $fullname);
    }

    /**
     * @param array<int|string>|int|string $ids
     * @return array<int>
     */
    public function getSubcatIds(array|int|string $ids): array
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $idsInt = [];
        foreach ($ids as $categoryId) {
            if (!is_numeric($categoryId)) {
                throw new \InvalidArgumentException('get_subcat_ids expecting numeric, not ' . gettype($categoryId));
            }
            $idsInt[] = (int) $categoryId;
        }
        return $this->catRepo->findSubcatIdsByRootIds($idsInt);
    }

    /**
     * @param string[] $permalinks
     */
    public function getCatIdFromPermalinks(array $permalinks, int &$idx): ?int
    {
        $permaHash = $this->catRepo->findCategoryIdsByPermalinksKeyedByPermalink(array_values($permalinks));

        if (empty($permaHash)) {
            return null;
        }
        for ($i = count($permalinks) - 1; $i >= 0; $i--) {
            if (isset($permaHash[$permalinks[$i]])) {
                $idx   = $i;
                $entry = $permaHash[$permalinks[$i]];
                if ($entry['is_old']) {
                    $this->catRepo->updatePermalinkHit($entry['id'], $permalinks[$i]);
                }
                return $entry['id'];
            }
        }
        return null;
    }

    public function getDisplayImagesCount(int $catNbImages, int $catCountImages, int $catCountCategories, bool|string $shortMessage = true, string $separator = '\n'): string
    {
        $displayText = '';

        if ($catCountImages > 0) {
            if ($catNbImages > 0 and $catNbImages < $catCountImages) {
                $displayText .= $this->getDisplayImagesCount($catNbImages, $catNbImages, 0, $shortMessage, $separator) . $separator;
                $catCountImages -= $catNbImages;
                $catNbImages     = 0;
            }

            $displayText .= Translator::get()->plural('%d photo', '%d photos', $catCountImages);

            if ($catCountCategories == 0 or $catNbImages == $catCountImages) {
                if ($shortMessage === false || $shortMessage === '') {
                    $displayText .= ' ' . Lang::t('in this album');
                }
            } else {
                $displayText .= ' ' . Translator::get()->plural('in %d sub-album', 'in %d sub-albums', $catCountCategories);
            }
        }

        return $displayText;
    }

    /** @param array<string, mixed> $category */
    public function getRandomImageInCategory(array $category, bool $recursive = true): ?int
    {
        if (!($category['count_images'] > 0)) {
            return null;
        }
        $catId       = is_numeric($category['id']) ? (int) $category['id'] : 0;
        $uppercats   = is_string($category['uppercats'] ?? null) ? $category['uppercats'] : '';
        [$permSql, $permParams, $permTypes] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'c.id', 'visible_categories' => 'c.id', 'visible_images' => 'image_id'], "\n  AND");
        return $this->catRepo->findRandomImageIdInCategoryWithPermissions(
            $catId,
            $uppercats,
            $recursive,
            $permSql,
            $permParams,
            $permTypes,
        );
    }

    /**
     * @param  array<string, mixed>                  $userdata
     * @return array<int|string, array<string, mixed>>
     */
    public function getComputedCategories(array &$userdata, ?int $filterDays = null): array
    {
        $userLevel = is_numeric($userdata['level']) ? (int) $userdata['level'] : 0;
        $recentSql = $filterDays !== null ? SqlExpr::recentPeriodExpr($filterDays) : null;
        $forbidden = (!empty($userdata['forbidden_categories']) && is_array($userdata['forbidden_categories']))
            ? array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $userdata['forbidden_categories']))
            : [];

        $userdata['last_photo_date'] = null;
        $cats                        = [];
        foreach ($this->catRepo->findComputedCategoryAggregates($userLevel, $recentSql, $forbidden) as $row) {
            $udIdRaw                 = $userdata['id'] ?? null;
            $row['user_id']          = is_scalar($udIdRaw) ? $udIdRaw : 0;
            $row['nb_categories']    = 0;
            $row['count_categories'] = 0;
            $row['count_images']     = $row['nb_images'];
            $row['max_date_last']    = $row['date_last'];
            if ($row['date_last'] !== null && $row['date_last'] > (string) $userdata['last_photo_date']) {
                $userdata['last_photo_date'] = $row['date_last'];
            }
            $cats[(string) $row['cat_id']] = $row;
        }

        uasort($cats, $this->globalRankCompare(...));

        foreach ($cats as $cat) {
            if (!isset($cat['id_uppercat'])) {
                continue;
            }
            $catUppercatKey = (string) $cat['id_uppercat'];
            if (!isset($cats[$catUppercatKey])) {
                continue;
            }

            $parent = &$cats[$catUppercatKey];
            $parent['nb_categories']++;

            do {
                // PHPStan narrows the category-row docblock shape to
                // "nb_images always present"; runtime rows assembled
                // from heterogeneous fetches may lack the column. The
                // defensive ?? null is kept until row shapes converge
                // (likely §1.7 typed-entity-repositories pass).
                /** @phpstan-ignore-next-line nullCoalesce.offset */
                $parent['count_images']     += is_numeric($cat['nb_images'] ?? null) ? (int) ($cat['nb_images'] ?? 0) : 0;
                $parent['count_categories']++;

                if ((empty($parent['max_date_last'])) or ($parent['max_date_last'] < $cat['date_last'])) {
                    $parent['max_date_last'] = $cat['date_last'];
                }

                if (!isset($parent['id_uppercat'])) {
                    break;
                }
                $parentKey = (string) $parent['id_uppercat'];
                $parent    = &$cats[$parentKey];
            } while (true);
            unset($parent);
        }

        if (isset($filterDays)) {
            foreach ($cats as $category) {
                if (empty($category['max_date_last'])) {
                    $this->removeComputedCategory($cats, $category);
                }
            }
        }

        return $cats;
    }

    /**
     * @param array<int|string, array<string, mixed>> $cats
     * @param array<string, mixed>                    $cat
     */
    public function removeComputedCategory(array &$cats, array $cat): void
    {
        $idUppercat    = $cat['id_uppercat'] ?? null;
        $catUppercatKey = is_scalar($idUppercat) ? (string) $idUppercat : '';
        if ($catUppercatKey !== '' && isset($cats[$catUppercatKey])) {
            $parent = &$cats[$catUppercatKey];
            $parent['nb_categories'] = (is_numeric($parent['nb_categories'] ?? null) ? (int) $parent['nb_categories'] : 0) - 1;

            do {
                // Same row-shape disagreement as the parallel block in
                // computeAdditional() above — see comment there.
                /** @phpstan-ignore-next-line nullCoalesce.offset */
                $parent['count_images']     = (is_numeric($parent['count_images'] ?? null) ? (int) ($parent['count_images'] ?? 0) : 0) - (is_numeric($cat['nb_images'] ?? null) ? (int) ($cat['nb_images'] ?? 0) : 0);
                /** @phpstan-ignore-next-line nullCoalesce.offset */
                $parent['count_categories'] = (is_numeric($parent['count_categories'] ?? null) ? (int) ($parent['count_categories'] ?? 0) : 0) - 1 - (is_numeric($cat['count_categories'] ?? null) ? (int) ($cat['count_categories'] ?? 0) : 0);

                if (!isset($parent['id_uppercat'])) {
                    break;
                }
                $parentUppercat     = $parent['id_uppercat'];
                $parentUppercatKey  = is_scalar($parentUppercat) ? (string) $parentUppercat : '';
                if ($parentUppercatKey === '' || !isset($cats[$parentUppercatKey])) {
                    break;
                }
                $parent = &$cats[$parentUppercatKey];
            } while (true);
        }

        $catId = $cat['cat_id'] ?? null;
        unset($cats[is_scalar($catId) ? (string) $catId : '']);
    }

    /**
     * @param int[]|int|string $catIds
     * @return int[]
     */
    public function getImageIdsForCategories(array|int|string $catIds, string $mode = 'AND', ?string $extraImagesWhereSql = '', string $orderBy = '', bool $usePermissions = true): array
    {
        if (empty($catIds)) {
            return [];
        }
        if (!is_array($catIds)) {
            $catIds = [$catIds];
        }
        $catIdsInt = array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catIds));

        $permParams = [];
        $permTypes  = [];
        $permSql    = '';
        if ($usePermissions) {
            [$permSql, $permParams, $permTypes] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'id'], "\n  AND");
        }

        return $this->catRepo->findImageIdsForCategoriesWithPermissions(
            $catIdsInt,
            $mode,
            $extraImagesWhereSql,
            empty($orderBy) ? $this->orderByService->buildOrderByClause(Config::orderBy()) : $orderBy,
            $permSql,
            $permParams,
            $permTypes,
        );
    }

    /**
     * @param  array<mixed>  $items
     * @param  int[]         $excludedCatIds
     * @return array<int|string, array{id: int, uppercats: string, counter: int}>
     */
    public function getCommonCategories(array $items, ?int $max = null, array $excludedCatIds = []): array
    {
        if (empty($items)) {
            return [];
        }

        [$permSql, $permParams, $permTypes] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id'], "\n    AND");
        $imageIds = array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $items));
        $excluded = array_values($excludedCatIds);

        $cats = [];
        foreach ($this->catRepo->findCommonCategoriesWithPermissions($imageIds, $max, $excluded, $permSql, $permParams, $permTypes) as $row) {
            $cats[(string) $row['id']] = $row;
        }

        return $cats;
    }

    /**
     * @param array<mixed>  $items
     * @param int[]         $excludedCatIds
     * @return array<mixed>
     */
    public function getRelatedCategoriesMenu(array $items, array $excludedCatIds = []): array
    {
        $ctx = SectionContextRegistry::current();
        $commonCats = $this->getCommonCategories($items, Config::relatedAlbumsDisplayLimit(), $excludedCatIds);

        if (count($commonCats) == 0) {
            return [];
        }

        $catIds = [];
        foreach ($commonCats as $cat) {
            foreach (explode(',', $cat['uppercats']) as $uppercat) {
                $catIds[$uppercat] = ($catIds[$uppercat] ?? 0) + 1;
            }
        }

        $navIds = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_keys($catIds));
        $cats = $this->catRepo->findRelatedNavRowsByIds($navIds);
        usort($cats, $this->globalRankCompare(...));

        $indexOfCat = [];

        foreach ($cats as $idx => $cat) {
            $catIdKey              = (string) $cat['id'];
            $indexOfCat[$catIdKey] = $idx;
            $cats[$idx]['LEVEL']   = substr_count($cat['global_rank'] ?? '', '.') + 1;
            $catRenderEvent        = new RenderCategoryName($cat['name'], $cat);
            $this->dispatcher->dispatch($catRenderEvent);
            $cats[$idx]['name'] = $catRenderEvent->categoryName;

            if (isset($commonCats[$catIdKey])) {
                $cats[$idx]['count_images'] = $commonCats[$catIdKey]['counter'];

                $urlParams = [];
                if ($ctx->category !== null) {
                    $urlParams['category']            = $ctx->category;
                    $urlParams['combined_categories'] = [$cat];
                    if ($ctx->combinedCategories !== null) {
                        $urlParams['combined_categories'] = array_merge($ctx->combinedCategories, [$cat]);
                    }
                } else {
                    $urlParams['category'] = $cat;
                }

                $cats[$idx]['url'] = Kernel::service(UrlService::class)->makeIndexUrl($urlParams);
            }

            if (!empty($cat['id_uppercat']) and ($cats[$idx]['count_images'] ?? 0) > 0) {
                foreach (array_slice(explode(',', $cat['uppercats']), 0, -1) as $uppercatId) {
                    $upperIdx = $indexOfCat[$uppercatId] ?? null;
                    if ($upperIdx !== null) {
                        $cats[$upperIdx]['count_categories'] = (is_numeric($cats[$upperIdx]['count_categories'] ?? null) ? (int) $cats[$upperIdx]['count_categories'] : 0) + 1;
                    }
                }
            }
        }

        return $cats;
    }
}
