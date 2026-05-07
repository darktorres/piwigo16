<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Filter\FilterService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;

final class CategoryCatsRenderer
{
    public function render(): void
    {
        $template = TemplateRegistry::current();
        $logger = LoggerRegistry::current();
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        $currentUser = CurrentUser::get();
        $user = $currentUser->rawAttributes;

        $query = '
SELECT SQL_CALC_FOUND_ROWS
    c.*,
    user_representative_picture_id,
    nb_images,
    date_last,
    max_date_last,
    count_images,
    nb_categories,
    count_categories
  FROM ' . Tables::categories() . ' c
    INNER JOIN ' . Tables::userCacheCategories() . ' ucc
    ON id = cat_id
    AND user_id = ' . $currentUser->id . '
  WHERE count_images > 0
';

        if ('recent_cats' == $page['section']) {
            $query .= '
  AND ' . PermissionService::get()->getRecentPhotosSql('date_last');
        } else {
            $query .= '
  AND id_uppercat ' . (!isset($page['category']) || !is_array($page['category']) ? 'is NULL' : '= ' . (is_scalar($page['category']['id'] ?? null) ? (string) $page['category']['id'] : ''));
        }

        $query .= '
      ' . PermissionService::get()->getSqlConditionFandF(['visible_categories' => 'id'], 'AND');
        $query .= '
-- after conditions
';

        if ('recent_cats' != $page['section']) {
            $query .= '
  ORDER BY `rank`';
        }

        $nb_cats_page = Config::nbCategoriesPage();
        $query .= '
  LIMIT ' . $nb_cats_page . ' OFFSET ' . (is_numeric($page['startcat'] ?? null) ? (int) $page['startcat'] : 0) . '
;';

        $query = EventDispatcher::dispatch('loc_begin_index_category_thumbnails_query', $query);

        $conn = ServiceLocator::get(Connection::class);
        $catCatsRows = $conn->executeQuery($query)->fetchAllAssociative();
        $page['total_categories'] = $conn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();

        $categories = [];
        $category_ids = [];
        $image_ids = [];
        $user_representative_updates_for = [];

        foreach ($catCatsRows as $row) {
            $row['is_child_date_last'] = ($row['max_date_last'] ?? null) > ($row['date_last'] ?? null);

            if (!empty($row['user_representative_picture_id'])) {
                $image_id = $row['user_representative_picture_id'];
            } elseif (!empty($row['representative_picture_id'])) {
                $image_id = $row['representative_picture_id'];
            } elseif (Config::allowRandomRepresentative()) {
                $image_id = get_random_image_in_category($row);
            } elseif ($row['count_categories'] > 0 and $row['count_images'] > 0) {
                $subquery = '
SELECT representative_picture_id
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::userCacheCategories() . '
  ON id = cat_id and user_id = ' . $currentUser->id . '
  WHERE uppercats LIKE \'' . (is_scalar($row['uppercats']) ? (string) $row['uppercats'] : '') . ',%\'
    AND representative_picture_id IS NOT NULL'
                    . PermissionService::get()->getSqlConditionFandF(['visible_categories' => 'id'], "\n  AND") . '
  ORDER BY ' . Dml::RANDOM_FUNCTION . '()
  LIMIT 1
;';
                $subval = ServiceLocator::get(Connection::class)->executeQuery($subquery)->fetchOne();
                if ($subval !== false) {
                    $image_id = is_numeric($subval) ? (int) $subval : null;
                }
            }

            if (isset($image_id)) {
                if (Config::representativeCacheOnSubcats() and $row['user_representative_picture_id'] != $image_id) {
                    $user_representative_updates_for[is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''] = $image_id;
                }
                $row['representative_picture_id'] = $image_id;
                $image_ids[] = $image_id;
                $categories[] = $row;
                $category_ids[] = $row['id'];
            } else {
                $logger->info(sprintf(
                    '[CategoryCatsRenderer] category #%u was listed in SQL but no image_id found, so it was skipped',
                    is_numeric($row['id']) ? (int) $row['id'] : 0
                ));
            }
            unset($image_id);
        }

        if (Config::displayFromto()) {
            if (count($category_ids) > 0) {
                $query = '
SELECT
    category_id,
    MIN(date_creation) AS `from`,
    MAX(date_creation) AS `to`
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::images() . ' ON image_id = id
  WHERE category_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $category_ids)) . ')
' . PermissionService::get()->getSqlConditionFandF(['visible_categories' => 'category_id', 'visible_images' => 'id'], 'AND') . '
  GROUP BY category_id
;';
                $dates_of_category = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), null, 'category_id');
            }
        }

        if ('recent_cats' == $page['section']) {
            usort($categories, global_rank_compare(...));
        }

        if (count($categories) > 0) {
            $infos_of_image = [];
            $new_image_ids = [];

            $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $image_ids)) . ')
;';
            foreach (ServiceLocator::get(Connection::class)->executeQuery($query)->fetchAllAssociative() as $row) {
                if ($row['level'] <= $user['level']) {
                    $infos_of_image[is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''] = $row;
                } else {
                    foreach ($categories as &$category) {
                        if ($row['id'] == $category['representative_picture_id']) {
                            $image_id = get_random_image_in_category($category);
                            if (isset($image_id) and !in_array($image_id, $image_ids)) {
                                $new_image_ids[] = $image_id;
                            }
                            if (Config::representativeCacheOnLevel()) {
                                $user_representative_updates_for[is_scalar($category['id'] ?? null) ? (string) $category['id'] : ''] = $image_id;
                            }
                            $category['representative_picture_id'] = $image_id;
                        }
                    }
                    unset($category);
                }
            }

            if (count($new_image_ids) > 0) {
                $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $new_image_ids) . ')
;';
                foreach (ServiceLocator::get(Connection::class)->executeQuery($query)->fetchAllAssociative() as $row) {
                    $infos_of_image[is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''] = $row;
                }
            }

            foreach ($infos_of_image as &$info) {
                $info['src_image'] = new SrcImage($info);
            }
            unset($info);
        }

        if (count($user_representative_updates_for)) {
            $updates = [];
            foreach ($user_representative_updates_for as $cat_id => $image_id) {
                $updates[] = [
                    'user_id' => $user['id'],
                    'cat_id' => $cat_id,
                    'user_representative_picture_id' => $image_id,
                ];
            }
            Dml::massUpdates(
                Tables::userCacheCategories(),
                ['primary' => ['user_id', 'cat_id'], 'update' => ['user_representative_picture_id']],
                $updates
            );
        }

        if (count($categories) > 0) {
            ServiceLocator::get(FilterService::class)->updateCategoriesWithFilteredData($categories);

            $template->setFilename('index_category_thumbnails', 'mainpage_categories.tpl');
            EventDispatcher::notify('loc_begin_index_category_thumbnails', $categories);
            $tpl_thumbnails_var = [];

            foreach ($categories as $category) {
                if (0 == $category['count_images']) {
                    continue;
                }

                $category['name'] = EventDispatcher::dispatch(
                    'render_category_name',
                    is_scalar($category['name'] ?? null) ? (string) $category['name'] : '',
                    'subcatify_category_name'
                );

                if ($page['section'] == 'recent_cats') {
                    $name = get_cat_display_name_cache(is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '', null);
                } else {
                    $name = $category['name'];
                }

                $repPicIdRaw = $category['representative_picture_id'] ?? null;
                $repPicId = (is_string($repPicIdRaw) || is_int($repPicIdRaw)) ? $repPicIdRaw : null;
                $representative_infos = ($repPicId !== null && is_array($infos_of_image[$repPicId] ?? null)) ? $infos_of_image[$repPicId] : [];

                $tpl_var = array_merge($category, [
                    'ID' => $category['id'],
                    'representative' => $representative_infos,
                    'TN_ALT' => strip_tags((string) $category['name']),
                    'URL' => UrlService::get()->makeIndexUrl(['category' => $category]),
                    'CAPTION_NB_IMAGES' => get_display_images_count(
                        is_numeric($category['nb_images'] ?? null) ? (int) $category['nb_images'] : 0,
                        is_numeric($category['count_images']) ? (int) $category['count_images'] : 0,
                        is_numeric($category['count_categories'] ?? null) ? (int) $category['count_categories'] : 0,
                        true,
                        '<br>'
                    ),
                    'DESCRIPTION' => EventDispatcher::dispatch(
                        'render_category_literal_description',
                        EventDispatcher::dispatch('render_category_description', $category['comment'] ?? null, 'subcatify_category_description')
                    ),
                    'NAME' => $name,
                ]);
                if (Config::indexNewIcon()) {
                    $tpl_var['icon_ts'] = get_icon(
                        is_scalar($category['max_date_last'] ?? null) ? (string) $category['max_date_last'] : null,
                        (bool) ($category['is_child_date_last'] ?? false)
                    );
                }

                if (Config::displayFromto()) {
                    $catIdRaw = $category['id'] ?? null;
                    $catId = (is_string($catIdRaw) || is_int($catIdRaw)) ? $catIdRaw : null;
                    if ($catId !== null && isset($dates_of_category[$catId])) {
                        $from = $dates_of_category[$catId]['from'] ?? null;
                        $to = $dates_of_category[$catId]['to'] ?? null;
                        if (!empty($from)) {
                            $tpl_var['INFO_DATES'] = format_fromto(
                                (is_string($from) || is_int($from)) ? $from : null,
                                (is_string($to) || is_int($to)) ? $to : null
                            );
                        }
                    }
                }

                $tpl_thumbnails_var[] = $tpl_var;
            }

            $tpl_thumbnails_var_selection = $tpl_thumbnails_var;
            $derivative_params = EventDispatcher::dispatch('get_index_album_derivative_params', ImageStdParams::getByType(IMG_THUMB));
            $tpl_thumbnails_var_selection = EventDispatcher::dispatch('loc_end_index_category_thumbnails', $tpl_thumbnails_var_selection);
            $template->assign([
                'maxRequests' => Config::maxRequests(),
                'category_thumbnails' => $tpl_thumbnails_var_selection,
                'derivative_params' => $derivative_params,
            ]);

            $template->assignVarFromHandle('CATEGORIES', 'index_category_thumbnails');

            $page['cats_navigation_bar'] = [];
            if ($page['total_categories'] > Config::nbCategoriesPage()) {
                $page['cats_navigation_bar'] = create_navigation_bar(
                    UrlService::get()->duplicateIndexUrl([], ['startcat']),
                    is_numeric($page['total_categories']) ? (int) $page['total_categories'] : 0,
                    is_numeric($page['startcat'] ?? null) ? (int) $page['startcat'] : 0,
                    Config::nbCategoriesPage(),
                    true,
                    'startcat'
                );
            }

            $template->assign('cats_navbar', $page['cats_navigation_bar']);
        }

        pwg_debug('end CategoryCatsRenderer');
    }
}
