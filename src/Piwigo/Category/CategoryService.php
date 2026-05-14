<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Util;
use Piwigo\Db\Dml;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
use Piwigo\Filter\FilterService;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\Translator;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;

final readonly class CategoryService
{
    public function __construct(
        private CategoryRepository $catRepo,
        private Connection $conn,
        private FilterService $filterService,
        private PermissionService $permissionService,
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
        $forbidden = CurrentUser::get()->rawAttributes['forbidden_categories'] ?? '';
        if (in_array($categoryId, explode(',', is_scalar($forbidden) ? (string) $forbidden : ''))) {
            Kernel::service(HtmlService::class)->accessDenied();
        }
    }

    /** @return array<mixed> */
    public function getCategoriesMenu(): array
    {
        $filter      = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];
        $ctx         = SectionContextRegistry::current();
        $currentUser = CurrentUser::get();
        $userExpand  = $currentUser->rawAttributes['expand'] ?? false;

        $query = '
SELECT
  id, name, permalink, nb_images, global_rank,
  date_last, max_date_last, count_images, count_categories
FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::userCacheCategories() . '
  ON id = cat_id and user_id = ' . $currentUser->id;

        if (($userExpand === false || $userExpand === 0 || $userExpand === '') and !$filter['enabled']) {
            $where = '
(id_uppercat is NULL';
            $category = $ctx->category;
            if ($category !== null) {
                $uppercats = is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '';
                $where .= ' OR id_uppercat IN (' . $uppercats . ')';
            }
            $where .= ')';
        } else {
            $where = '
  ' . $this->permissionService->getSqlConditionFandF(['visible_categories' => 'id'], null, true);
        }

        $where = EventDispatcher::dispatch('get_categories_menu_sql_where', $where, $userExpand, $filter['enabled']);

        $query .= '
WHERE ' . $where . '
;';

        $cats             = [];
        $selectedCategory = $ctx->category;
        foreach ($this->conn->executeQuery($query)->fetchAllAssociative() as $row) {
            $childDateLast = ($row['max_date_last'] ?? null) > ($row['date_last'] ?? null);
            $row           = array_merge($row, [
                'NAME'        => EventDispatcher::dispatch('render_category_name', $row['name'], 'get_categories_menu'),
                'TITLE'       => $this->getDisplayImagesCount(
                    is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0,
                    is_numeric($row['count_images']) ? (int) $row['count_images'] : 0,
                    is_numeric($row['count_categories']) ? (int) $row['count_categories'] : 0,
                    false,
                    ' / '
                ),
                'URL'         => Kernel::service(UrlService::class)->makeIndexUrl(['category' => $row]),
                'LEVEL'       => substr_count(is_string($row['global_rank'] ?? null) ? $row['global_rank'] : '', '.') + 1,
                'SELECTED'    => ($selectedCategory !== null && $selectedCategory['id'] == $row['id']) ? true : false,
                'IS_UPPERCAT' => ($selectedCategory !== null && $selectedCategory['id_uppercat'] == $row['id']) ? true : false,
            ]);
            if (Config::indexNewIcon()) {
                $row['icon_ts'] = Kernel::service(Util::class)->getIcon(is_string($row['max_date_last']) || is_null($row['max_date_last']) ? $row['max_date_last'] : (is_scalar($row['max_date_last']) ? (string) $row['max_date_last'] : null), $childDateLast);
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
        $cat = $this->catRepo->findCategoryById((int) $id);
        if ($cat === null || count($cat) === 0) {
            return null;
        }

        foreach ($cat as $k => $v) {
            if ($cat[$k] == 'true' or $cat[$k] == 'false') {
                $catKv = $cat[$k];
                if (is_string($catKv) || is_int($catKv) || is_float($catKv) || $catKv === null) {
                    $cat[$k] = BoolUtil::fromMixed($catKv);
                }
            }
        }

        $upperIds = explode(',', is_string($cat['uppercats'] ?? null) ? $cat['uppercats'] : '');
        if (count($upperIds) == 1) {
            $cat['upper_names'] = [[
                'id'        => $cat['id'],
                'name'      => $cat['name'],
                'permalink' => $cat['permalink'],
            ]];
        } else {
            $query = '
  SELECT id, name, permalink
    FROM ' . Tables::categories() . '
    WHERE id IN (' . (is_string($cat['uppercats'] ?? null) ? $cat['uppercats'] : '') . ')
  ;';
            $names = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), null, 'id');

            $cat['upper_names'] = [];
            foreach ($upperIds as $catId) {
                $cat['upper_names'][] = $names[$catId];
            }
        }
        return $cat;
    }

    /** @return array<mixed> */
    public function getCategoryPreferredImageOrders(): array
    {
        $result = EventDispatcher::dispatch('get_category_preferred_image_orders', [
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
        return $result;
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
                $option .= strip_tags((string) EventDispatcher::dispatch('render_category_name', is_string($category['name'] ?? null) ? $category['name'] : '', 'display_select_categories'));
            }
            $tplCats[is_scalar($category['id'] ?? null) ? (string) $category['id'] : ''] = $option;
        }
        $template->assign($blockname, $tplCats);
        $template->assign($blockname . '_selected', $selecteds);
    }

    /** @param int[]|string $selecteds */
    public function displaySelectCatWrapper(string $query, array|string $selecteds, string $blockname, bool|string $fullname = true): void
    {
        $categories = $this->conn->executeQuery($query)->fetchAllAssociative();
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
        $query = '
SELECT DISTINCT(id)
  FROM ' . Tables::categories() . '
  WHERE ';
        foreach ($ids as $num => $categoryId) {
            is_numeric($categoryId) or trigger_error('get_subcat_ids expecting numeric, not ' . gettype($categoryId), E_USER_WARNING);
            if ($num > 0) {
                $query .= '
    OR ';
            }
            $query .= 'uppercats ' . Dml::REGEX_OPERATOR . ' \'(^|,)' . $categoryId . '(,|$)\'';
        }
        $query .= '
;';
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id'));
    }

    /**
     * @param string[] $permalinks
     */
    public function getCatIdFromPermalinks(array $permalinks, int &$idx): ?int
    {
        $in = '';
        foreach ($permalinks as $permalink) {
            if (!empty($in)) {
                $in .= ', ';
            }
            $in .= '\'' . $permalink . '\'';
        }
        $query = '
SELECT cat_id AS id, permalink, 1 AS is_old
  FROM ' . Tables::oldPermalinks() . '
  WHERE permalink IN (' . $in . ')
UNION
SELECT id, permalink, 0 AS is_old
  FROM ' . Tables::categories() . '
  WHERE permalink IN (' . $in . ')
;';
        $permaHash = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), null, 'permalink');

        if (empty($permaHash)) {
            return null;
        }
        for ($i = count($permalinks) - 1; $i >= 0; $i--) {
            if (isset($permaHash[$permalinks[$i]])) {
                $idx    = $i;
                $catId  = is_numeric($permaHash[$permalinks[$i]]['id']) ? (int) $permaHash[$permalinks[$i]]['id'] : 0;
                if ($permaHash[$permalinks[$i]]['is_old']) {
                    $this->catRepo->updatePermalinkHit($catId, $permalinks[$i]);
                }
                return $catId;
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
        $imageId = null;
        if ($category['count_images'] > 0) {
            $query = '
SELECT image_id
  FROM ' . Tables::categories() . ' AS c
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON ic.category_id = c.id
  WHERE ';
            if ($recursive) {
                $query .= '
    (c.id=' . (is_numeric($category['id']) ? (int) $category['id'] : 0) . ' OR uppercats LIKE \'' . addslashes(is_string($category['uppercats'] ?? null) ? $category['uppercats'] : '') . ',%\')';
            } else {
                $query .= '
    c.id=' . (is_numeric($category['id']) ? (int) $category['id'] : 0);
            }
            $query .= '
    ' . $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'c.id', 'visible_categories' => 'c.id', 'visible_images' => 'image_id'], "\n  AND") . '
  ORDER BY ' . Dml::RANDOM_FUNCTION . '()
  LIMIT 1
;';
            $val = $this->conn->executeQuery($query)->fetchOne();
            if ($val !== false) {
                $imageId = is_numeric($val) ? (int) $val : null;
            }
        }

        return $imageId;
    }

    /**
     * @param array<string, mixed>               $userdata
     * @return array<string, array<string, mixed>>
     */
    public function getComputedCategories(array &$userdata, ?int $filterDays = null): array
    {
        $query  = 'SELECT c.id AS cat_id, id_uppercat';
        $query .= ', global_rank';
        $query .= ',
  MAX(date_available) AS date_last, COUNT(date_available) AS nb_images
FROM ' . Tables::categories() . ' as c
  LEFT JOIN ' . Tables::imageCategory() . ' AS ic ON ic.category_id = c.id
  LEFT JOIN ' . Tables::images() . ' AS i
    ON ic.image_id = i.id
      AND i.level<=' . (is_numeric($userdata['level']) ? (int) $userdata['level'] : 0);

        if (isset($filterDays)) {
            $query .= ' AND i.date_available > ' . SqlExpr::recentPeriodExpr($filterDays);
        }

        if (!empty($userdata['forbidden_categories'])) {
            $query .= '
  WHERE c.id NOT IN (' . (is_string($userdata['forbidden_categories']) ? $userdata['forbidden_categories'] : '') . ')';
        }

        $query .= '
  GROUP BY c.id';

        $userdata['last_photo_date'] = null;
        $cats                        = [];
        foreach ($this->conn->executeQuery($query)->fetchAllAssociative() as $row) {
            $udIdRaw = $userdata['id'] ?? null;
            $row['user_id']          = is_scalar($udIdRaw) ? $udIdRaw : 0;
            $row['nb_categories']    = 0;
            $row['count_categories'] = 0;
            $row['count_images']     = is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0;
            $row['max_date_last']    = $row['date_last'];
            if ($row['date_last'] > $userdata['last_photo_date']) {
                $userdata['last_photo_date'] = $row['date_last'];
            }
            $rowCatIdRaw = $row['cat_id'] ?? null;
            $cats[is_scalar($rowCatIdRaw) ? (string) $rowCatIdRaw : ''] = $row;
        }

        uasort($cats, $this->globalRankCompare(...));

        foreach ($cats as $cat) {
            if (!isset($cat['id_uppercat'])) {
                continue;
            }
            $catIdUppercatRaw = $cat['id_uppercat'];
            $catUppercatKey = is_scalar($catIdUppercatRaw) ? (string) $catIdUppercatRaw : '';
            if (!isset($cats[$catUppercatKey])) {
                continue;
            }

            $parent = &$cats[$catUppercatKey];
            $parent['nb_categories']++;

            do {
                /** @phpstan-ignore-next-line nullCoalesce.offset */
                $parent['count_images']     += is_numeric($cat['nb_images'] ?? null) ? (int) ($cat['nb_images'] ?? 0) : 0;
                $parent['count_categories']++;

                if ((empty($parent['max_date_last'])) or ($parent['max_date_last'] < $cat['date_last'])) {
                    $parent['max_date_last'] = $cat['date_last'];
                }

                if (!isset($parent['id_uppercat'])) {
                    break;
                }
                $parentUppercatRaw = $parent['id_uppercat'];
                $parentKey = is_scalar($parentUppercatRaw) ? (string) $parentUppercatRaw : '';
                $parent = &$cats[$parentKey];
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
     * @param array<string, array<string, mixed>> $cats
     * @param array<string, mixed>                $cat
     */
    public function removeComputedCategory(array &$cats, array $cat): void
    {
        $idUppercat    = $cat['id_uppercat'] ?? null;
        $catUppercatKey = is_scalar($idUppercat) ? (string) $idUppercat : '';
        if ($catUppercatKey !== '' && isset($cats[$catUppercatKey])) {
            $parent = &$cats[$catUppercatKey];
            $parent['nb_categories'] = (is_numeric($parent['nb_categories'] ?? null) ? (int) $parent['nb_categories'] : 0) - 1;

            do {
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

        $query = '
SELECT id
  FROM ' . Tables::images() . ' i
    INNER JOIN ' . Tables::imageCategory() . ' ic ON id=ic.image_id
  WHERE category_id IN (' . implode(',', $catIds) . ')';

        if ($usePermissions) {
            $query .= $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'id'], "\n  AND");
        }

        $query .= (($extraImagesWhereSql === null || $extraImagesWhereSql === '') ? '' : " \nAND (" . $extraImagesWhereSql . ')') . '
  GROUP BY id';

        if ($mode == 'AND' and count($catIds) > 1) {
            $query .= '
  HAVING COUNT(DISTINCT category_id)=' . count($catIds);
        }
        $query .= "\n" . (empty($orderBy) ? Config::orderBy() : $orderBy);

        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id'));
    }

    /**
     * @param array<mixed>  $items
     * @param int[]         $excludedCatIds
     * @return array<string, array<string, mixed>>
     */
    public function getCommonCategories(array $items, ?int $max = null, array $excludedCatIds = []): array
    {
        if (empty($items)) {
            return [];
        }

        $query = '
SELECT
    c.id,
    c.uppercats,
    count(*) AS counter
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::categories() . ' c ON category_id = id
  WHERE image_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $items)) . ')';

        $query .= $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id'], "\n    AND");

        if (!empty($excludedCatIds)) {
            $query .= '
    AND category_id NOT IN (' . implode(',', $excludedCatIds) . ')';
        }

        $query .= '
  GROUP BY c.id
  ORDER BY ';
        if (isset($max)) {
            $query .= 'counter DESC
  LIMIT ' . $max;
        } else {
            $query .= 'NULL';
        }

        $cats = [];
        foreach ($this->conn->executeQuery($query)->fetchAllAssociative() as $row) {
            $cats[is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''] = $row;
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
            foreach (explode(',', is_string($cat['uppercats'] ?? null) ? $cat['uppercats'] : '') as $uppercat) {
                $catIds[$uppercat] = ($catIds[$uppercat] ?? 0) + 1;
            }
        }

        $query = '
SELECT
    id,
    name,
    permalink,
    id_uppercat,
    uppercats,
    global_rank
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', array_keys($catIds)) . ')
;';
        $cats = $this->conn->executeQuery($query)->fetchAllAssociative();
        usort($cats, $this->globalRankCompare(...));

        $indexOfCat = [];

        foreach ($cats as $idx => $cat) {
            $catIdKey             = is_scalar($cat['id'] ?? null) ? (string) $cat['id'] : '';
            $indexOfCat[$catIdKey] = $idx;
            $cats[$idx]['LEVEL']  = substr_count(is_string($cat['global_rank'] ?? null) ? $cat['global_rank'] : '', '.') + 1;
            $cats[$idx]['name']   = EventDispatcher::dispatch('render_category_name', is_string($cat['name'] ?? null) ? $cat['name'] : '', $cat);

            if (isset($commonCats[$catIdKey])) {
                $cats[$idx]['count_images'] = $commonCats[$catIdKey]['counter'];

                $urlParams = [];
                if ($ctx->category !== null) {
                    $urlParams['category'] = $ctx->category;
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
                $catUppercatsRaw = $cat['uppercats'] ?? null;
                foreach (array_slice(explode(',', is_string($catUppercatsRaw) ? $catUppercatsRaw : ''), 0, -1) as $uppercatId) {
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
