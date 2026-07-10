<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $lang
 * @var array<string, mixed> $page
 * @var \Template $template
 * @var array<string, mixed> $user
 */
global $conf, $lang, $page, $persistent_cache, $template, $user;
if (! $persistent_cache instanceof PersistentCache) {
    fatal_error('persistent cache not initialized');
}

$filters_views_conf = conf_get_param('filters_views', null);
if (is_array($filters_views_conf) || is_string($filters_views_conf)) {
    $filters_views_raw = safe_unserialize($filters_views_conf);
} else {
    $filters_views_raw = $conf['default_filters_views'];
}

if (! is_array($filters_views_raw)) {
    $filters_views_raw = $conf['default_filters_views'];
    if (! is_array($filters_views_raw)) {
        $filters_views_raw = [];
    }
}

// 'last_filters_conf' is a lone boolean flag stored alongside the per-filter
// settings in this config value (see admin/configuration.php); every other
// entry is a settings array. This file only ever reads the per-filter
// arrays by name, so drop the flag here to give $filters_views a uniform,
// narrow shape.
/** @var array<string, array<string, mixed>> $filters_views */
$filters_views = array_filter($filters_views_raw, 'is_array');

$template->assign('display_filter', $filters_views);

// we add isset($page['search_details']) in this condition because it only
// applies to regular search, not the legacy qsearch. As Piwigo 14 will still
// be able to show an old quicksearch result, we must check this condtion too.
if ($page['section'] == 'search' and isset($page['search_details']) and is_array($page['search_details'])) {
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

    include_once PHPWG_ROOT_PATH . 'include/functions_search.inc.php';

    // $user['id'] and $user['cache_update_time'] key every persistent-cache entry
    // built by the filter blocks below; narrow them once to real strings.
    $user_id = is_scalar($user['id'] ?? null) ? (string) $user['id'] : '';
    $user_cache_update_time = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';

    // $lang['month'] is the language file's month-index (1-12) to name map;
    // narrow it once for the date_posted/date_created breakdowns below.
    $lang_month = is_array($lang['month'] ?? null) ? $lang['month'] : [];

    $search_id = $page['search'] ?? null;
    $search_id = (is_int($search_id) || is_string($search_id)) ? $search_id : '';
    $my_search = get_search_array($search_id);
    if (! is_array($my_search)) {
        // get_search_array() only returns false when unserialize() fails on
        // malformed data; this file only runs for an already-validated
        // search (get_search_info() calls bad_request() otherwise), so this
        // is just a defensive fallback keeping the rest of this file array-typed.
        $my_search = [];
    }
    if (! isset($my_search['fields']) || ! is_array($my_search['fields'])) {
        $my_search['fields'] = [];
    }

    /** @var array<string, mixed> $search_fields */
    $search_fields = &$my_search['fields'];

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
        if (! empty($page['items']) && is_array($page['items'])) {
            /** @var list<int|string|float|bool> $search_items */
            $search_items = array_values(array_filter($page['items'], 'is_scalar'));
        }

        $search_items_clause = 'image_id IN (' . implode(',', $search_items) . ')';
    } else {
        $search_items_clause = '1=1';
    }

    if (isset($search_fields['allwords']) and ! ($display_filters['words']['access'])) {
        unset($search_fields['allwords']);
    }

    if (isset($search_fields['tags']) and $display_filters['tags']['access']) {
        $filter_tags = [];

        // TODO calling get_available_tags(), with lots of photos/albums/tags may cost time,
        // we should reuse the result if already executed (for building the menu for example)

        if (! is_array($search_fields['tags'])) {
            $search_fields['tags'] = [];
        }

        $tag_words = [];
        if (is_array($search_fields['tags']['words'] ?? null)) {
            foreach ($search_fields['tags']['words'] as $tag_word) {
                if (is_int($tag_word) || is_string($tag_word)) {
                    $tag_words[] = $tag_word;
                }
            }
        }

        /**
         * @param array<int, array<string, mixed>> $tags
         * @return list<int|string>
         */
        $extract_tag_ids = static function (array $tags): array {
            $ids = [];
            foreach ($tags as $tag) {
                if (! is_array($tag)) {
                    continue;
                }
                $tag_id = $tag['id'] ?? null;
                if (is_int($tag_id) || is_string($tag_id)) {
                    $ids[] = $tag_id;
                }
            }
            return $ids;
        };

        $other_filters_items = get_items_for_filter('tags');
        if ($other_filters_items === false) {
            $filter_tags = get_available_tags();
            usort($filter_tags, tag_alpha_compare(...));
        } else {
            $tag_filter_items = [];
            foreach ($other_filters_items as $other_filter_item) {
                if (is_numeric($other_filter_item)) {
                    $tag_filter_items[] = (int) $other_filter_item;
                }
            }

            $filter_tags = get_common_tags($tag_filter_items, 0);

            // the user may have started a search on 2 or more tags that have no
            // intersection. In this case, $search_items is empty and get_common_tags
            // returns nothing. We should still display the list of selected tags. We
            // have to "force" them in the list.
            $missing_tag_ids = array_diff($tag_words, $extract_tag_ids($filter_tags));

            if (count($missing_tag_ids) > 0) {
                $filter_tags = array_merge(get_available_tags($missing_tag_ids), $filter_tags);
            }
        }

        $template->assign('TAGS', $filter_tags);

        $filter_tag_ids = count($filter_tags) > 0 ? $extract_tag_ids($filter_tags) : [];

        // in case the search has forbidden tags for current user, we need to filter the search rule
        $search_fields['tags']['words'] = array_intersect($tag_words, $filter_tag_ids);
    } elseif (isset($search_fields['tags'])) {
        unset($search_fields['tags']);
    }

    if (isset($search_fields['expert'])) {
        if (! $display_filters['expert']['access']) {
            unset($search_fields['expert']);
        } else {
            load_language('help_quick_search.lang');
        }
    }

    if (isset($search_fields['author']) and $display_filters['author']['access']) {
        $filter_clause = get_clause_for_filter('author');

        $query = '
SELECT
    author,
    COUNT(DISTINCT(id)) AS counter
  FROM ' . IMAGES_TABLE . ' AS i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filter_clause . '
    AND author IS NOT NULL
  GROUP BY author
;';

        if (! preg_match('/^image_id IN/', $filter_clause)) {
            // we use persistent_cache only for fetching lines filtered only by permissions
            $cache_key = $persistent_cache->make_key('filter_author_rows' . $user_id . $user_cache_update_time);
            $filter_rows = null;
            if (! $persistent_cache->get($cache_key, $filter_rows)) {
                $filter_rows = query2array($query);
                $persistent_cache->set($cache_key, $filter_rows);
            }
        } else {
            $filter_rows = query2array($query);
        }

        if (! is_array($filter_rows)) {
            // the persistent cache should only ever hold what query2array() just
            // produced above; re-run the query if a corrupted entry slipped through.
            $filter_rows = query2array($query);
        }

        // the persistent cache stores this row set as plain mixed data, so
        // validate each row's shape defensively rather than trusting it.
        $author_names = [];
        $authors = [];
        foreach ($filter_rows as $author_row) {
            if (! is_array($author_row)) {
                continue;
            }
            $authors[] = $author_row;

            $author_name = $author_row['author'] ?? null;
            if (is_string($author_name)) {
                $author_names[] = $author_name;
            }
        }
        $template->assign('AUTHORS', $authors);

        if (! is_array($search_fields['author'])) {
            $search_fields['author'] = [];
        }

        $author_words = [];
        if (is_array($search_fields['author']['words'] ?? null)) {
            foreach ($search_fields['author']['words'] as $author_word) {
                if (is_string($author_word)) {
                    $author_words[] = $author_word;
                }
            }
        }

        // in case the search has forbidden authors for current user, we need to filter the search rule
        $search_fields['author']['words'] = array_intersect($author_words, $author_names);
    } elseif (isset($search_fields['author'])) {
        unset($search_fields['author']);
    }

    if (isset($search_fields['date_posted']) and $display_filters['post_date']['access']) {
        $filter_clause = get_clause_for_filter('date_posted');
        $cache_key = $persistent_cache->make_key('filter_date_posted' . $user_id . $user_cache_update_time);
        // we use persistent_cache only for fetching lines filtered only by permissions
        $cache_applicable = ! preg_match('/^image_id IN/', $filter_clause);
        $cached_date_posted = null;
        $has_cached_date_posted = $cache_applicable and $persistent_cache->get($cache_key, $cached_date_posted);

        if ($has_cached_date_posted
            and is_array($cached_date_posted)
            and is_array($cached_date_posted['pre_counters'] ?? null)
            and is_array($cached_date_posted['list_of_dates'] ?? null)
        ) {
            $pre_counters = $cached_date_posted['pre_counters'];
            $list_of_dates = $cached_date_posted['list_of_dates'];
        } else {
            $query = '
SELECT
    SUBDATE(NOW(), INTERVAL 24 HOUR) AS 24h,
    SUBDATE(NOW(), INTERVAL 7 DAY) AS 7d,
    SUBDATE(NOW(), INTERVAL 30 DAY) AS 30d,
    SUBDATE(NOW(), INTERVAL 3 MONTH) AS 3m,
    SUBDATE(NOW(), INTERVAL 6 MONTH) AS 6m
;';
            $thresholds = query2array($query)[0];

            $query = '
SELECT
    DISTINCT id,
    date_available as date
  FROM ' . IMAGES_TABLE . ' AS i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filter_clause . '
;';

            $list_of_dates = [];
            $pre_counters = [];

            $result = pwg_query($query);
            while ($row = pwg_db_fetch_assoc($result)) {
                foreach ($thresholds as $threshold => $date_limit) {
                    if ($row['date'] > $date_limit) {
                        @$pre_counters[$threshold]++;
                    }
                }

                [$date_without_time] = explode(' ', (string) $row['date']);
                [$y, $m] = explode('-', $date_without_time);

                $list_of_dates[$y]['months'][$y . '-' . $m]['days'][$date_without_time]['count'] =
                    ($list_of_dates[$y]['months'][$y . '-' . $m]['days'][$date_without_time]['count'] ?? 0) + 1;
                $list_of_dates[$y]['months'][$y . '-' . $m]['count'] =
                    ($list_of_dates[$y]['months'][$y . '-' . $m]['count'] ?? 0) + 1;
                $list_of_dates[$y]['count'] = ($list_of_dates[$y]['count'] ?? 0) + 1;
            }

            if ($cache_applicable) {
                // for this filter, we do not store in cache the $filter_rows : for a big gallery it may
                // take more than 10MB. It is smarter to store in cache the result of the computation,
                // which is just around 100 bytes.
                $persistent_cache->set(
                    $cache_key,
                    [
                        'pre_counters' => $pre_counters,
                        'list_of_dates' => $list_of_dates,
                    ]
                );
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
                'counter' => $pre_counters[$threshold] ?? 0,
            ];
        }

        // $list_of_dates may have come from the persistent cache above, which
        // stores it as plain mixed data — validate each nesting level
        // defensively rather than trusting its shape.
        foreach (array_keys($list_of_dates) as $y) {
            $year_bucket = $list_of_dates[$y] ?? null;
            if (! is_array($year_bucket)) {
                continue;
            }
            $year_bucket['label'] = l10n('year %d', $y);

            $months_bucket = $year_bucket['months'] ?? null;
            if (is_array($months_bucket)) {
                foreach (array_keys($months_bucket) as $ym) {
                    $month_bucket = $months_bucket[$ym] ?? null;
                    if (! is_array($month_bucket)) {
                        continue;
                    }

                    [, $m] = explode('-', (string) $ym);
                    $month_name = $lang_month[(int) $m] ?? null;
                    $month_name = is_string($month_name) ? $month_name : '';
                    $month_bucket['label'] = $month_name . ' ' . $y;

                    $days_bucket = $month_bucket['days'] ?? null;
                    if (is_array($days_bucket)) {
                        foreach (array_keys($days_bucket) as $ymd) {
                            $day_bucket = $days_bucket[$ymd] ?? null;
                            if (! is_array($day_bucket)) {
                                continue;
                            }
                            $day_bucket['label'] = format_date($ymd);
                            $days_bucket[$ymd] = $day_bucket;
                        }
                        $month_bucket['days'] = $days_bucket;
                    }

                    $months_bucket[$ym] = $month_bucket;
                }
                $year_bucket['months'] = $months_bucket;
            }

            $list_of_dates[$y] = $year_bucket;
        }
        krsort($list_of_dates);

        $template->assign('LIST_DATE_POSTED', $list_of_dates);
        $template->assign('DATE_POSTED', $counters);
    } elseif (isset($search_fields['date_posted'])) {
        unset($search_fields['date_posted']);
    }

    if (isset($search_fields['date_created']) and $display_filters['creation_date']['access']) {
        $filter_clause = get_clause_for_filter('date_created');
        $cache_key = $persistent_cache->make_key('filter_date_created' . $user_id . $user_cache_update_time);
        // we use persistent_cache only for fetching lines filtered only by permissions
        $cache_applicable = ! preg_match('/^image_id IN/', $filter_clause);
        $cached_date_created = null;
        $has_cached_date_created = $cache_applicable and $persistent_cache->get($cache_key, $cached_date_created);

        if ($has_cached_date_created
            and is_array($cached_date_created)
            and is_array($cached_date_created['pre_counters'] ?? null)
            and is_array($cached_date_created['list_of_dates'] ?? null)
        ) {
            $pre_counters = $cached_date_created['pre_counters'];
            $list_of_dates = $cached_date_created['list_of_dates'];
        } else {
            $query = '
SELECT
    SUBDATE(NOW(), INTERVAL 7 DAY) AS 7d,
    SUBDATE(NOW(), INTERVAL 30 DAY) AS 30d,
    SUBDATE(NOW(), INTERVAL 3 MONTH) AS 3m,
    SUBDATE(NOW(), INTERVAL 6 MONTH) AS 6m,
    SUBDATE(NOW(), INTERVAL 12 MONTH) AS 12m
;';
            $thresholds = query2array($query)[0];

            $query = '
SELECT
    DISTINCT id,
    date_creation as date
  FROM ' . IMAGES_TABLE . ' AS i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filter_clause . '
;';

            $list_of_dates = [];
            $pre_counters = [];

            $result = pwg_query($query);
            while ($row = pwg_db_fetch_assoc($result)) {
                if (! empty($row['date'])) {
                    foreach ($thresholds as $threshold => $date_limit) {
                        if ($row['date'] > $date_limit) {
                            @$pre_counters[$threshold]++;
                        }
                    }

                    [$date_without_time] = explode(' ', (string) $row['date']);
                    [$y, $m] = explode('-', $date_without_time);

                    $list_of_dates[$y]['months'][$y . '-' . $m]['days'][$date_without_time]['count'] =
                        ($list_of_dates[$y]['months'][$y . '-' . $m]['days'][$date_without_time]['count'] ?? 0) + 1;
                    $list_of_dates[$y]['months'][$y . '-' . $m]['count'] =
                        ($list_of_dates[$y]['months'][$y . '-' . $m]['count'] ?? 0) + 1;
                    $list_of_dates[$y]['count'] = ($list_of_dates[$y]['count'] ?? 0) + 1;
                }
            }

            if ($cache_applicable) {
                // for this filter, we do not store in cache the $filter_rows : for a big gallery it may
                // take more than 10MB. It is smarter to store in cache the result of the computation,
                // which is just around 100 bytes.
                $persistent_cache->set(
                    $cache_key,
                    [
                        'pre_counters' => $pre_counters,
                        'list_of_dates' => $list_of_dates,
                    ]
                );
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
                'counter' => $pre_counters[$threshold] ?? 0,
            ];
        }

        // $list_of_dates may have come from the persistent cache above, which
        // stores it as plain mixed data — validate each nesting level
        // defensively rather than trusting its shape.
        foreach (array_keys($list_of_dates) as $y) {
            $year_bucket = $list_of_dates[$y] ?? null;
            if (! is_array($year_bucket)) {
                continue;
            }
            $year_bucket['label'] = l10n('year %d', $y);

            $months_bucket = $year_bucket['months'] ?? null;
            if (is_array($months_bucket)) {
                foreach (array_keys($months_bucket) as $ym) {
                    $month_bucket = $months_bucket[$ym] ?? null;
                    if (! is_array($month_bucket)) {
                        continue;
                    }

                    [, $m] = explode('-', (string) $ym);
                    $month_name = $lang_month[(int) $m] ?? null;
                    $month_name = is_string($month_name) ? $month_name : '';
                    $month_bucket['label'] = $month_name . ' ' . $y;

                    $days_bucket = $month_bucket['days'] ?? null;
                    if (is_array($days_bucket)) {
                        foreach (array_keys($days_bucket) as $ymd) {
                            $day_bucket = $days_bucket[$ymd] ?? null;
                            if (! is_array($day_bucket)) {
                                continue;
                            }
                            $day_bucket['label'] = format_date($ymd);
                            $days_bucket[$ymd] = $day_bucket;
                        }
                        $month_bucket['days'] = $days_bucket;
                    }

                    $months_bucket[$ym] = $month_bucket;
                }
                $year_bucket['months'] = $months_bucket;
            }

            $list_of_dates[$y] = $year_bucket;
        }
        krsort($list_of_dates);

        $template->assign('LIST_DATE_CREATED', $list_of_dates);
        $template->assign('DATE_CREATED', $counters);
    } elseif (isset($search_fields['date_created'])) {
        unset($search_fields['date_created']);
    }

    if (isset($search_fields['added_by']) and $display_filters['added_by']['access']) {
        $filter_clause = get_clause_for_filter('added_by');

        $query = '
SELECT
    COUNT(DISTINCT(id)) AS counter,
    added_by AS added_by_id
  FROM ' . IMAGES_TABLE . ' AS i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filter_clause . '
  GROUP BY added_by_id
  ORDER BY counter DESC
;';

        if (! preg_match('/^image_id IN/', $filter_clause)) {
            // we use persistent_cache only for fetching lines filtered only by permissions
            $cache_key = $persistent_cache->make_key('filter_added_by_rows' . $user_id . $user_cache_update_time);
            $filter_rows = null;
            if (! $persistent_cache->get($cache_key, $filter_rows)) {
                $filter_rows = query2array($query);
                $persistent_cache->set($cache_key, $filter_rows);
            }
        } else {
            $filter_rows = query2array($query);
        }

        if (! is_array($filter_rows)) {
            // the persistent cache should only ever hold what query2array() just
            // produced above; re-run the query if a corrupted entry slipped through.
            $filter_rows = query2array($query);
        }

        // the persistent cache stores this row set as plain mixed data, so
        // validate each row's shape defensively rather than trusting it.
        $added_by = [];
        foreach ($filter_rows as $added_by_row) {
            if (is_array($added_by_row)) {
                $added_by[] = $added_by_row;
            }
        }

        $user_ids = [];

        if (count($added_by) > 0) {
            // now let's find the usernames of added_by users
            foreach ($added_by as $row) {
                $row_added_by_id = $row['added_by_id'] ?? null;
                if (is_string($row_added_by_id)) {
                    $user_ids[] = $row_added_by_id;
                }
            }

            // $conf['user_fields'] maps generic field names to actual DB columns;
            // fall back to the generic names, matching functions_mail.inc.php.
            $conf_user_fields = $conf['user_fields'] ?? null;
            $conf_user_fields = is_array($conf_user_fields) ? $conf_user_fields : [];
            $user_field_id = is_string($conf_user_fields['id'] ?? null) ? $conf_user_fields['id'] : 'id';
            $user_field_username = is_string($conf_user_fields['username'] ?? null) ? $conf_user_fields['username'] : 'username';

            $query = '
SELECT
    ' . $user_field_id . ' AS id,
    ' . $user_field_username . ' AS username
  FROM ' . USERS_TABLE . '
  WHERE ' . $user_field_id . ' IN (' . implode(',', $user_ids) . ')
;';
            $username_of = query2array($query, 'id', 'username');

            foreach (array_keys($added_by) as $added_by_idx) {
                $added_by_id = $added_by[$added_by_idx]['added_by_id'] ?? null;
                if (! is_string($added_by_id)) {
                    continue;
                }
                $added_by[$added_by_idx]['added_by_name'] = $username_of[$added_by_id] ?? 'user #' . $added_by_id . ' (deleted)';
            }
        }

        $template->assign('ADDED_BY', $added_by);

        $added_by_ids = [];
        if (is_array($search_fields['added_by'])) {
            foreach ($search_fields['added_by'] as $added_by_word) {
                if (is_int($added_by_word) || is_string($added_by_word)) {
                    $added_by_ids[] = $added_by_word;
                }
            }
        }

        // in case the search has forbidden added_by users for current user, we need to filter the search rule
        $search_fields['added_by'] = array_intersect($added_by_ids, $user_ids);
    } elseif (isset($search_fields['added_by'])) {
        unset($search_fields['added_by']);
    }

    if (isset($search_fields['cat']) and $display_filters['album']['access']) {
        $cat_words = [];
        if (is_array($search_fields['cat']) && is_array($search_fields['cat']['words'] ?? null)) {
            foreach ($search_fields['cat']['words'] as $cat_word) {
                if (is_int($cat_word) || is_string($cat_word)) {
                    $cat_words[] = $cat_word;
                }
            }
        }

        if (count($cat_words) > 0) {
            $fullname_of = [];

            $query = '
SELECT
    id,
    uppercats
  FROM ' . CATEGORIES_TABLE . '
    INNER JOIN ' . USER_CACHE_CATEGORIES_TABLE . ' ON id = cat_id AND user_id = ' . $user_id . '
  WHERE id IN (' . implode(',', $cat_words) . ')
;';
            $result = pwg_query($query);

            while ($row = pwg_db_fetch_assoc($result)) {
                if ($row['id'] === null || $row['uppercats'] === null) {
                    continue;
                }

                $cat_display_name = get_cat_display_name_cache(
                    $row['uppercats'],
                    'admin.php?page=album-' // TODO not sure it's relevant to link to admin pages
                );
                $row['fullname'] = strip_tags($cat_display_name);

                $fullname_of[$row['id']] = $row['fullname'];
            }

            $template->assign('fullname_of', json_encode($fullname_of));

            if (! is_array($search_fields['cat'])) {
                $search_fields['cat'] = [];
            }

            // in case the search has forbidden albums for current user, we need to filter the search rule
            $search_fields['cat']['words'] = array_intersect($cat_words, array_keys($fullname_of));
        }
    } elseif (isset($search_fields['cat'])) {
        unset($search_fields['cat']);
    }

    if (isset($search_fields['filetypes']) and $display_filters['file_type']['access']) {
        $filter_clause = get_clause_for_filter('filetypes');

        // get all file extensions for this user in the gallery, whatever the current filters
        $cache_key = $persistent_cache->make_key('file_exts' . $user_id . $user_cache_update_time);
        $all_exts_query = '
SELECT
    SUBSTRING_INDEX(path, ".", -1) AS ext,
    COUNT(DISTINCT(id)) AS counter
  FROM ' . IMAGES_TABLE . ' AS i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  WHERE 1=1' . $page['search_details']['forbidden'] . '
  GROUP BY ext
  ORDER BY counter DESC
;';
        $all_exts = null;
        if (! $persistent_cache->get($cache_key, $all_exts)) {
            $all_exts = query2array($all_exts_query, 'ext', 'counter');
            $persistent_cache->set($cache_key, $all_exts);
        }

        if (! is_array($all_exts)) {
            // the persistent cache should only ever hold what query2array() just
            // produced above; re-run the query if a corrupted entry slipped through.
            $all_exts = query2array($all_exts_query, 'ext', 'counter');
        }

        if (preg_match('/^image_id IN/', $filter_clause)) {
            $query = '
SELECT
    SUBSTRING_INDEX(path, ".", -1) AS ext,
    COUNT(DISTINCT(id)) AS counter
  FROM ' . IMAGES_TABLE . ' AS i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filter_clause . '
  GROUP BY ext
  ORDER BY counter DESC
;';
            $filtered_exts = query2array($query, 'ext', 'counter');

            $exts = [];
            foreach ($all_exts as $ext => $counter) {
                $exts[$ext] = $filtered_exts[$ext] ?? 0;
            }

            $template->assign('FILETYPES', $exts);
        } else {
            $template->assign('FILETYPES', $all_exts);
        }
    } elseif (isset($search_fields['filetypes'])) {
        unset($search_fields['filetypes']);
    }

    // For rating
    if ($conf['rate']) {
        $template->assign('SHOW_FILTER_RATINGS', true);

        if (isset($search_fields['ratings']) and $display_filters['rating']['access']) {
            $filter_clause = get_clause_for_filter('ratings');

            $cache_key = $persistent_cache->make_key('filter_ratings' . $user_id . $user_cache_update_time);

            $ratings = null;
            $set_persistent_cache = ! preg_match('/^image_id IN/', $filter_clause) and ! $persistent_cache->get($cache_key, $ratings);

            if (! isset($ratings)) {
                $query = '
SELECT
    DISTINCT id,
    rating_score
  FROM ' . IMAGES_TABLE . ' AS i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filter_clause;

                $filter_rows = query2array($query);

                $ratings = array_fill(0, 6, 0);

                foreach ($filter_rows as $row) {
                    $r = 5;

                    if (! isset($row['rating_score'])) {
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
        } elseif (isset($search_fields['ratings'])) {
            unset($search_fields['ratings']);
        }
    } else {
        $template->assign('SHOW_FILTER_RATINGS', false);
        if (isset($search_fields['ratings'])) {
            unset($search_fields['ratings']);
        }
    }

    // For filesize
    if (isset($search_fields['filesize_min']) && isset($search_fields['filesize_max']) and $display_filters['file_size']['access']) {
        $filter_clause = get_clause_for_filter('filesize');

        $filesizes = [];
        $filesize = [];

        $query = '
SELECT
    DISTINCT id,
    filesize
  FROM ' . IMAGES_TABLE . ' AS i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filter_clause . '
;';
        $result = pwg_query($query);
        while ($row = pwg_db_fetch_assoc($result)) {
            if (! is_numeric($row['filesize'])) {
                continue;
            }
            @$filesizes[sprintf('%.1f', (float) $row['filesize'] / 1024)]++;
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

        $filesize_min = $search_fields['filesize_min'];
        $filesize_max = $search_fields['filesize_max'];

        // warning: we will (hopefully) have smarter values for filters. The min/max of the
        // current search won't always be the first/last values found. It's going to be a
        // problem with this way to select selected values
        $filesize['selected'] = [
            'min' => (! empty($filesize_min) && is_numeric($filesize_min)) ? sprintf('%.1f', (float) $filesize_min / 1024) : $unique_filesizes[0],
            'max' => (! empty($filesize_max) && is_numeric($filesize_max)) ? sprintf('%.1f', (float) $filesize_max / 1024) : end($unique_filesizes),
        ];

        $template->assign('FILESIZE', $filesize);
    } elseif (isset($search_fields['filesize_min']) && isset($search_fields['filesize_max']) and ! ($display_filters['file_size']['access'])) {
        unset($search_fields['filesize_min']);
        unset($search_fields['filesize_max']);
    }

    if (isset($search_fields['ratios']) and $display_filters['ratio']['access']) {
        $filter_clause = get_clause_for_filter('ratios');

        $cache_key = $persistent_cache->make_key('filter_ratios' . $user_id . $user_cache_update_time);

        $ratios = null;
        $set_persistent_cache = ! preg_match('/^image_id IN/', $filter_clause) and ! $persistent_cache->get($cache_key, $ratios);

        if (! isset($ratios)) {
            $query = '
SELECT
    DISTINCT id,
    width,
    height
  FROM ' . IMAGES_TABLE . ' as i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filter_clause . '
    AND width IS NOT NULL
    AND height IS NOT NULL
;';

            $filter_rows = query2array($query);

            $ratios = [
                'Portrait' => 0,
                'square' => 0,
                'Landscape' => 0,
                'Panorama' => 0,
            ];

            foreach ($filter_rows as $row) {
                if (! is_numeric($row['width']) || ! is_numeric($row['height'])) {
                    continue;
                }

                $row_width = (float) $row['width'];
                $row_height = (float) $row['height'];

                if ($row_width <= 0 and $row_height <= 0) {
                    continue;
                }

                $r = $row_width / $row_height;
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
    } elseif (isset($search_fields['ratios'])) {
        unset($search_fields['ratios']);
    }

    if (isset($search_fields['height_min']) and isset($search_fields['height_max']) and $display_filters['height']['access']) {
        $filter_clause = get_clause_for_filter('height');

        $query = '
SELECT
    height
  FROM ' . IMAGES_TABLE . ' as i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filter_clause . '
    AND height IS NOT NULL
  GROUP BY height
  ORDER BY height ASC
;';

        if (! preg_match('/^image_id IN/', $filter_clause)) {
            // we use persistent_cache only for fetching lines filtered only by permissions
            $cache_key = $persistent_cache->make_key('filter_height_rows' . $user_id . $user_cache_update_time);
            $filter_rows = null;
            if (! $persistent_cache->get($cache_key, $filter_rows)) {
                $filter_rows = query2array($query, null, 'height');
                $persistent_cache->set($cache_key, $filter_rows);
            }
        } else {
            $filter_rows = query2array($query, null, 'height');
        }

        if (! is_array($filter_rows)) {
            // the persistent cache should only ever hold what query2array() just
            // produced above; re-run the query if a corrupted entry slipped through.
            $filter_rows = query2array($query, null, 'height');
        }

        // the persistent cache stores this row set as plain mixed data, so
        // validate each value defensively rather than trusting it.
        $heights = [];
        foreach ($filter_rows as $height_value) {
            if (is_string($height_value)) {
                $heights[] = $height_value;
            }
        }

        $height = [
            'list' => implode(',', $heights),
            'bounds' => [
                'min' => $heights[0],
                'max' => end($heights),
            ],
            'selected' => [
                'min' => ! empty($search_fields['height_min']) ? $search_fields['height_min'] : $heights[0],
                'max' => ! empty($search_fields['height_max']) ? $search_fields['height_max'] : end($heights),
            ],
        ];

        $template->assign('HEIGHT', $height);
    } elseif (isset($search_fields['height_min']) && isset($search_fields['height_max']) and ! ($display_filters['height']['access'])) {
        unset($search_fields['height_min']);
        unset($search_fields['height_max']);
    }

    if (isset($search_fields['width_min']) and isset($search_fields['width_max']) and $display_filters['width']['access']) {
        $filter_clause = get_clause_for_filter('width');

        $query = '
SELECT
    width
  FROM ' . IMAGES_TABLE . ' as i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  WHERE ' . $filter_clause . '
    AND width IS NOT NULL
  GROUP BY width
  ORDER BY width ASC
;';

        if (! preg_match('/^image_id IN/', $filter_clause)) {
            // we use persistent_cache only for fetching lines filtered only by permissions
            $cache_key = $persistent_cache->make_key('filter_width_rows' . $user_id . $user_cache_update_time);
            $filter_rows = null;
            if (! $persistent_cache->get($cache_key, $filter_rows)) {
                $filter_rows = query2array($query, null, 'width');
                $persistent_cache->set($cache_key, $filter_rows);
            }
        } else {
            $filter_rows = query2array($query, null, 'width');
        }

        if (! is_array($filter_rows)) {
            // the persistent cache should only ever hold what query2array() just
            // produced above; re-run the query if a corrupted entry slipped through.
            $filter_rows = query2array($query, null, 'width');
        }

        // the persistent cache stores this row set as plain mixed data, so
        // validate each value defensively rather than trusting it.
        $widths = [];
        foreach ($filter_rows as $width_value) {
            if (is_string($width_value)) {
                $widths[] = $width_value;
            }
        }

        $width = [
            'list' => implode(',', $widths),
            'bounds' => [
                'min' => $widths[0],
                'max' => end($widths),
            ],
            'selected' => [
                'min' => ! empty($search_fields['width_min']) ? $search_fields['width_min'] : $widths[0],
                'max' => ! empty($search_fields['width_max']) ? $search_fields['width_max'] : end($widths),
            ],
        ];

        $template->assign('WIDTH', $width);
    } elseif (isset($search_fields['width_min']) && isset($search_fields['width_max']) and ! ($display_filters['width']['access'])) {
        unset($search_fields['width_min']);
        unset($search_fields['width_max']);
    }

    $template->assign(
        [
            'GP' => json_encode($my_search),
            'SEARCH_ID' => $page['search'],
        ]
    );

    // $page['search_details'] is already known array here (guarded above).
    if ($page['start'] == 0 and ! isset($page['chronology_field'])) {
        $matching_cat_ids = $page['search_details']['matching_cat_ids'] ?? null;
        if (is_array($matching_cat_ids)) {
            // shape from get_search_info(): list<string|null>; keep only real ids.
            /** @var list<string> $cat_ids */
            $cat_ids = array_values(array_filter($matching_cat_ids, 'is_string'));
            if (count($cat_ids) > 0) {
                $query = '
SELECT
    c.*
  FROM ' . CATEGORIES_TABLE . ' AS c
    INNER JOIN ' . USER_CACHE_CATEGORIES_TABLE . ' ON c.id = cat_id and user_id = ' . $user_id . '
  WHERE id IN (' . implode(',', $cat_ids) . ')
;';
                $cats = query2array($query);
                usort($cats, name_compare(...));
                $albums_found = [];
                foreach ($cats as $cat) {
                    if ($cat['uppercats'] === null) {
                        continue;
                    }

                    $single_link = false;
                    $albums_found[] = get_cat_display_name_cache(
                        $cat['uppercats'],
                        '',
                        $single_link
                    );
                }

                if (count($albums_found) > 0) {
                    $template->assign('ALBUMS_FOUND', $albums_found);
                }
            }
        }
        $matching_tag_ids = $page['search_details']['matching_tag_ids'] ?? null;
        if (is_array($matching_tag_ids)) {
            // shape from get_search_info(): list<string|null>; keep only real ids.
            /** @var list<string> $tag_ids */
            $tag_ids = array_values(array_filter($matching_tag_ids, 'is_string'));

            if (count($tag_ids) > 0) {
                $tags = get_available_tags($tag_ids);
                usort($tags, tag_alpha_compare(...));
                $tags_found = [];
                foreach ($tags as $tag) {
                    if (! isset($tag['name']) || ! is_string($tag['name'])) {
                        continue;
                    }

                    $url = make_index_url(
                        [
                            'tags' => [$tag],
                        ]
                    );
                    $tags_found[] = sprintf('<a href="%s">%s</a>', $url, $tag['name']);
                }

                if (count($tags_found) > 0) {
                    $template->assign('TAGS_FOUND', $tags_found);
                }
            }
        }
    }
}
