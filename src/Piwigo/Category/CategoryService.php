<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\SqlExpr;
use Piwigo\Filter\FilterService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;

final readonly class CategoryService
{
    public function __construct(
        private CategoryRepository $catRepo,
        private Connection $conn,
    ) {
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function globalRankCompare(array $a, array $b): int
    {
        return strnatcasecmp(is_scalar($a['global_rank']) ? (string) $a['global_rank'] : '', is_scalar($b['global_rank']) ? (string) $b['global_rank'] : '');
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
            access_denied();
        }
    }

    /** @return array<mixed> */
    public function getCategoriesMenu(): array
    {
        $filter      = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];
        $page        = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        $currentUser = CurrentUser::get();
        $userExpand  = $currentUser->rawAttributes['expand'] ?? false;

        $query = '
SELECT
  id, name, permalink, nb_images, global_rank,
  date_last, max_date_last, count_images, count_categories
FROM ' . CATEGORIES_TABLE . ' INNER JOIN ' . USER_CACHE_CATEGORIES_TABLE . '
  ON id = cat_id and user_id = ' . $currentUser->id;

        if (!$userExpand and !$filter['enabled']) {
            $where = '
(id_uppercat is NULL';
            $category = is_array($page['category'] ?? null) ? $page['category'] : null;
            if ($category !== null) {
                $uppercats = is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '';
                $where .= ' OR id_uppercat IN (' . $uppercats . ')';
            }
            $where .= ')';
        } else {
            $where = '
  ' . get_sql_condition_FandF(['visible_categories' => 'id'], null, true);
        }

        $where = trigger_change('get_categories_menu_sql_where', $where, $userExpand, $filter['enabled']);

        $query .= '
WHERE ' . $where . '
;';

        $cats             = [];
        $selectedCategory = is_array($page['category'] ?? null) ? $page['category'] : null;
        foreach ($this->conn->executeQuery($query)->fetchAllAssociative() as $row) {
            $childDateLast = ($row['max_date_last'] ?? null) > ($row['date_last'] ?? null);
            $row           = array_merge($row, [
                'NAME'        => trigger_change('render_category_name', $row['name'], 'get_categories_menu'),
                'TITLE'       => $this->getDisplayImagesCount(
                    is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0,
                    is_numeric($row['count_images']) ? (int) $row['count_images'] : 0,
                    is_numeric($row['count_categories']) ? (int) $row['count_categories'] : 0,
                    false,
                    ' / '
                ),
                'URL'         => make_index_url(['category' => $row]),
                'LEVEL'       => substr_count(is_scalar($row['global_rank']) ? (string) $row['global_rank'] : '', '.') + 1,
                'SELECTED'    => ($selectedCategory !== null && $selectedCategory['id'] == $row['id']) ? true : false,
                'IS_UPPERCAT' => ($selectedCategory !== null && $selectedCategory['id_uppercat'] == $row['id']) ? true : false,
            ]);
            if (Config::indexNewIcon()) {
                $row['icon_ts'] = get_icon(is_string($row['max_date_last']) || is_null($row['max_date_last']) ? $row['max_date_last'] : (is_scalar($row['max_date_last']) ? (string) $row['max_date_last'] : null), $childDateLast);
            }
            $cats[] = $row;
            if ($selectedCategory !== null && $row['id'] == ($selectedCategory['id'] ?? null)) {
                $cat                      = is_array($page['category'] ?? null) ? $page['category'] : [];
                $cat['count_categories']  = $row['count_categories'];
                $page['category']         = $cat;
            }
        }
        usort($cats, $this->globalRankCompare(...));

        ServiceLocator::get(FilterService::class)->updateCategoriesWithFilteredData($cats);

        return $cats;
    }

    /** @return array<mixed>|null */
    public function getCatInfo(int|string $id): ?array
    {
        $cat = $this->catRepo->findCategoryById((int) $id);
        if (empty($cat)) {
            return null;
        }

        foreach ($cat as $k => $v) {
            if ($cat[$k] == 'true' or $cat[$k] == 'false') {
                $cat[$k] = BoolUtil::fromMixed($cat[$k]);
            }
        }

        $upperIds = explode(',', is_scalar($cat['uppercats']) ? (string) $cat['uppercats'] : '');
        if (count($upperIds) == 1) {
            $cat['upper_names'] = [[
                'id'        => $cat['id'],
                'name'      => $cat['name'],
                'permalink' => $cat['permalink'],
            ]];
        } else {
            $query = '
  SELECT id, name, permalink
    FROM ' . CATEGORIES_TABLE . '
    WHERE id IN (' . (is_scalar($cat['uppercats']) ? (string) $cat['uppercats'] : '') . ')
  ;';
            $names = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), null, 'id');

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
        $page   = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $result = trigger_change('get_category_preferred_image_orders', [
            [l10n('Default'),                        '',                     true],
            [l10n('Photo title, A &rarr; Z'),        'name ASC',             true],
            [l10n('Photo title, Z &rarr; A'),        'name DESC',            true],
            [l10n('Date created, new &rarr; old'),   'date_creation DESC',   true],
            [l10n('Date created, old &rarr; new'),   'date_creation ASC',    true],
            [l10n('Date posted, new &rarr; old'),    'date_available DESC',  true],
            [l10n('Date posted, old &rarr; new'),    'date_available ASC',   true],
            [l10n('Rating score, high &rarr; low'),  'rating_score DESC',    Config::rateEnabled()],
            [l10n('Rating score, low &rarr; high'),  'rating_score ASC',     Config::rateEnabled()],
            [l10n('Visits, high &rarr; low'),        'hit DESC',             true],
            [l10n('Visits, low &rarr; high'),        'hit ASC',              true],
            [l10n('Permissions'),                    'level DESC',           is_admin()],
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
            if ($fullname) {
                $option = strip_tags(get_cat_display_name_cache(is_scalar($category['uppercats']) ? (string) $category['uppercats'] : '', null));
            } else {
                $option  = str_repeat('&nbsp;', (3 * substr_count(is_scalar($category['global_rank']) ? (string) $category['global_rank'] : '', '.')));
                $option .= '- ';
                $option .= strip_tags((string) trigger_change('render_category_name', is_scalar($category['name']) ? (string) $category['name'] : '', 'display_select_categories'));
            }
            $tplCats[is_scalar($category['id']) ? (string) $category['id'] : ''] = $option;
        }
        $template->assign($blockname, $tplCats);
        $template->assign($blockname . '_selected', $selecteds);
    }

    /** @param int[]|string $selecteds */
    public function displaySelectCatWrapper(string $query, array|string $selecteds, string $blockname, bool|string $fullname = true): void
    {
        $categories = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
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
  FROM ' . CATEGORIES_TABLE . '
  WHERE ';
        foreach ($ids as $num => $categoryId) {
            is_numeric($categoryId) or trigger_error('get_subcat_ids expecting numeric, not ' . gettype($categoryId), E_USER_WARNING);
            if ($num > 0) {
                $query .= '
    OR ';
            }
            $query .= 'uppercats ' . DB_REGEX_OPERATOR . ' \'(^|,)' . $categoryId . '(,|$)\'';
        }
        $query .= '
;';
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'));
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
  FROM ' . OLD_PERMALINKS_TABLE . '
  WHERE permalink IN (' . $in . ')
UNION
SELECT id, permalink, 0 AS is_old
  FROM ' . CATEGORIES_TABLE . '
  WHERE permalink IN (' . $in . ')
;';
        $permaHash = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), null, 'permalink');

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

            $displayText .= l10n_dec('%d photo', '%d photos', $catCountImages);

            if ($catCountCategories == 0 or $catNbImages == $catCountImages) {
                if (!$shortMessage) {
                    $displayText .= ' ' . l10n('in this album');
                }
            } else {
                $displayText .= ' ' . l10n_dec('in %d sub-album', 'in %d sub-albums', $catCountCategories);
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
  FROM ' . CATEGORIES_TABLE . ' AS c
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.category_id = c.id
  WHERE ';
            if ($recursive) {
                $query .= '
    (c.id=' . (is_numeric($category['id']) ? (int) $category['id'] : 0) . ' OR uppercats LIKE \'' . addslashes(is_scalar($category['uppercats']) ? (string) $category['uppercats'] : '') . ',%\')';
            } else {
                $query .= '
    c.id=' . (is_numeric($category['id']) ? (int) $category['id'] : 0);
            }
            $query .= '
    ' . get_sql_condition_FandF(['forbidden_categories' => 'c.id', 'visible_categories' => 'c.id', 'visible_images' => 'image_id'], "\n  AND") . '
  ORDER BY ' . DB_RANDOM_FUNCTION . '()
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
FROM ' . CATEGORIES_TABLE . ' as c
  LEFT JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.category_id = c.id
  LEFT JOIN ' . IMAGES_TABLE . ' AS i
    ON ic.image_id = i.id
      AND i.level<=' . (is_numeric($userdata['level']) ? (int) $userdata['level'] : 0);

        if (isset($filterDays)) {
            $query .= ' AND i.date_available > ' . SqlExpr::recentPeriodExpr($filterDays);
        }

        if (!empty($userdata['forbidden_categories'])) {
            $query .= '
  WHERE c.id NOT IN (' . (is_scalar($userdata['forbidden_categories']) ? (string) $userdata['forbidden_categories'] : '') . ')';
        }

        $query .= '
  GROUP BY c.id';

        $userdata['last_photo_date'] = null;
        $cats                        = [];
        foreach ($this->conn->executeQuery($query)->fetchAllAssociative() as $row) {
            $row['user_id']          = is_scalar($userdata['id']) ? $userdata['id'] : 0;
            $row['nb_categories']    = 0;
            $row['count_categories'] = 0;
            $row['count_images']     = is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0;
            $row['max_date_last']    = $row['date_last'];
            if ($row['date_last'] > $userdata['last_photo_date']) {
                $userdata['last_photo_date'] = $row['date_last'];
            }
            $cats[is_scalar($row['cat_id']) ? (string) $row['cat_id'] : ''] = $row;
        }

        uasort($cats, $this->globalRankCompare(...));

        foreach ($cats as $cat) {
            if (!isset($cat['id_uppercat'])) {
                continue;
            }
            $catUppercatKey = is_scalar($cat['id_uppercat']) ? (string) $cat['id_uppercat'] : '';
            if (!isset($cats[$catUppercatKey])) {
                continue;
            }

            $parent = &$cats[$catUppercatKey];
            $parent['nb_categories']++;

            do {
                $parent['count_images']     += is_numeric($cat['nb_images']) ? (int) $cat['nb_images'] : 0;
                $parent['count_categories']++;

                if ((empty($parent['max_date_last'])) or ($parent['max_date_last'] < $cat['date_last'])) {
                    $parent['max_date_last'] = $cat['date_last'];
                }

                if (!isset($parent['id_uppercat'])) {
                    break;
                }
                $parent = &$cats[is_scalar($parent['id_uppercat']) ? (string) $parent['id_uppercat'] : ''];
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
                $parent['count_images']     = (is_numeric($parent['count_images'] ?? null) ? (int) $parent['count_images'] : 0) - (is_numeric($cat['nb_images'] ?? null) ? (int) $cat['nb_images'] : 0);
                $parent['count_categories'] = (is_numeric($parent['count_categories'] ?? null) ? (int) $parent['count_categories'] : 0) - 1 - (is_numeric($cat['count_categories'] ?? null) ? (int) $cat['count_categories'] : 0);

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
  FROM ' . IMAGES_TABLE . ' i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON id=ic.image_id
  WHERE category_id IN (' . implode(',', $catIds) . ')';

        if ($usePermissions) {
            $query .= get_sql_condition_FandF(['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'id'], "\n  AND");
        }

        $query .= (empty($extraImagesWhereSql) ? '' : " \nAND (" . $extraImagesWhereSql . ')') . '
  GROUP BY id';

        if ($mode == 'AND' and count($catIds) > 1) {
            $query .= '
  HAVING COUNT(DISTINCT category_id)=' . count($catIds);
        }
        $query .= "\n" . (empty($orderBy) ? Config::orderBy() : $orderBy);

        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'));
    }

    /**
     * @param array<mixed>  $items
     * @param int[]         $excludedCatIds
     * @return array<string, array<string, mixed>>
     */
    public function getCommonCategories(array $items, ?int $max = null, array $excludedCatIds = [], bool $usePermissions = true): array
    {
        if (empty($items)) {
            return [];
        }

        $query = '
SELECT
    c.id,
    c.uppercats,
    count(*) AS counter
  FROM ' . IMAGE_CATEGORY_TABLE . '
    INNER JOIN ' . CATEGORIES_TABLE . ' c ON category_id = id
  WHERE image_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $items)) . ')';

        if ($usePermissions) {
            $query .= get_sql_condition_FandF(['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id'], "\n    AND");
        }

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
            $cats[is_scalar($row['id']) ? (string) $row['id'] : ''] = $row;
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
        $page       = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $commonCats = $this->getCommonCategories($items, Config::relatedAlbumsDisplayLimit(), $excludedCatIds);

        if (count($commonCats) == 0) {
            return [];
        }

        $catIds = [];
        foreach ($commonCats as $cat) {
            foreach (explode(',', is_scalar($cat['uppercats']) ? (string) $cat['uppercats'] : '') as $uppercat) {
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
  FROM ' . CATEGORIES_TABLE . '
  WHERE id IN (' . implode(',', array_keys($catIds)) . ')
;';
        $cats = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        usort($cats, $this->globalRankCompare(...));

        $indexOfCat = [];

        foreach ($cats as $idx => $cat) {
            $catIdKey             = is_scalar($cat['id']) ? (string) $cat['id'] : '';
            $indexOfCat[$catIdKey] = $idx;
            $cats[$idx]['LEVEL']  = substr_count(is_scalar($cat['global_rank']) ? (string) $cat['global_rank'] : '', '.') + 1;
            $cats[$idx]['name']   = trigger_change('render_category_name', is_scalar($cat['name']) ? (string) $cat['name'] : '', $cat);

            if (isset($commonCats[$catIdKey])) {
                $cats[$idx]['count_images'] = $commonCats[$catIdKey]['counter'];

                $urlParams = [];
                if (isset($page['category'])) {
                    $urlParams['category'] = $page['category'];
                    $urlParams['combined_categories'] = [$cat];
                    $combined = is_array($page['combined_categories'] ?? null) ? $page['combined_categories'] : null;
                    if ($combined !== null) {
                        $urlParams['combined_categories'] = array_merge($combined, [$cat]);
                    }
                } else {
                    $urlParams['category'] = $cat;
                }

                $cats[$idx]['url'] = make_index_url($urlParams);
            }

            if (!empty($cat['id_uppercat']) and ($cats[$idx]['count_images'] ?? 0) > 0) {
                foreach (array_slice(explode(',', is_scalar($cat['uppercats']) ? (string) $cat['uppercats'] : ''), 0, -1) as $uppercatId) {
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
