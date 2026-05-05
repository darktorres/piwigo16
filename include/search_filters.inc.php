<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

$filters_views_raw = conf_get_param('filters_views', \Piwigo\Config\Config::defaultFiltersViews());
$filters_views_str = is_array($filters_views_raw) ? $filters_views_raw : (is_string($filters_views_raw) ? $filters_views_raw : '');
/** @var array<string, array<string,mixed>> $filters_views */
$filters_views = safe_unserialize($filters_views_str);

$template->assign('display_filter', $filters_views);

// we add isset($page['search_details']) in this condition because it only
// applies to regular search, not the legacy qsearch. As Piwigo 14 will still
// be able to show an old quicksearch result, we must check this condtion too.
if ('search' == $page['section'] and isset($page['search_details'])) {
    $display_filters = $filters_views;

    foreach ($filters_views as $filt_name => $filt_conf) {
        if (isset($filt_conf['access'])) {
            if ($filt_conf['access'] == 'everybody' or ($filt_conf['access'] == 'admins-only' and is_admin()) or ($filt_conf['access'] == 'registered-users' and is_classic_user())) {
                $display_filters[$filt_name]['access'] = true;
            } else {
                $display_filters[$filt_name]['access'] = false;
            }
        }
    }

    require_once(PHPWG_ROOT_PATH.'include/functions_search.inc.php');

    /** @var array<string, mixed> $my_search */
    $my_search = get_search_array($page['search']);
    /** @var array<string, mixed> $my_search_fields_tmp */
    $my_search_fields_tmp = is_array($my_search['fields'] ?? null) ? $my_search['fields'] : [];
    $my_search['fields'] = $my_search_fields_tmp;

    $page['search_details']['forbidden'] = get_sql_condition_FandF(
        [
        'forbidden_categories' => 'category_id',
        'visible_categories' => 'category_id',
        'visible_images' => 'id',
    ],
        "\n  AND"
    );

    // we want filters to be filled with values related to current items ONLY IF we have some filters filled
    if ($page['search_details']['has_filters_filled']) {
        $search_items = [-1];
        if (!empty($page['items'])) {
            $search_items = $page['items'];
        }

        $search_items_clause = 'image_id IN ('.implode(',', $search_items).')';
    } else {
        $search_items_clause = '1=1';
    }

    if (isset($my_search['fields']['allwords']) and !($display_filters['words']['access'])) {
        unset($my_search['fields']['allwords']);
    }

    if (isset($my_search['fields']['tags']) and $display_filters['tags']['access']) {
        $filter_tags = [];

        // PERF: get_available_tags() can be expensive with many tags; consider sharing
        // the result with the menu builder to avoid a second identical query.

        $other_filters_items = get_items_for_filter('tags');
        if (false === $other_filters_items) {
            $filter_tags = get_available_tags();
            usort($filter_tags, fn (mixed $a, mixed $b): int => tag_alpha_compare(is_array($a) ? $a : [], is_array($b) ? $b : []));
        } else {
            $filter_tags = get_common_tags($other_filters_items, 0);

            // the user may have started a search on 2 or more tags that have no
            // intersection. In this case, $search_items is empty and get_common_tags
            // returns nothing. We should still display the list of selected tags. We
            // have to "force" them in the list.
            $tags_field = is_array($my_search['fields']['tags']) ? $my_search['fields']['tags'] : [];
            $tags_words_raw = is_array($tags_field['words'] ?? null) ? $tags_field['words'] : [];
            $missing_tag_ids = array_diff(
                array_map(fn (mixed $v) => is_scalar($v) ? (string) $v : '', $tags_words_raw),
                array_map(fn (mixed $v) => is_scalar($v) ? (string) $v : '', array_column($filter_tags, 'id'))
            );

            if (count($missing_tag_ids) > 0) {
                $filter_tags = array_merge(get_available_tags(array_map(fn (mixed $v) => is_numeric($v) ? (int) $v : 0, $missing_tag_ids)), $filter_tags);
            }
        }

        $template->assign('TAGS', $filter_tags);

        $filter_tag_ids = count($filter_tags) > 0 ? array_column($filter_tags, 'id') : [];

        // in case the search has forbidden tags for current user, we need to filter the search rule
        $tags_field2 = is_array($my_search['fields']['tags']) ? $my_search['fields']['tags'] : [];
        $tags_words2_raw = is_array($tags_field2['words'] ?? null) ? $tags_field2['words'] : [];
        $tags_words2 = array_map(fn (mixed $v) => is_scalar($v) ? (string) $v : '', $tags_words2_raw);
        $filter_tag_ids_str = array_map(fn (mixed $v) => is_scalar($v) ? (string) $v : '', $filter_tag_ids);
        $tags_field2['words'] = array_intersect($tags_words2, $filter_tag_ids_str);
        $my_search['fields']['tags'] = $tags_field2;
    } elseif (isset($my_search['fields']['tags']) and !($display_filters['tags']['access'])) {
        unset($my_search['fields']['tags']);
    }

    if (isset($my_search['fields']['expert'])) {
        if (!$display_filters['expert']['access']) {
            unset($my_search['fields']['expert']);
        } else {
            load_language('help_quick_search.lang');
        }
    }

    if (isset($my_search['fields']['author']) and $display_filters['author']['access']) {
        $filter_clause = get_clause_for_filter('author');

        $query = '
SELECT
    author,
    COUNT(DISTINCT(id)) AS counter
  FROM '.IMAGES_TABLE.' AS i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE '.$filter_clause.'
    AND author IS NOT NULL
  GROUP BY author
;';

        if (!preg_match('/^image_id IN/', $filter_clause)) {
            // we use persistent_cache only for fetching lines filtered only by permissions
            $cache_key = $persistent_cache->make_key('filter_author_rows'.$user['id'].$user['cache_update_time']);
            $filter_rows = [];
            $filter_rows = [];
            if (!$persistent_cache->get($cache_key, $filter_rows)) {
                $filter_rows = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
                $persistent_cache->set($cache_key, $filter_rows);
            }
        } else {
            $filter_rows = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        }

        $author_names = [];
        foreach ($filter_rows as $author) {
            $author_names[] = $author['author'];
        }
        $template->assign('AUTHORS', $filter_rows);

        // in case the search has forbidden authors for current user, we need to filter the search rule
        $author_field = is_array($my_search['fields']['author']) ? $my_search['fields']['author'] : [];
        $author_words_raw = is_array($author_field['words'] ?? null) ? $author_field['words'] : [];
        $author_words_str = array_map(fn (mixed $v) => is_scalar($v) ? (string) $v : '', $author_words_raw);
        $author_names_str = array_map(fn (mixed $v) => is_scalar($v) ? (string) $v : '', $author_names);
        $author_field['words'] = array_intersect($author_words_str, $author_names_str);
        $my_search['fields']['author'] = $author_field;
    } elseif (isset($my_search['fields']['author']) and !($display_filters['author']['access'])) {
        unset($my_search['fields']['author']);
    }

    if (isset($my_search['fields']['date_posted']) and $display_filters['post_date']['access']) {
        $filter_clause = get_clause_for_filter('date_posted');
        $cache_key = $persistent_cache->make_key('filter_date_posted'.$user['id'].$user['cache_update_time']);
        $date_posted = null;
        $cache_hit_date_posted = false;
        $set_persistent_cache = !preg_match('/^image_id IN/', $filter_clause) and !($cache_hit_date_posted = $persistent_cache->get($cache_key, $date_posted));

        if (!$cache_hit_date_posted) {
            $query = '
SELECT
    SUBDATE(NOW(), INTERVAL 24 HOUR) AS 24h,
    SUBDATE(NOW(), INTERVAL 7 DAY) AS 7d,
    SUBDATE(NOW(), INTERVAL 30 DAY) AS 30d,
    SUBDATE(NOW(), INTERVAL 3 MONTH) AS 3m,
    SUBDATE(NOW(), INTERVAL 6 MONTH) AS 6m
;';
            $thresholds = get_dbal_connection()->executeQuery($query)->fetchAllAssociative()[0];

            $query = '
SELECT
    DISTINCT id,
    date_available as date
  FROM '.IMAGES_TABLE.' AS i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE '.$filter_clause.'
;';

            $list_of_dates = [];
            $pre_counters = [];

            foreach (\Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
                ->executeQuery($query)->fetchAllAssociative() as $row) {
                foreach ($thresholds as $threshold => $date_limit) {
                    if ($row['date'] > $date_limit) {
                        $pre_counters[$threshold] = ($pre_counters[$threshold] ?? 0) + 1;
                    }
                }

                [$date_without_time] = explode(' ', is_scalar($row['date']) ? (string) $row['date'] : '');
                [$y, $m] = explode('-', $date_without_time);

                $list_of_dates[$y]['months'][$y.'-'.$m]['days'][$date_without_time]['count'] = ($list_of_dates[$y]['months'][$y.'-'.$m]['days'][$date_without_time]['count'] ?? 0) + 1;
                $list_of_dates[$y]['months'][$y.'-'.$m]['count'] = ($list_of_dates[$y]['months'][$y.'-'.$m]['count'] ?? 0) + 1;
                $list_of_dates[$y]['count'] = ($list_of_dates[$y]['count'] ?? 0) + 1;
            }

            $date_posted = [
              'pre_counters' => $pre_counters,
              'list_of_dates' => $list_of_dates,
            ];

            if ($set_persistent_cache) {
                // for this filter, we do not store in cache the $filter_rows : for a big gallery it may
                // take more than 10MB. It is smarter to store in cache the result of the computation,
                // which is just around 100 bytes.
                $persistent_cache->set($cache_key, $date_posted);
            }
        }

        $label_for_threshold = [
          '24h' => l10n('last 24 hours'),
          '7d' => l10n('last 7 days'),
          '30d' => l10n('last 30 days'),
          '3m' => l10n('last 3 months'),
          '6m' => l10n('last 6 months'),
        ];

        $counters = [];
        foreach (array_keys($label_for_threshold) as $threshold) {
            $counters[$threshold] = [
              'label' => $label_for_threshold[$threshold],
              'counter' => ($date_posted['pre_counters'] ?? [])[$threshold] ?? 0,
            ];
        }

        foreach (array_keys($date_posted['list_of_dates'] ?? []) as $y) {
            $date_posted['list_of_dates'][$y]['label'] = l10n('year %d', $y);

            foreach (array_keys($date_posted['list_of_dates'][$y]['months'] ?? []) as $ym) {
                list(, $m) = explode('-', (string) $ym);
                $month_days = $date_posted['list_of_dates'][$y]['months'][$ym]['days'] ?? null;
                $date_posted['list_of_dates'][$y]['months'][$ym]['label'] = $lang['month'][(int)$m].' '.$y;

                if (is_array($month_days)) {
                    foreach (array_keys($month_days) as $ymd) {
                        list(, , $d) = explode('-', (string) $ymd);
                        $month_days[$ymd]['label'] = format_date($ymd);
                    }
                    $date_posted['list_of_dates'][$y]['months'][$ym]['days'] = $month_days;
                }
            }
        }
        if (isset($date_posted['list_of_dates'])) {
            krsort($date_posted['list_of_dates']);
        }

        $template->assign('LIST_DATE_POSTED', $date_posted['list_of_dates'] ?? []);
        $template->assign('DATE_POSTED', $counters);
    } elseif (isset($my_search['fields']['date_posted']) and !($display_filters['post_date']['access'])) {
        unset($my_search['fields']['date_posted']);
    }

    if (isset($my_search['fields']['date_created']) and $display_filters['creation_date']['access']) {
        $filter_clause = get_clause_for_filter('date_created');
        $cache_key = $persistent_cache->make_key('filter_date_created'.$user['id'].$user['cache_update_time']);
        $date_created = null;
        $cache_hit_date_created = false;
        $set_persistent_cache = !preg_match('/^image_id IN/', $filter_clause) and !($cache_hit_date_created = $persistent_cache->get($cache_key, $date_created));

        if (!$cache_hit_date_created) {
            $query = '
SELECT
    SUBDATE(NOW(), INTERVAL 7 DAY) AS 7d,
    SUBDATE(NOW(), INTERVAL 30 DAY) AS 30d,
    SUBDATE(NOW(), INTERVAL 3 MONTH) AS 3m,
    SUBDATE(NOW(), INTERVAL 6 MONTH) AS 6m,
    SUBDATE(NOW(), INTERVAL 12 MONTH) AS 12m
;';
            $thresholds = get_dbal_connection()->executeQuery($query)->fetchAllAssociative()[0];

            $query = '
SELECT
    DISTINCT id,
    date_creation as date
  FROM '.IMAGES_TABLE.' AS i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE '.$filter_clause.'
;';

            $list_of_dates = [];
            $pre_counters = [];

            foreach (\Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
                ->executeQuery($query)->fetchAllAssociative() as $row) {
                if (!empty($row['date'])) {
                    foreach ($thresholds as $threshold => $date_limit) {
                        if ($row['date'] > $date_limit) {
                            $pre_counters[$threshold] = ($pre_counters[$threshold] ?? 0) + 1;
                        }
                    }

                    [$date_without_time] = explode(' ', is_scalar($row['date']) ? (string) $row['date'] : '');
                    [$y, $m] = explode('-', $date_without_time);

                    $list_of_dates[$y]['months'][$y.'-'.$m]['days'][$date_without_time]['count'] = ($list_of_dates[$y]['months'][$y.'-'.$m]['days'][$date_without_time]['count'] ?? 0) + 1;
                    $list_of_dates[$y]['months'][$y.'-'.$m]['count'] = ($list_of_dates[$y]['months'][$y.'-'.$m]['count'] ?? 0) + 1;
                    $list_of_dates[$y]['count'] = ($list_of_dates[$y]['count'] ?? 0) + 1;
                }
            }

            $date_created = [
              'pre_counters' => $pre_counters,
              'list_of_dates' => $list_of_dates,
            ];

            if ($set_persistent_cache) {
                // for this filter, we do not store in cache the $filter_rows : for a big gallery it may
                // take more than 10MB. It is smarter to store in cache the result of the computation,
                // which is just around 100 bytes.
                $persistent_cache->set($cache_key, $date_created);
            }
        }

        $label_for_threshold = [
          '7d' => l10n('last 7 days'),
          '30d' => l10n('last 30 days'),
          '3m' => l10n('last 3 months'),
          '6m' => l10n('last 6 months'),
          '12m' => l10n('last 12 months'),
        ];

        $counters = [];
        foreach (array_keys($label_for_threshold) as $threshold) {
            $counters[$threshold] = [
              'label' => $label_for_threshold[$threshold],
              'counter' => ($date_created['pre_counters'] ?? [])[$threshold] ?? 0,
            ];
        }

        foreach (array_keys($date_created['list_of_dates'] ?? []) as $y) {
            $date_created['list_of_dates'][$y]['label'] = l10n('year %d', $y);

            foreach (array_keys($date_created['list_of_dates'][$y]['months'] ?? []) as $ym) {
                list(, $m) = explode('-', (string) $ym);
                $month_days = $date_created['list_of_dates'][$y]['months'][$ym]['days'] ?? null;
                $date_created['list_of_dates'][$y]['months'][$ym]['label'] = $lang['month'][(int)$m].' '.$y;

                if (is_array($month_days)) {
                    foreach (array_keys($month_days) as $ymd) {
                        list(, , $d) = explode('-', (string) $ymd);
                        $month_days[$ymd]['label'] = format_date($ymd);
                    }
                    $date_created['list_of_dates'][$y]['months'][$ym]['days'] = $month_days;
                }
            }
        }
        if (isset($date_created['list_of_dates'])) {
            krsort($date_created['list_of_dates']);
        }

        $template->assign('LIST_DATE_CREATED', $date_created['list_of_dates'] ?? []);
        $template->assign('DATE_CREATED', $counters);
    } elseif (isset($my_search['fields']['date_created']) and !($display_filters['creation_date']['access'])) {
        unset($my_search['fields']['date_created']);
    }

    if (isset($my_search['fields']['added_by']) and $display_filters['added_by']['access']) {
        $filter_clause = get_clause_for_filter('added_by');

        $query = '
SELECT
    COUNT(DISTINCT(id)) AS counter,
    added_by AS added_by_id
  FROM '.IMAGES_TABLE.' AS i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE '.$filter_clause.'
  GROUP BY added_by_id
  ORDER BY counter DESC
;';

        if (!preg_match('/^image_id IN/', $filter_clause)) {
            // we use persistent_cache only for fetching lines filtered only by permissions
            $cache_key = $persistent_cache->make_key('filter_added_by_rows'.$user['id'].$user['cache_update_time']);
            $filter_rows = [];
            if (!$persistent_cache->get($cache_key, $filter_rows)) {
                $filter_rows = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
                $persistent_cache->set($cache_key, $filter_rows);
            }
        } else {
            $filter_rows = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        }

        $added_by = $filter_rows;
        $user_ids = [];

        if (count($added_by) > 0) {
            // now let's find the usernames of added_by users
            foreach ($added_by as $i) {
                $user_ids[] = is_numeric($i['added_by_id']) ? (int) $i['added_by_id'] : 0;
            }

            $query = '
SELECT
    '.\Piwigo\Config\Config::userFields()['id'].' AS id,
    '.\Piwigo\Config\Config::userFields()['username'].' AS username
  FROM '.USERS_TABLE.'
  WHERE '.\Piwigo\Config\Config::userFields()['id'].' IN ('.implode(',', $user_ids).')
;';
            $username_of = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'username', 'id');

            foreach (array_keys($added_by) as $added_by_idx) {
                $added_by_id_raw = $added_by[$added_by_idx]['added_by_id'];
                $added_by_id = is_numeric($added_by_id_raw) ? (int) $added_by_id_raw : 0;
                $added_by[$added_by_idx]['added_by_name'] = $username_of[(string) $added_by_id] ?? 'user #'.$added_by_id.' (deleted)';
            }
        }

        $template->assign('ADDED_BY', $added_by);

        // in case the search has forbidden added_by users for current user, we need to filter the search rule
        $added_by_field_raw = is_array($my_search['fields']['added_by']) ? $my_search['fields']['added_by'] : [];
        $added_by_field_int = array_map(fn (mixed $v) => is_numeric($v) ? (int) $v : 0, $added_by_field_raw);
        $my_search['fields']['added_by'] = array_intersect($added_by_field_int, $user_ids);
    } elseif (isset($my_search['fields']['added_by']) and !($display_filters['added_by']['access'])) {
        unset($my_search['fields']['added_by']);
    }

    if (isset($my_search['fields']['cat']) and $display_filters['album']['access']) {
        $cat_field = is_array($my_search['fields']['cat']) ? $my_search['fields']['cat'] : [];
        $cat_words = is_array($cat_field['words'] ?? null) ? $cat_field['words'] : [];
        if (!empty($cat_words)) {
            $fullname_of = [];

            $query = '
SELECT
    id,
    uppercats
  FROM '.CATEGORIES_TABLE.'
    INNER JOIN '.USER_CACHE_CATEGORIES_TABLE.' ON id = cat_id AND user_id = '.$user['id'].'
  WHERE id IN ('.implode(',', array_map(fn (mixed $v) => is_numeric($v) ? (int) $v : 0, $cat_words)).')
;';
            foreach (\Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
                ->executeQuery($query)->fetchAllAssociative() as $row) {
                $uppercats_val = $row['uppercats'];
                $cat_display_name = get_cat_display_name_cache(
                    is_scalar($uppercats_val) ? (string) $uppercats_val : '',
                    'admin.php?page=album-' // DEFERRED: admin URL may be inappropriate in gallery context
                );
                $row['fullname'] = strip_tags($cat_display_name);

                $fullname_of[is_scalar($row['id']) ? (string) $row['id'] : ''] = $row['fullname'];
            }

            $template->assign('fullname_of', json_encode($fullname_of));

            // in case the search has forbidden albums for current user, we need to filter the search rule
            $cat_words_str = array_map(fn (mixed $v) => is_scalar($v) ? (string) $v : '', $cat_words);
            $cat_field['words'] = array_intersect($cat_words_str, array_keys($fullname_of));
            $my_search['fields']['cat'] = $cat_field;
        }
    } elseif (isset($my_search['fields']['cat']) and !($display_filters['album']['access'])) {
        unset($my_search['fields']['cat']);
    }

    if (isset($my_search['fields']['filetypes']) and $display_filters['file_type']['access']) {
        $filter_clause = get_clause_for_filter('filetypes');

        // get all file extensions for this user in the gallery, whatever the current filters
        $cache_key = $persistent_cache->make_key('file_exts'.$user['id'].$user['cache_update_time']);
        $all_exts = [];
        if (!$persistent_cache->get($cache_key, $all_exts)) {
            $query = '
SELECT
    SUBSTRING_INDEX(path, ".", -1) AS ext,
    COUNT(DISTINCT(id)) AS counter
  FROM '.IMAGES_TABLE.' AS i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE 1=1'.$page['search_details']['forbidden'].'
  GROUP BY ext
  ORDER BY counter DESC
;';
            $all_exts = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'counter', 'ext');
            $persistent_cache->set($cache_key, $all_exts);
        }

        if (preg_match('/^image_id IN/', $filter_clause)) {
            $query = '
SELECT
    SUBSTRING_INDEX(path, ".", -1) AS ext,
    COUNT(DISTINCT(id)) AS counter
  FROM '.IMAGES_TABLE.' AS i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE '.$filter_clause.'
  GROUP BY ext
  ORDER BY counter DESC
;';
            $filtered_exts = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'counter', 'ext');

            $exts = [];
            foreach ($all_exts as $ext => $counter) {
                $exts[$ext] = $filtered_exts[$ext] ?? 0;
            }

            $template->assign('FILETYPES', $exts);
        } else {
            $template->assign('FILETYPES', $all_exts);
        }
    } elseif (isset($my_search['fields']['filetypes']) and !($display_filters['file_type']['access'])) {
        unset($my_search['fields']['filetypes']);
    }

    // For rating
    if (\Piwigo\Config\Config::rateEnabled()) {
        $template->assign('SHOW_FILTER_RATINGS', true);

        if (isset($my_search['fields']['ratings']) and $display_filters['rating']['access']) {
            $filter_clause = get_clause_for_filter('ratings');

            $cache_key = $persistent_cache->make_key('filter_ratings'.$user['id'].$user['cache_update_time']);

            $ratings = null;
            $cache_hit_ratings = false;
            $set_persistent_cache = !preg_match('/^image_id IN/', $filter_clause) and !($cache_hit_ratings = $persistent_cache->get($cache_key, $ratings));

            if (!$cache_hit_ratings) {
                $query = '
SELECT
    DISTINCT id,
    rating_score
  FROM '.IMAGES_TABLE.' AS i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE '.$filter_clause;

                $filter_rows = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

                $ratings = array_fill(0, 6, 0);

                foreach ($filter_rows as $row) {
                    $r = 5;

                    if (!isset($row['rating_score'])) {
                        $r = 0;
                    } else {
                        for ($i = 1; $i <= 4; $i++) {
                            if ($row['rating_score'] < $i) {
                                $r = $i;
                                break;
                            }
                        }
                    }

                    $ratings[$r]++;
                }

                if ($set_persistent_cache) {
                    // for this filter, we do not store in cache the $filter_rows : for a big gallery it may
                    // take more than 10MB. It is smarter to store in cache the result of the computation,
                    // which is just around 100 bytes.
                    $persistent_cache->set($cache_key, $ratings);
                }
            }
            $template->assign('RATING', $ratings);
        } elseif (isset($my_search['fields']['ratings']) and !($display_filters['rating']['access'])) {
            unset($my_search['fields']['ratings']);
        }
    } else {
        $template->assign('SHOW_FILTER_RATINGS', false);
        if (isset($my_search['fields']['ratings'])) {
            unset($my_search['fields']['ratings']);
        }
    }

    // For filesize
    if (isset($my_search['fields']['filesize_min']) && isset($my_search['fields']['filesize_max']) and $display_filters['file_size']['access']) {
        $filter_clause = get_clause_for_filter('filesize');

        $filesizes = [];
        $filesize = [];

        $query = '
SELECT
    DISTINCT id,
    filesize
  FROM '.IMAGES_TABLE.' AS i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE '.$filter_clause.'
;';
        foreach (\Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
            ->executeQuery($query)->fetchAllAssociative() as $row) {
            $fs_val = is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0;
            $key_fs = sprintf('%.1f', $fs_val / 1024);
            $filesizes[$key_fs] = ($filesizes[$key_fs] ?? 0) + 1;
        }

        if (empty($filesizes)) { // arbitrary values, only used when no photos on the gallery
            $filesizes = [0, 1, 2, 5, 8, 15];
        }

        $unique_filesizes = array_keys($filesizes);
        sort($unique_filesizes, SORT_NUMERIC);

        $filesize['list'] = implode(',', $unique_filesizes);

        $filesize['bounds'] = [
          'min' => $unique_filesizes[0],
          'max' => end($unique_filesizes),
        ];

        // warning: we will (hopefully) have smarter values for filters. The min/max of the
        // current search won't always be the first/last values found. It's going to be a
        // problem with this way to select selected values
        $fs_min_raw = $my_search['fields']['filesize_min'];
        $fs_max_raw = $my_search['fields']['filesize_max'];
        $filesize['selected'] = [
          'min' => !empty($fs_min_raw) ? sprintf('%.1f', (is_numeric($fs_min_raw) ? (float) $fs_min_raw : 0.0) / 1024) : $unique_filesizes[0],
          'max' => !empty($fs_max_raw) ? sprintf('%.1f', (is_numeric($fs_max_raw) ? (float) $fs_max_raw : 0.0) / 1024) : end($unique_filesizes),
        ];

        $template->assign('FILESIZE', $filesize);
    } elseif (isset($my_search['fields']['filesize_min']) && isset($my_search['fields']['filesize_max']) and !($display_filters['file_size']['access'])) {
        unset($my_search['fields']['filesize_min']);
        unset($my_search['fields']['filesize_max']);
    }

    if (isset($my_search['fields']['ratios']) and $display_filters['ratio']['access']) {
        $filter_clause = get_clause_for_filter('ratios');

        $cache_key = $persistent_cache->make_key('filter_ratios'.$user['id'].$user['cache_update_time']);

        $ratios = null;
        $cache_hit_ratios = false;
        $set_persistent_cache = !preg_match('/^image_id IN/', $filter_clause) and !($cache_hit_ratios = $persistent_cache->get($cache_key, $ratios));

        if (!$cache_hit_ratios) {
            $query = '
SELECT
    DISTINCT id,
    width,
    height
  FROM '.IMAGES_TABLE.' as i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE '.$filter_clause.'
    AND width IS NOT NULL
    AND height IS NOT NULL
;';

            $filter_rows = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

            $ratios = [
              'Portrait' => 0,
              'square' => 0,
              'Landscape' => 0,
              'Panorama' => 0,
            ];

            foreach ($filter_rows as $row) {
                $row_width = is_numeric($row['width']) ? (float) $row['width'] : 0.0;
                $row_height = is_numeric($row['height']) ? (float) $row['height'] : 0.0;
                if ($row_width <= 0 and $row_height <= 0) {
                    continue;
                }

                $r = $row_height > 0 ? $row_width / $row_height : 0.0;
                if ($r < 0.95) {
                    $ratios['Portrait']++;
                } elseif ($r >= 0.95 and $r <= 1.05) {
                    $ratios['square']++;
                } elseif ($r > 1.05 and $r < 2) {
                    $ratios['Landscape']++;
                } elseif ($r >= 2) {
                    $ratios['Panorama']++;
                }
            }

            if ($set_persistent_cache) {
                // for this filter, we do not store in cache the $filter_rows : for a big gallery it may
                // take more than 10MB. It is smarter to store in cache the result of the computation,
                // which is just around 100 bytes.
                $persistent_cache->set($cache_key, $ratios);
            }
        }
        $template->assign('RATIOS', $ratios);
    } elseif (isset($my_search['fields']['ratios']) and !($display_filters['ratio']['access'])) {
        unset($my_search['fields']['ratios']);
    }

    if (isset($my_search['fields']['height_min']) and isset($my_search['fields']['height_max']) and $display_filters['height']['access']) {
        $filter_clause = get_clause_for_filter('height');

        $query = '
SELECT
    height
  FROM '.IMAGES_TABLE.' as i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE '.$filter_clause.'
    AND height IS NOT NULL
  GROUP BY height
  ORDER BY height ASC
;';

        if (!preg_match('/^image_id IN/', $filter_clause)) {
            // we use persistent_cache only for fetching lines filtered only by permissions
            $cache_key = $persistent_cache->make_key('filter_height_rows'.$user['id'].$user['cache_update_time']);
            $filter_rows = [];
            if (!$persistent_cache->get($cache_key, $filter_rows)) {
                $filter_rows = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'height');
                $persistent_cache->set($cache_key, $filter_rows);
            }
        } else {
            $filter_rows = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'height');
        }

        $heights = $filter_rows;

        $height = [
          'list' => implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $heights)),
          'bounds' => [
            'min' => $heights[0],
            'max' => end($heights),
          ],
          'selected' => [
            'min' => !empty($my_search['fields']['height_min']) ? $my_search['fields']['height_min'] : $heights[0],
            'max' => !empty($my_search['fields']['height_max']) ? $my_search['fields']['height_max'] : end($heights),
          ],
        ];

        $template->assign('HEIGHT', $height);
    } elseif (isset($my_search['fields']['height_min']) && isset($my_search['fields']['height_max']) and !($display_filters['height']['access'])) {
        unset($my_search['fields']['height_min']);
        unset($my_search['fields']['height_max']);
    }

    if (isset($my_search['fields']['width_min']) and isset($my_search['fields']['width_max']) and $display_filters['width']['access']) {
        $filter_clause = get_clause_for_filter('width');

        $query = '
SELECT
    width
  FROM '.IMAGES_TABLE.' as i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE '.$filter_clause.'
    AND width IS NOT NULL
  GROUP BY width
  ORDER BY width ASC
;';

        if (!preg_match('/^image_id IN/', $filter_clause)) {
            // we use persistent_cache only for fetching lines filtered only by permissions
            $cache_key = $persistent_cache->make_key('filter_width_rows'.$user['id'].$user['cache_update_time']);
            $filter_rows = [];
            if (!$persistent_cache->get($cache_key, $filter_rows)) {
                $filter_rows = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'width');
                $persistent_cache->set($cache_key, $filter_rows);
            }
        } else {
            $filter_rows = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'width');
        }

        $widths = $filter_rows;

        $width = [
          'list' => implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $widths)),
          'bounds' => [
            'min' => $widths[0],
            'max' => end($widths),
          ],
          'selected' => [
            'min' => !empty($my_search['fields']['width_min']) ? $my_search['fields']['width_min'] : $widths[0],
            'max' => !empty($my_search['fields']['width_max']) ? $my_search['fields']['width_max'] : end($widths),
          ],
        ];

        $template->assign('WIDTH', $width);
    } elseif (isset($my_search['fields']['width_min']) && isset($my_search['fields']['width_max']) and !($display_filters['width']['access'])) {
        unset($my_search['fields']['width_min']);
        unset($my_search['fields']['width_max']);
    }

    $template->assign(
        [
        'GP' => json_encode($my_search),
        'SEARCH_ID' => $page['search'],
    ]
    );

    $sliders_data = [];
    if (isset($filesize)) {
        $sliders_data['filesizes'] = [
            'values'   => array_map('floatval', explode(',', $filesize['list'])),
            'selected' => [
                'min' => $filesize['selected']['min'],
                'max' => $filesize['selected']['max'],
            ],
            'text' => l10n('between %s and %s MB'),
        ];
    }
    if (isset($height)) {
        $sliders_data['heights'] = [
            'values'   => array_map('intval', explode(',', $height['list'])),
            'selected' => [
                'min' => $height['selected']['min'],
                'max' => $height['selected']['max'],
            ],
            'text' => l10n('between %d and %d pixels'),
        ];
    }
    if (isset($width)) {
        $sliders_data['widths'] = [
            'values'   => array_map('intval', explode(',', $width['list'])),
            'selected' => [
                'min' => $width['selected']['min'],
                'max' => $width['selected']['max'],
            ],
            'text' => l10n('between %d and %d pixels'),
        ];
    }

    $template->assign('page_data_json', json_encode(
        [
        'global_params'               => $my_search,
        'search_id'                   => $page['search'],
        'fullname_of_cat'             => $fullname_of ?? [],
        'show_filter_ratings'         => \Piwigo\Config\Config::rateEnabled() ? true : false,
        'sliders'                     => $sliders_data,
        'str_word_widget_label'       => l10n('Search for words'),
        'str_tags_widget_label'       => l10n('Tag'),
        'str_album_widget_label'      => l10n('Album'),
        'str_author_widget_label'     => l10n('Author'),
        'str_added_by_widget_label'   => l10n('Added by'),
        'str_filetypes_widget_label'  => l10n('File type'),
        'str_ratio_widget_label'      => l10n('Ratio'),
        'str_rating_widget_label'     => l10n('Rating'),
        'str_no_rating'               => l10n('no rate'),
        'str_between_rating'          => l10n('between %d and %d'),
        'str_filesize_widget_label'   => l10n('Filesize'),
        'str_width_widget_label'      => l10n('Width'),
        'str_height_widget_label'     => l10n('Height'),
        'str_expert_widget_label'     => l10n('Expert mode'),
        'str_empty_search_top_alt'    => l10n('Fill in the filters to start a search'),
        'str_empty_search_bot_alt'    => l10n('Pre-established filters are proposed, but you can add or remove them using the "Choose filters" button.'),
        'str_search_in_ab'            => l10n('Search in albums'),
        'str_ratios_label'            => [
            'Portrait'  => l10n('Portrait'),
            'square'    => l10n('square'),
            'Landscape' => l10n('Landscape'),
            'Panorama'  => l10n('Panorama'),
        ],
    ],
        JSON_HEX_TAG | JSON_UNESCAPED_UNICODE
    ));

    if (0 == $page['start'] and !isset($page['chronology_field']) and isset($page['search_details'])) {
        if (isset($page['search_details']['matching_cat_ids'])) {
            $cat_ids = $page['search_details']['matching_cat_ids'];
            if (count($cat_ids)) {
                $query = '
SELECT
    c.*
  FROM '.CATEGORIES_TABLE.' AS c
    INNER JOIN '.USER_CACHE_CATEGORIES_TABLE.' ON c.id = cat_id and user_id = '.$user['id'].'
  WHERE id IN ('.implode(',', $cat_ids).')
;';
                $cats = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
                usort($cats, fn (array $a, array $b): int => name_compare($a, $b));
                $albums_found = [];
                foreach ($cats as $cat) {
                    $single_link = false;
                    $albums_found[] = get_cat_display_name_cache(
                        is_scalar($cat['uppercats'] ?? null) ? (string) $cat['uppercats'] : '',
                        '',
                        $single_link
                    );
                }

                if (count($albums_found) > 0) {
                    $template->assign('ALBUMS_FOUND', $albums_found);
                }
            }
        }
        if (isset($page['search_details']['matching_tag_ids'])) {
            $tag_ids = $page['search_details']['matching_tag_ids'];

            if (count($tag_ids) > 0) {
                $tags = get_available_tags($tag_ids);
                usort($tags, fn (mixed $a, mixed $b): int => tag_alpha_compare(is_array($a) ? $a : [], is_array($b) ? $b : []));
                $tags_found = [];
                foreach ($tags as $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }
                    $url = make_index_url(
                        [
                        'tags' => [$tag],
            ]
                    );
                    $tags_found[] = sprintf('<a href="%s">%s</a>', $url, is_scalar($tag['name'] ?? null) ? (string) $tag['name'] : '');
                }

                if (count($tags_found) > 0) {
                    $template->assign('TAGS_FOUND', $tags_found);
                }
            }
        }
    }
}
