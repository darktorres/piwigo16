<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

function get_search_id_pattern(int|string $candidate): ?string
{
    $clause_pattern = null;
    if (preg_match('/^psk-\d{8}-[a-z0-9]{10}$/i', (string) $candidate)) {
        $clause_pattern = 'search_uuid = \'%s\'';
    } elseif (preg_match('/^\d+$/', (string) $candidate)) {
        $clause_pattern = 'id = %u';
    }

    return $clause_pattern;
}

/**
 * @return array<string, mixed>|null
 */
function get_search_info(int|string $candidate): ?array
{
    global $page;

    // $candidate might be a search.id or a search_uuid
    $clause_pattern = get_search_id_pattern($candidate);

    if (empty($clause_pattern)) {
        die('Invalid search identifier');
    }

    $query = '
SELECT *
  FROM ' . SEARCH_TABLE . '
  WHERE ' . sprintf($clause_pattern, $candidate) . '
;';
    $searches = query2array($query);

    if (count($searches) > 0) {
        // we don't want spies to be able to see the search rules of any prior search (performed
        // by any user). We don't want them to be try index.php?/search/123 then index.php?/search/124
        // and so on. That's why we have implemented search_uuid with random characters.
        //
        // We also don't want to break old search urls with only the numeric id, so we only break if
        // there is no uuid.
        //
        // We also don't want to die if we're in the API.
        if (script_basename() != 'ws' and $clause_pattern == 'id = %u' and isset($searches[0]['search_uuid'])) {
            fatal_error('this search is not reachable with its id, need the search_uuid instead');
        }

        if (isset($page['section']) and $page['section'] == 'search') {
            // to be used later in pwg_log
            $page['search_id'] = $searches[0]['id'];
        }

        return $searches[0];
    }

    return null;
}

/**
 * Returns search rules stored into a serialized array in "search"
 * table. Each search rules set is numericaly identified.
 *
 * @return array<string, mixed>|false
 */
function get_search_array(int|string $search_id): mixed
{
    global $user;

    $search = get_search_info($search_id);

    if (empty($search)) {
        bad_request('this search identifier does not exist');
    }

    $rules = $search['rules'] ?? null;
    if (! is_string($rules)) {
        return false;
    }

    $result = unserialize($rules);
    if (! is_array($result)) {
        return false;
    }

    // search rules are always serialized from an associative array with
    // string keys ('fields', 'q', ...) — see search.php and
    // ws_history_search() in include/ws_functions/pwg.php, the only two
    // places that build the array later passed to save_search()/serialize().
    /** @var array<string, mixed> $result */
    return $result;
}

/**
 * Returns the list of items corresponding to the advanced search array.
 *
 * @param array<string, mixed> $search
 * @param string $images_where optional additional restriction on images table
 * @return array{items: array<int|string, mixed>, search_details: array{matching_cat_ids: list<string|null>|null, matching_tag_ids: list<string|null>|null, has_filters_filled: bool, image_ids_for_filter: array{}|array{expert?: array<mixed>, allwords?: list<string|null>, author?: list<string|null>, filetypes?: list<string|null>, added_by?: list<string|null>, cat?: list<string|null>, date_posted?: list<string|null>, date_created?: list<string|null>, ratios?: list<string|null>, ratings?: list<string|null>, filesize?: list<string|null>, height?: list<string|null>, width?: list<string|null>, tags?: array<int|string, mixed>, custom?: list<string|null>}}}
 */
function get_regular_search_results(array $search, string $images_where = ''): array
{
    global $conf, $logger;

    $logger->debug(__FUNCTION__, 'search', $search);

    $has_filters_filled = false;

    $forbidden = get_sql_condition_FandF(
        [
            'forbidden_categories' => 'category_id',
            'visible_categories' => 'category_id',
            'visible_images' => 'id',
        ],
        "\n  AND"
    );

    $image_ids_for_filter = [];

    $raw_filters_views = conf_get_param('filters_views', $conf['default_filters_views']);
    $unserialized_display_filters = (is_array($raw_filters_views) or is_string($raw_filters_views))
        ? safe_unserialize($raw_filters_views)
        : [];

    // $display_filters maps each filter name (eg. 'expert', 'words', 'tags', ...) to its
    // config array; every entry we actually read below is an array (we only ever access
    // the 'access' key, set to a bool right after this loop), so non-array entries coming
    // from a malformed $conf['filters_views'] are simply dropped here.
    $display_filters = [];
    if (is_array($unserialized_display_filters)) {
        foreach ($unserialized_display_filters as $filt_name => $filt_conf) {
            if (is_string($filt_name) and is_array($filt_conf)) {
                $display_filters[$filt_name] = $filt_conf;
            }
        }
    }

    foreach ($display_filters as $filt_name => $filt_conf) {
        if (isset($filt_conf['access'])) {
            if ($filt_conf['access'] == 'everybody' or ($filt_conf['access'] == 'admins-only' and is_admin()) or ($filt_conf['access'] == 'registered-users' and is_classic_user())) {
                $filt_conf['access'] = true;
            } else {
                $filt_conf['access'] = false;
            }
            $display_filters[$filt_name] = $filt_conf;
        }
    }

    // $search['fields'] holds one entry per active search criterion (eg. 'allwords',
    // 'tags', 'cat', ...); narrowed once here so every access below is a single-level
    // (and therefore typed) offset access instead of chaining through mixed.
    $raw_search_fields = $search['fields'] ?? null;
    $search_fields = is_array($raw_search_fields) ? $raw_search_fields : [];

    //
    // expert
    //
    $expert_field = $search_fields['expert'] ?? null;
    $expert_string = (is_array($expert_field) && is_string($expert_field['string'] ?? null)) ? $expert_field['string'] : null;
    if (isset($search_fields['expert']) and ! empty($expert_string) and ($display_filters['expert']['access'] ?? false)) {
        $has_filters_filled = true;

        $expert_items = get_quick_search_results($expert_string, [])['items'];
        $image_ids_for_filter['expert'] = is_array($expert_items) ? $expert_items : [];
    }

    //
    // allwords
    //
    $allwords_field = $search_fields['allwords'] ?? null;
    $allwords_words = is_array($allwords_field) && is_array($allwords_field['words'] ?? null)
        ? array_values(array_filter($allwords_field['words'], 'is_string'))
        : [];
    $allwords_search_fields = is_array($allwords_field) && is_array($allwords_field['fields'] ?? null)
        ? array_values(array_filter($allwords_field['fields'], 'is_string'))
        : [];
    if (isset($search_fields['allwords']) and count($allwords_words) > 0 and count($allwords_search_fields) > 0 and ($display_filters['words']['access'] ?? false)) {
        $has_filters_filled = true;

        // 1) we search in regular fields (ie, the ones in the piwigo_images table)
        $fields = ['file', 'name', 'comment', 'author'];

        // the outer if already established count(...) > 0 for this same key
        $fields = array_intersect($fields, $allwords_search_fields);

        $cat_fields_dictionnary = [
            'cat-title' => 'name',
            'cat-desc' => 'comment',
        ];
        $cat_fields = array_intersect(array_keys($cat_fields_dictionnary), $allwords_search_fields);

        // in the OR mode, request must be :
        // ((field1 LIKE '%word1%' OR field2 LIKE '%word1%')
        // OR (field1 LIKE '%word2%' OR field2 LIKE '%word2%'))
        //
        // in the AND mode :
        // ((field1 LIKE '%word1%' OR field2 LIKE '%word1%')
        // AND (field1 LIKE '%word2%' OR field2 LIKE '%word2%'))
        $word_clauses = [];
        $cat_ids_by_word = $tag_ids_by_word = [];
        foreach ($allwords_words as $word) {
            $field_clauses = [];
            foreach ($fields as $field) {
                $field_clauses[] = $field . " LIKE '%" . $word . "%'";
            }

            if (count($cat_fields) > 0) {
                $cat_word_clauses = [];
                $cat_field_clauses = [];
                foreach ($cat_fields as $cat_field) {
                    $cat_field_clauses[] = $cat_fields_dictionnary[$cat_field] . " LIKE '%" . $word . "%'";
                }

                // adds brackets around where clauses
                $cat_word_clauses[] = implode(' OR ', $cat_field_clauses);

                $query = '
SELECT
    id
  FROM ' . CATEGORIES_TABLE . '
  WHERE ' . implode(' OR ', $cat_word_clauses) . '
;';
                $cat_ids = query2array($query, null, 'id');
                $cat_ids_by_word[$word] = $cat_ids;
                if (count($cat_ids) > 0) {
                    $query = '
SELECT
    image_id
  FROM ' . IMAGE_CATEGORY_TABLE . '
  WHERE category_id IN (' . implode(',', $cat_ids) . ')
;';
                    $cat_image_ids = query2array($query, null, 'image_id');

                    if (count($cat_image_ids) > 0) {
                        $field_clauses[] = 'id IN (' . implode(',', $cat_image_ids) . ')';
                    }
                }
            }

            // search_in_tags
            if (in_array('tags', $allwords_search_fields)) {
                $query = '
SELECT
    id
  FROM ' . TAGS_TABLE . '
  WHERE name LIKE \'%' . $word . '%\'
;';
                $tag_ids = query2array($query, null, 'id');
                $tag_ids_by_word[$word] = $tag_ids;
                if (count($tag_ids) > 0) {
                    $query = '
SELECT
    image_id
  FROM ' . IMAGE_TAG_TABLE . '
  WHERE tag_id IN (' . implode(',', $tag_ids) . ')
;';
                    $tag_image_ids = query2array($query, null, 'image_id');

                    if (count($tag_image_ids) > 0) {
                        $field_clauses[] = 'id IN (' . implode(',', $tag_image_ids) . ')';
                    }
                }
            }

            if (count($field_clauses) > 0) {
                // adds brackets around where clauses
                $word_clauses[] = implode(
                    "\n          OR ",
                    $field_clauses
                );
            }
        }

        if (count($word_clauses) > 0) {
            array_walk(
                $word_clauses,
                function (string &$s): void { $s = '(' . $s . ')'; }
            );
        }

        // make sure the "mode" is either OR or AND
        // $allwords_field is already known to be an array here: reaching this
        // point required count($allwords_search_fields) > 0 above, which is
        // only non-empty when the is_array($allwords_field) check at its
        // definition (a few lines up) already passed.
        $allwords_mode = (is_string($allwords_field['mode'] ?? null) && in_array($allwords_field['mode'], ['OR', 'AND'], true))
            ? $allwords_field['mode']
            : 'AND';

        $filter_clause = "\n         " . implode(
            "\n         " . $allwords_mode . "\n         ",
            $word_clauses
        );

        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE ' . $filter_clause . '
  ' . $forbidden . '
;';
        $image_ids_for_filter['allwords'] = query2array($query, null, 'id');

        if (count($cat_ids_by_word) > 0) {
            $matching_cat_ids = null;
            foreach ($cat_ids_by_word as $idx => $cat_ids) {
                if ($matching_cat_ids === null) {
                    // first iteration
                    $matching_cat_ids = $cat_ids;
                } else {
                    $matching_cat_ids = array_merge($matching_cat_ids, $cat_ids);
                }
            }

            $matching_cat_ids = array_unique($matching_cat_ids);
        }

        if (count($tag_ids_by_word) > 0) {
            $matching_tag_ids = null;
            foreach ($tag_ids_by_word as $idx => $tag_ids) {
                if ($matching_tag_ids === null) {
                    // first iteration
                    $matching_tag_ids = $tag_ids;
                } else {
                    $matching_tag_ids = array_merge($matching_tag_ids, $tag_ids);
                }
            }

            $matching_tag_ids = array_unique($matching_tag_ids);
        }
    }

    //
    // author
    //
    $author_field = $search_fields['author'] ?? null;
    $author_words = is_array($author_field) && is_array($author_field['words'] ?? null)
        ? array_values(array_filter($author_field['words'], 'is_string'))
        : [];
    if (isset($search_fields['author']) and count($author_words) > 0 and ($display_filters['author']['access'] ?? false)) {
        $has_filters_filled = true;

        $author_clauses = [];
        foreach ($author_words as $word) {
            $author_clauses[] = "author = '" . $word . "'";
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE (' . implode(' OR ', $author_clauses) . ')
  ' . $forbidden . '
;';
        $image_ids_for_filter['author'] = query2array($query, null, 'id');
    }

    //
    // filetypes
    //
    $filetypes_field = $search_fields['filetypes'] ?? null;
    $filetypes = is_array($filetypes_field) ? array_values(array_filter($filetypes_field, 'is_string')) : [];
    if (count($filetypes) > 0 and ($display_filters['file_type']['access'] ?? false)) {
        $has_filters_filled = true;

        $filetypes_clauses = [];
        foreach ($filetypes as $ext) {
            $filetypes_clauses[] = 'path LIKE \'%.' . $ext . '\'';
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE (' . implode(' OR ', $filetypes_clauses) . ')
  ' . $forbidden . '
;';
        $image_ids_for_filter['filetypes'] = query2array($query, null, 'id');
    }

    //
    // added_by
    //
    $added_by_field = $search_fields['added_by'] ?? null;
    $added_by_ids = is_array($added_by_field) ? array_values(array_filter($added_by_field, 'is_string')) : [];
    if (count($added_by_ids) > 0 and ($display_filters['added_by']['access'] ?? false)) {
        $has_filters_filled = true;

        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE added_by IN (' . implode(',', $added_by_ids) . ')
  ' . $forbidden . '
;';
        $image_ids_for_filter['added_by'] = query2array($query, null, 'id');
    }

    //
    // cat
    //
    $cat_field = $search_fields['cat'] ?? null;
    $cat_words = [];
    if (is_array($cat_field) and is_array($cat_field['words'] ?? null)) {
        foreach ($cat_field['words'] as $cat_word) {
            if (is_string($cat_word)) {
                $cat_words[] = $cat_word;
            } elseif (is_int($cat_word)) {
                $cat_words[] = (string) $cat_word;
            }
        }
    }
    if (isset($search_fields['cat']) and count($cat_words) > 0 and ($display_filters['album']['access'] ?? false)) {
        $has_filters_filled = true;

        if (is_array($cat_field) and ! empty($cat_field['sub_inc'])) {
            // searching all the categories id of sub-categories
            $cat_ids = get_subcat_ids($cat_words);
        } else {
            // TODO we take the list of cat_ids "as is", we should check they still
            // exist and are browseable to the user
            $cat_ids = $cat_words;
        }

        // in case the album would no longer exists, we consider the filter on album no longer active
        if (! empty($cat_ids)) {
            $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE category_id IN (' . implode(',', $cat_ids) . ')
  ' . $forbidden . '
;';
            $image_ids_for_filter['cat'] = query2array($query, null, 'id');
        }
    }

    //
    // date_posted
    //
    $date_posted_field = $search_fields['date_posted'] ?? null;
    $date_posted_preset = is_array($date_posted_field) && is_string($date_posted_field['preset'] ?? null)
        ? $date_posted_field['preset']
        : null;
    $date_posted_custom = [];
    if (is_array($date_posted_field) and is_array($date_posted_field['custom'] ?? null)) {
        foreach ($date_posted_field['custom'] as $date_posted_custom_value) {
            if (is_string($date_posted_custom_value)) {
                $date_posted_custom[] = $date_posted_custom_value;
            } elseif (is_int($date_posted_custom_value)) {
                $date_posted_custom[] = (string) $date_posted_custom_value;
            }
        }
    }
    if (! empty($date_posted_preset) and ($display_filters['post_date']['access'] ?? false)) {

        $has_filters_filled = true;

        $options = [
            '24h' => '24 HOUR',
            '7d' => '7 DAY',
            '30d' => '30 DAY',
            '3m' => '3 MONTH',
            '6m' => '6 MONTH',
        ];

        if (isset($options[$date_posted_preset])) {
            $date_posted_clause = 'date_available > SUBDATE(NOW(), INTERVAL ' . $options[$date_posted_preset] . ')';
        } elseif ($date_posted_preset == 'custom' and count($date_posted_custom) > 0) {
            $date_posted_subclauses = [];
            $custom_dates = array_flip($date_posted_custom);

            foreach (array_keys($custom_dates) as $custom_date) {
                // in real-life tests, we have determined "where year(date_available) = 2024" was
                // far less (4 times less) than "where date_available between '2024-01-01 00:00:00' and '2024-12-31 23:59:59'"
                // so let's find the begin/end for each custom date
                // ... and also, no need to search for images of 2023-10-16 if 2023-10 is already requested
                $begin = $end = null;

                $ymd = substr((string) $custom_date, 0, 1);
                if ($ymd == 'y') {
                    $year = substr((string) $custom_date, 1);
                    $begin = $year . '-01-01 00:00:00';
                    $end = $year . '-12-31 23:59:59';
                } elseif ($ymd == 'm') {
                    [$year, $month] = explode('-', substr((string) $custom_date, 1));

                    if (! isset($custom_dates['y' . $year])) {
                        $begin = $year . '-' . $month . '-01 00:00:00';
                        $end = $year . '-' . $month . '-' . cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year) . ' 23:59:59';
                    }
                } elseif ($ymd == 'd') {
                    [$year, $month, $day] = explode('-', substr((string) $custom_date, 1));

                    if (! isset($custom_dates['y' . $year]) and ! isset($custom_dates['m' . $year . '-' . $month])) {
                        $begin = $year . '-' . $month . '-' . $day . ' 00:00:00';
                        $end = $year . '-' . $month . '-' . $day . ' 23:59:59';
                    }
                }

                if (! empty($begin)) {
                    $date_posted_subclauses[] = 'date_available BETWEEN "' . $begin . '" AND "' . $end . '"';
                }
            }

            $date_posted_clause = '(' . implode(' OR ', prepend_append_array_items($date_posted_subclauses, '(', ')')) . ')';
        } else {
            // Unknown/stale preset value (e.g. a saved search referencing a
            // preset that no longer exists): don't filter on this criterion.
            $date_posted_clause = '1=1';
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE ' . $date_posted_clause . '
  ' . $forbidden . '
;';

        $image_ids_for_filter['date_posted'] = query2array($query, null, 'id');
    }

    //
    // date_created
    //
    $date_created_field = $search_fields['date_created'] ?? null;
    $date_created_preset = is_array($date_created_field) && is_string($date_created_field['preset'] ?? null)
        ? $date_created_field['preset']
        : null;
    $date_created_custom = [];
    if (is_array($date_created_field) and is_array($date_created_field['custom'] ?? null)) {
        foreach ($date_created_field['custom'] as $date_created_custom_value) {
            if (is_string($date_created_custom_value)) {
                $date_created_custom[] = $date_created_custom_value;
            } elseif (is_int($date_created_custom_value)) {
                $date_created_custom[] = (string) $date_created_custom_value;
            }
        }
    }
    if (! empty($date_created_preset) and ($display_filters['creation_date']['access'] ?? false)) {

        $has_filters_filled = true;

        $options = [
            '7d' => '7 DAY',
            '30d' => '30 DAY',
            '3m' => '3 MONTH',
            '6m' => '6 MONTH',
            '12m' => '12 MONTH',
        ];

        if (isset($options[$date_created_preset])) {
            $date_created_clause = 'date_creation > SUBDATE(NOW(), INTERVAL ' . $options[$date_created_preset] . ')';
        } elseif ($date_created_preset == 'custom' and count($date_created_custom) > 0) {
            $date_created_subclauses = [];
            $custom_dates = array_flip($date_created_custom);

            foreach (array_keys($custom_dates) as $custom_date) {
                // in real-life tests, we have determined "where year(date_creation) = 2024" was
                // far less (4 times less) than "where date_creation between '2024-01-01 00:00:00' and '2024-12-31 23:59:59'"
                // so let's find the begin/end for each custom date
                // ... and also, no need to search for images of 2023-10-16 if 2023-10 is already requested
                $begin = $end = null;

                $ymd = substr((string) $custom_date, 0, 1);
                if ($ymd == 'y') {
                    $year = substr((string) $custom_date, 1);
                    $begin = $year . '-01-01 00:00:00';
                    $end = $year . '-12-31 23:59:59';
                } elseif ($ymd == 'm') {
                    [$year, $month] = explode('-', substr((string) $custom_date, 1));

                    if (! isset($custom_dates['y' . $year])) {
                        $begin = $year . '-' . $month . '-01 00:00:00';
                        $end = $year . '-' . $month . '-' . cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year) . ' 23:59:59';
                    }
                } elseif ($ymd == 'd') {
                    [$year, $month, $day] = explode('-', substr((string) $custom_date, 1));

                    if (! isset($custom_dates['y' . $year]) and ! isset($custom_dates['m' . $year . '-' . $month])) {
                        $begin = $year . '-' . $month . '-' . $day . ' 00:00:00';
                        $end = $year . '-' . $month . '-' . $day . ' 23:59:59';
                    }
                }

                if (! empty($begin)) {
                    $date_created_subclauses[] = 'date_creation BETWEEN "' . $begin . '" AND "' . $end . '"';
                }
            }

            $date_created_clause = '(' . implode(' OR ', prepend_append_array_items($date_created_subclauses, '(', ')')) . ')';
        } else {
            // Unknown/stale preset value (e.g. a saved search referencing a
            // preset that no longer exists): don't filter on this criterion.
            $date_created_clause = '1=1';
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE ' . $date_created_clause . '
  ' . $forbidden . '
;';

        $image_ids_for_filter['date_created'] = query2array($query, null, 'id');
    }

    //
    // ratios
    //
    $ratios_field = $search_fields['ratios'] ?? null;
    $ratios = is_array($ratios_field) ? array_values(array_filter($ratios_field, 'is_string')) : [];
    if (count($ratios) > 0 and ($display_filters['ratio']['access'] ?? false)) {
        $has_filters_filled = true;

        $clause_for_ratio = [
            'Portrait' => 'width/height < 0.95',
            'square' => 'width/height BETWEEN 0.95 AND 1.05',
            'Landscape' => '(width/height > 1.05 AND width/height < 2)',
            'Panorama' => 'width/height >= 2',
        ];

        $ratios_clauses = [];
        foreach ($ratios as $r) {
            if (isset($clause_for_ratio[$r])) {
                $ratios_clauses[] = $clause_for_ratio[$r];
            }
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE (' . implode(' OR ', $ratios_clauses) . ')
  ' . $forbidden . '
;';
        $image_ids_for_filter['ratios'] = query2array($query, null, 'id');
    }

    //
    // ratings
    //
    $ratings_field = $search_fields['ratings'] ?? null;
    $ratings = is_array($ratings_field) ? array_values(array_filter($ratings_field, 'is_string')) : [];
    if ($conf['rate'] and count($ratings) > 0 and ($display_filters['rating']['access'] ?? false)) {
        $has_filters_filled = true;

        $filter_clauses = [];
        foreach ($ratings as $r) {
            if ($r == 0) {
                $filter_clauses[] = 'rating_score IS NULL';
            } else {
                $filter_clauses[] = '(rating_score >= ' . (intval($r) - 1) . ' AND rating_score < ' . $r . ')';
            }
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE (' . implode(' OR ', $filter_clauses) . ')
  ' . $forbidden . '
;';
        $image_ids_for_filter['ratings'] = query2array($query, null, 'id');
    }

    //
    // filesize
    //
    $filesize_min_raw = $search_fields['filesize_min'] ?? null;
    $filesize_max_raw = $search_fields['filesize_max'] ?? null;
    if (! empty($filesize_min_raw) and ! empty($filesize_max_raw) and is_numeric($filesize_min_raw) and is_numeric($filesize_max_raw) and ($display_filters['file_size']['access'] ?? false)) {
        $has_filters_filled = true;

        // because of conversion from kB to mB, approximation, then conversion back to kB,
        // we need to slightly enlarge the range for search
        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE filesize BETWEEN ' . ((float) $filesize_min_raw - 100) . ' AND ' . ((float) $filesize_max_raw + 100) . '
  ' . $forbidden . '
;';
        $image_ids_for_filter['filesize'] = query2array($query, null, 'id');
    }

    //
    // height
    //
    $height_min_raw = $search_fields['height_min'] ?? null;
    $height_max_raw = $search_fields['height_max'] ?? null;
    if (! empty($height_min_raw) and ! empty($height_max_raw) and is_scalar($height_min_raw) and is_scalar($height_max_raw) and ($display_filters['height']['access'] ?? false)) {
        $has_filters_filled = true;

        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE height BETWEEN ' . (string) $height_min_raw . ' AND ' . (string) $height_max_raw . '
  ' . $forbidden . '
;';
        $image_ids_for_filter['height'] = query2array($query, null, 'id');
    }

    //
    // width
    //
    $width_min_raw = $search_fields['width_min'] ?? null;
    $width_max_raw = $search_fields['width_max'] ?? null;
    if (! empty($width_min_raw) and ! empty($width_max_raw) and is_scalar($width_min_raw) and is_scalar($width_max_raw) and ($display_filters['width']['access'] ?? false)) {
        $has_filters_filled = true;

        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE width BETWEEN ' . (string) $width_min_raw . ' AND ' . (string) $width_max_raw . '
  ' . $forbidden . '
;';
        $image_ids_for_filter['width'] = query2array($query, null, 'id');
    }

    //
    // tags
    //
    $tags_field = $search_fields['tags'] ?? null;
    $tags_words = [];
    if (is_array($tags_field) and is_array($tags_field['words'] ?? null)) {
        foreach ($tags_field['words'] as $tag_word) {
            if (is_numeric($tag_word)) {
                $tags_words[] = (int) $tag_word;
            }
        }
    }
    $tags_mode = is_array($tags_field) && is_string($tags_field['mode'] ?? null) ? $tags_field['mode'] : 'AND';
    if (isset($search_fields['tags']) and count($tags_words) > 0 and ($display_filters['tags']['access'] ?? false)) {
        $has_filters_filled = true;

        $image_ids_for_filter['tags'] = get_image_ids_for_tags(
            $tags_words,
            $tags_mode
        );
    }

    //
    // custom search
    //
    if (! empty($images_where)) {
        $query = '
SELECT
    DISTINCT(id)
  FROM ' . IMAGES_TABLE . ' AS i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
  WHERE ' . $images_where . '
  ' . $forbidden . '
;';
        $image_ids_for_filter['custom'] = query2array($query, null, 'id');
    }

    $items = [];
    if (! empty($image_ids_for_filter)) {
        // every entry of $image_ids_for_filter is either a query2array() id list
        // (list<string|null> — the 'id' column is a non-null primary key, so the
        // null case never actually happens in practice) or, for 'expert', the
        // already-narrowed result of get_quick_search_results(); normalize every
        // entry to a plain string-id list here so the intersection below has an
        // unambiguous element type.
        $normalized_filter_ids = [];
        foreach ($image_ids_for_filter as $filter_name => $filter_ids) {
            $string_ids = [];
            foreach ($filter_ids as $filter_id) {
                if (is_scalar($filter_id)) {
                    $string_ids[] = (string) $filter_id;
                }
            }
            $normalized_filter_ids[$filter_name] = $string_ids;
        }

        if (count($normalized_filter_ids) > 1) {
            $items = array_values(array_unique(array_intersect(...array_values($normalized_filter_ids))));
        } else {
            // exactly one filter is filled here — grab its (only) value
            // without a dynamic re-lookup by key, which PHPStan can't
            // verify against this array's many conditionally-set keys.
            $first_filter_ids = reset($normalized_filter_ids);
            $items = $first_filter_ids !== false ? $first_filter_ids : [];
        }
    }

    $logger->debug(__FUNCTION__ . ' ' . count($items) . ' items in $unsorted_items');

    if (count($items) > 1) {
        $query = '
SELECT
    id
  FROM ' . IMAGES_TABLE . ' i
  WHERE id IN (' . implode(',', $items) . ')
  ' . $conf['order_by'];

        $items = array_from_query($query, 'id');
    }

    return [
        'items' => $items,
        'search_details' => [
            'matching_cat_ids' => isset($matching_cat_ids) ? array_values($matching_cat_ids) : null,
            'matching_tag_ids' => isset($matching_tag_ids) ? array_values($matching_tag_ids) : null,
            'has_filters_filled' => $has_filters_filled,
            'image_ids_for_filter' => $image_ids_for_filter,
        ],
    ];
}

/**
 * Returns the SQL WHERE clause to be used to build filter values
 *
 * @since 15
 */
function get_clause_for_filter(string $filter_name): string
{
    global $page;

    $other_filters_items = get_items_for_filter($filter_name);
    if ($other_filters_items === false) {
        return '1=1' . $page['search_details']['forbidden'];
    }

    // get_items_for_filter() ultimately pulls its values from
    // $page['search_details']['image_ids_for_filter'], which is declared
    // array<string, mixed> (get_regular_search_results()'s return shape) — in
    // practice always image ids, but narrow to scalars here for implode().
    $other_filters_item_strings = array_map(
        static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0',
        $other_filters_items
    );

    return 'image_id IN (' . implode(',', $other_filters_item_strings) . ')';
}

/**
 * Returns the list of items (image_ids) to be used to build filter values
 * for a given filter. Depends on the other filters. Use a cache to avoid
 * computing the same large array_intersect several times.
 *
 * @since 15
 *
 * @return array<int, mixed>|false array of image_ids, or false
 */
function get_items_for_filter(string $filter_name)
{
    global $page, $logger;

    $other_filters = array_diff(array_keys($page['search_details']['image_ids_for_filter']), [$filter_name]);

    if (empty($other_filters)) {
        return false;
    }

    $cache_key = md5(implode(',', $other_filters));

    if (! isset($page['search_details'][__FUNCTION__][$cache_key])) {
        $function_start = get_moment();

        $other_filters_items = $page['search_details']['image_ids_for_filter'][array_shift($other_filters)];
        foreach ($other_filters as $other_filter) {
            $other_filters_items = array_intersect($other_filters_items, $page['search_details']['image_ids_for_filter'][$other_filter]);
        }

        $other_filters_items = array_unique($other_filters_items);

        $debug_msg = '[' . __FUNCTION__ . '] cache computed for ' . (count($other_filters) + 1) . ' other filters';
        $debug_msg .= ' (' . count($other_filters_items) . ' items)';
        $debug_msg .= ', time = ' . get_elapsed_time($function_start, get_moment());
        $logger->debug($debug_msg);

        if (empty($other_filters_items)) {
            $other_filters_items = [-1];
        }

        @$page['search_details'][__FUNCTION__][$cache_key] = $other_filters_items;
    }

    return $page['search_details'][__FUNCTION__][$cache_key];
}

define('QST_QUOTED', 0x01);
define('QST_NOT', 0x02);
define('QST_OR', 0x04);
define('QST_WILDCARD_BEGIN', 0x08);
define('QST_WILDCARD_END', 0x10);
define('QST_WILDCARD', QST_WILDCARD_BEGIN | QST_WILDCARD_END);
define('QST_BREAK', 0x20);

/**
 * A search scope applies to a single token and restricts the search to a subset of searchable fields.
 */
class QSearchScope
{
    /**
     * @param string[] $aliases
     */
    public function __construct(
        public string $id,
        public array $aliases,
        public bool $nullable = false,
        public bool $is_text = true
    ) {}

    public function parse(QSingleToken $token): bool
    {
        if (! $this->nullable && strlen((string) $token->term) == 0) {
            return false;
        }
        return true;
    }

    public function process_char(string &$ch, string &$crt_token): bool
    {
        return false;
    }

    /**
     * Only QNumericRangeScope and QDateRangeScope support get_sql() —
     * qsearch_get_images() only calls it for scope ids backed by one of
     * those two subclasses (width/height/ratio/size/hits/score/filesize/id/
     * created/posted), never for a plain QSearchScope (tag/photo/file/
     * author, which have their own dedicated SQL building).
     */
    public function get_sql(string $field, QSingleToken $token): string
    {
        throw new \LogicException(static::class . ' does not support get_sql()');
    }
}

class QNumericRangeScope extends QSearchScope
{
    /**
     * @param string[] $aliases
     */
    public function __construct(
        string $id,
        array $aliases,
        bool $nullable = false,
        private readonly int|float $epsilon = 0
    ) {
        parent::__construct($id, $aliases, $nullable, false);
    }

    #[\Override]
    public function parse(QSingleToken $token): bool
    {
        $str = $token->term;
        $strict = [0, 0];
        $range_requested = true;
        if (($pos = strpos((string) $str, '..')) !== false) {
            $range = [substr((string) $str, 0, $pos), substr((string) $str, $pos + 2)];
        } elseif (@$str[0] == '>') {// ratio:>1
            $range = [substr((string) $str, 1), ''];
            $strict[0] = 1;
        } elseif (@$str[0] == '<') { // size:<5mp
            $range = ['', substr((string) $str, 1)];
            $strict[1] = 1;
        } elseif (($token->modifier & QST_WILDCARD_BEGIN)) {
            $range = ['', $str];
        } elseif (($token->modifier & QST_WILDCARD_END)) {
            $range = [$str, ''];
        } else {
            $range = [$str, $str];
            $range_requested = false;
        }

        foreach ($range as $i => &$val) {
            if (preg_match('#^(-?[0-9.]+)/([0-9.]+)$#i', (string) $val, $matches)) {
                $val = floatval((float) $matches[1] / (float) $matches[2]);
            } elseif (preg_match('/^(-?[0-9.]+)([km])?/i', (string) $val, $matches)) {
                $val = floatval($matches[1]);
                if (isset($matches[2])) {
                    $mult = 1;
                    if ($matches[2] == 'k' || $matches[2] == 'K') {
                        $mult = 1000;
                    } else {
                        $mult = 1000000;
                    }
                    $val *= $mult;
                    if ($i && ! $range_requested) {// round up the upper limit if possible - e.g 6k goes up to 6999, but 6.12k goes only up to 6129
                        if (($dot_pos = strpos($matches[1], '.')) !== false) {
                            $requested_precision = strlen($matches[1]) - $dot_pos - 1;
                            $mult /= 10 ** $requested_precision;
                        }
                        if ($mult > 1) {
                            $val += $mult - 1;
                        }
                    }
                }
            } else {
                $val = '';
            }
            if (is_numeric($val)) {
                if ($i ^ $strict[$i]) {
                    $val += $this->epsilon;
                } else {
                    $val -= $this->epsilon;
                }
            }
        }

        if (! $this->nullable && $range[0] === '' && $range[1] === '') {
            return false;
        }
        $token->scope_data = [
            'range' => $range,
            'strict' => $strict,
        ];
        return true;
    }

    #[\Override]
    public function get_sql(string $field, QSingleToken $token): string
    {
        // set by parse() above as ['range' => array{0: int|float|string, 1:
        // int|float|string}, 'strict' => array{0: int, 1: int}] — see
        // QSingleToken::$scope_data's own docblock for why this can't be a
        // static property type.
        $scope_data = $token->scope_data;
        $range = is_array($scope_data) && is_array($scope_data['range'] ?? null) ? $scope_data['range'] : ['', ''];
        $strict = is_array($scope_data) && is_array($scope_data['strict'] ?? null) ? $scope_data['strict'] : [0, 0];
        $range_0 = $range[0] ?? '';
        $range_1 = $range[1] ?? '';

        $clauses = [];
        if ($range_0 !== '') {
            $clauses[] = $field . ' >' . (empty($strict[0]) ? '=' : '') . (is_scalar($range_0) ? (string) $range_0 : '') . ' ';
        }
        if ($range_1 !== '') {
            $clauses[] = $field . ' <' . (empty($strict[1]) ? '=' : '') . (is_scalar($range_1) ? (string) $range_1 : '') . ' ';
        }

        if (empty($clauses)) {
            if ($token->modifier & QST_WILDCARD) {
                return $field . ' IS NOT NULL';
            } else {
                return $field . ' IS NULL';
            }
        }
        return '(' . implode(' AND ', $clauses) . ')';
    }
}

class QDateRangeScope extends QSearchScope
{
    /**
     * @param string[] $aliases
     */
    public function __construct(
        string $id,
        array $aliases,
        bool $nullable = false
    ) {
        parent::__construct($id, $aliases, $nullable, false);
    }

    #[\Override]
    public function parse(QSingleToken $token): bool
    {
        $str = $token->term;
        $strict = [0, 0];
        if (($pos = strpos((string) $str, '..')) !== false) {
            $range = [substr((string) $str, 0, $pos), substr((string) $str, $pos + 2)];
        } elseif (@$str[0] == '>') {
            $range = [substr((string) $str, 1), ''];
            $strict[0] = 1;
        } elseif (@$str[0] == '<') {
            $range = ['', substr((string) $str, 1)];
            $strict[1] = 1;
        } elseif (($token->modifier & QST_WILDCARD_BEGIN)) {
            $range = ['', $str];
        } elseif (($token->modifier & QST_WILDCARD_END)) {
            $range = [$str, ''];
        } else {
            $range = [$str, $str];
        }

        foreach ($range as $i => &$val) {
            if (preg_match('/([0-9]{4})-?((?:1[0-2])|(?:0?[1-9]))?-?((?:(?:[1-3][0-9])|(?:0?[1-9])))?/', (string) $val, $matches)) {
                array_shift($matches);
                if (! isset($matches[1])) {
                    $matches[1] = ($i ^ $strict[$i]) ? 12 : 1;
                }
                if (! isset($matches[2])) {
                    $matches[2] = ($i ^ $strict[$i]) ? 31 : 1;
                }
                $val = implode('-', $matches);
                if ($i ^ $strict[$i]) {
                    $val .= ' 23:59:59';
                }
            } elseif (strlen((string) $val)) {
                return false;
            }
        }

        if (! $this->nullable && $range[0] == '' && $range[1] == '') {
            return false;
        }

        $token->scope_data = $range;
        return true;
    }

    #[\Override]
    public function get_sql(string $field, QSingleToken $token): string
    {
        // set by parse() above as a plain 2-element string[] range — see
        // QSingleToken::$scope_data's own docblock for why this can't be a
        // static property type.
        $scope_data = $token->scope_data;
        $date_range = is_array($scope_data) ? $scope_data : ['', ''];
        $date_range_0 = $date_range[0] ?? '';
        $date_range_1 = $date_range[1] ?? '';

        $clauses = [];
        if ($date_range_0 != '') {
            $clauses[] = $field . ' >= \'' . (is_scalar($date_range_0) ? (string) $date_range_0 : '') . '\'';
        }
        if ($date_range_1 != '') {
            $clauses[] = $field . ' <= \'' . (is_scalar($date_range_1) ? (string) $date_range_1 : '') . '\'';
        }

        if (empty($clauses)) {
            if ($token->modifier & QST_WILDCARD) {
                return $field . ' IS NOT NULL';
            } else {
                return $field . ' IS NULL';
            }
        }
        return '(' . implode(' AND ', $clauses) . ')';
    }
}

/**
 * Analyzes and splits the quick/query search query $q into tokens.
 * q='john bill' => 2 tokens 'john' 'bill'
 * Special characters for MySql full text search (+,<,>,~) appear in the token modifiers.
 * The query can contain a phrase: 'Pierre "New York"' will return 'pierre' qnd 'new york'.
 *
 * @param string $term
 */

/**
 * Represents a single word or quoted phrase to be searched.
 */
class QSingleToken implements \Stringable
{
    public bool $is_single = true; /* the actual word/phrase string */

    /**
     * @var string[]
     */
    public $variants = [];

    /**
     * @var mixed set by QSearchScope::parse() (or a subclass override);
     *   QNumericRangeScope stores array{range: array, strict: array},
     *   QDateRangeScope stores a plain 2-element string[] range — the shape
     *   depends on which scope subclass parsed this token
     */
    public $scope_data;

    public ?int $idx = null;

    public function __construct(
        public string $term,
        public int $modifier,
        public ?QSearchScope $scope
    ) {}

    public function __toString(): string
    {
        $s = '';
        if (isset($this->scope)) {
            $s .= $this->scope->id . ':';
        }
        if ($this->modifier & QST_WILDCARD_BEGIN) {
            $s .= '*';
        }
        if ($this->modifier & QST_QUOTED) {
            $s .= '"';
        }
        $s .= $this->term;
        if ($this->modifier & QST_QUOTED) {
            $s .= '"';
        }
        if ($this->modifier & QST_WILDCARD_END) {
            $s .= '*';
        }
        return $s;
    }
}

/**
 * Represents an expression of several words or sub expressions to be searched.
 */
class QMultiToken implements \Stringable
{
    public bool $is_single = false;

    public int $modifier = 0;

    /**
     * @var array<int, QSingleToken|QMultiToken>
     */
    public $tokens = []; // the actual array of QSingleToken or QMultiToken

    public function __toString(): string
    {
        $s = '';
        for ($i = 0; $i < count($this->tokens); $i++) {
            $modifier = $this->tokens[$i]->modifier;
            if ($i) {
                $s .= ' ';
            }
            if ($modifier & QST_OR) {
                $s .= 'OR ';
            }
            if ($modifier & QST_NOT) {
                $s .= 'NOT ';
            }
            if (! ($this->tokens[$i]->is_single)) {
                $s .= '(';
                $s .= $this->tokens[$i];
                $s .= ')';
            } else {
                $s .= $this->tokens[$i];
            }
        }
        return $s;
    }

    /**
     * @param-out null $scope push() only ever resets this to null; it never
     *   assigns a QSearchScope back to the caller
     */
    private function push(string &$token, int &$modifier, ?QSearchScope &$scope): void
    {
        if (strlen((string) $token) || (isset($scope) && $scope->nullable)) {
            if (isset($scope)) {
                $modifier |= QST_BREAK;
            }
            $this->tokens[] = new QSingleToken($token, $modifier, $scope);
        }
        $token = '';
        $modifier = 0;
        $scope = null;
    }

    /**
     * Parses the input query string by tokenizing the input, generating the modifiers (and/or/not/quotation/wildcards...).
     * Recursivity occurs when parsing ()
     * @param string $q the actual query to be parsed
     * @param int $qi the character index in $q where to start parsing
     * @param int $level the depth from root in the tree (number of opened and unclosed opening brackets)
     */
    protected function parse_expression(string $q, int &$qi, int $level, QExpression $root): void
    {
        $crt_token = '';
        $crt_modifier = 0;
        $crt_scope = null;

        for ($stop = false; ! $stop && $qi < strlen($q); $qi++) {
            $ch = $q[$qi];
            if (($crt_modifier & QST_QUOTED) == 0) {
                switch ($ch) {
                    case '(':
                        if (strlen((string) $crt_token)) {
                            $this->push($crt_token, $crt_modifier, $crt_scope);
                        }
                        $sub = new self();
                        $qi++;
                        $sub->parse_expression($q, $qi, $level + 1, $root);
                        $sub->modifier = $crt_modifier;
                        if (isset($crt_scope) && $crt_scope->is_text) {
                            $sub->apply_scope($crt_scope); // eg. 'tag:(John OR Bill)'
                        }
                        $this->tokens[] = $sub;
                        $crt_modifier = 0;
                        $crt_scope = null;
                        break;
                    case ')':
                        if ($level > 0) {
                            $stop = true;
                        }
                        break;
                    case ':':
                        $scope = $root->scopes[strtolower((string) $crt_token)] ?? null;
                        if (! isset($scope) || isset($crt_scope)) { // white space
                            $this->push($crt_token, $crt_modifier, $crt_scope);
                        } else {
                            $crt_token = '';
                            $crt_scope = $scope;
                        }
                        break;
                    case '"':
                        if (strlen((string) $crt_token)) {
                            $this->push($crt_token, $crt_modifier, $crt_scope);
                        }
                        $crt_modifier |= QST_QUOTED;
                        break;
                    case '-':
                        if (strlen((string) $crt_token) || isset($crt_scope)) {
                            $crt_token .= $ch;
                        } else {
                            $crt_modifier |= QST_NOT;
                        }
                        break;
                    case '*':
                        if (strlen((string) $crt_token)) {
                            $crt_token .= $ch;
                        } // wildcard end later
                        else {
                            $crt_modifier |= QST_WILDCARD_BEGIN;
                        }
                        break;
                    case '.':
                        if (isset($crt_scope) && ! $crt_scope->is_text) {
                            $crt_token .= $ch;
                            break;
                        }
                        if (strlen((string) $crt_token) && preg_match('/[0-9]/', substr((string) $crt_token, -1))
                          && $qi + 1 < strlen($q) && preg_match('/[0-9]/', $q[$qi + 1])) {// dot between digits is not a separator e.g. F2.8
                            $crt_token .= $ch;
                            break;
                        }
                        // else white space go on..
                        // no break
                    default:
                        if (! $crt_scope || ! $crt_scope->process_char($ch, $crt_token)) {
                            if (str_contains(' ,.;!?', $ch)) { // white space
                                $this->push($crt_token, $crt_modifier, $crt_scope);
                            } else {
                                $crt_token .= $ch;
                            }
                        }
                        break;
                }
            } else {// quoted
                if ($ch == '"') {
                    if ($qi + 1 < strlen($q) && $q[$qi + 1] == '*') {
                        $crt_modifier |= QST_WILDCARD_END;
                        $qi++;
                    }
                    $this->push($crt_token, $crt_modifier, $crt_scope);
                } else {
                    $crt_token .= $ch;
                }
            }
        }

        $this->push($crt_token, $crt_modifier, $crt_scope);

        for ($i = 0; $i < count($this->tokens); $i++) {
            $token = $this->tokens[$i];
            $remove = false;
            if ($token instanceof QSingleToken) {
                if (($token->modifier & QST_QUOTED) == 0
                  && str_ends_with((string) $token->term, '*')) {
                    $token->term = rtrim((string) $token->term, '*');
                    $token->modifier |= QST_WILDCARD_END;
                }

                if (! isset($token->scope)
                  && ($token->modifier & (QST_QUOTED | QST_WILDCARD)) == 0) {
                    if (strtolower((string) $token->term) == 'not') {
                        if ($i + 1 < count($this->tokens)) {
                            $this->tokens[$i + 1]->modifier |= QST_NOT;
                        }
                        $token->term = '';
                    }
                    if (strtolower((string) $token->term) == 'or') {
                        if ($i + 1 < count($this->tokens)) {
                            $this->tokens[$i + 1]->modifier |= QST_OR;
                        }
                        $token->term = '';
                    }
                    if (strtolower((string) $token->term) == 'and') {
                        $token->term = '';
                    }
                }

                if (! strlen((string) $token->term)
                  && (! isset($token->scope) || ! $token->scope->nullable)) {
                    $remove = true;
                }

                if (isset($token->scope)
                  && ! $token->scope->parse($token)) {
                    $remove = true;
                }
            } elseif (! count($token->tokens)) {
                $remove = true;
            }
            if ($remove) {
                array_splice($this->tokens, $i, 1);
                if ($i < count($this->tokens) && $this->tokens[$i]->is_single) {
                    $this->tokens[$i]->modifier |= QST_BREAK;
                }
                $i--;
            }
        }

        if ($level > 0 && count($this->tokens) && $this->tokens[0]->is_single) {
            $this->tokens[0]->modifier |= QST_BREAK;
        }
    }

    /**
     * Applies recursively a search scope to all sub single tokens. We allow 'tag:(John Bill)' but we cannot evaluate
     * scopes on expressions so we rewrite as '(tag:John tag:Bill)'
     */
    private function apply_scope(QSearchScope $scope): void
    {
        foreach ($this->tokens as $token) {
            if ($token instanceof QSingleToken) {
                if (! isset($token->scope)) {
                    $token->scope = $scope;
                }
            } else {
                $token->apply_scope($scope);
            }
        }
    }

    private static function priority(int $modifier): int
    {
        return $modifier & QST_OR ? 0 : 1;
    }

    /* because evaluations occur left to right, we ensure that 'a OR b c d' is interpreted as 'a OR (b c d)' */
    protected function check_operator_priority(): void
    {
        // Always overwritten at $i == 1 before being read at $i >= 2; the
        // initializer only keeps analysis sound.
        $crt_prio = 0;
        for ($i = 0; $i < count($this->tokens); $i++) {
            $token = $this->tokens[$i];
            if ($token instanceof self) {
                $token->check_operator_priority();
            }
            if ($i == 1) {
                $crt_prio = self::priority($token->modifier);
            }
            if ($i <= 1) {
                continue;
            }
            $prio = self::priority($token->modifier);
            if ($prio > $crt_prio) {// e.g. 'a OR b c d' i=2, operator(c)=AND -> prio(AND) > prio(OR) = operator(b)
                $term_count = 2; // at least b and c to be regrouped
                for ($j = $i + 1; $j < count($this->tokens); $j++) {
                    if (self::priority($this->tokens[$j]->modifier) >= $prio) {
                        $term_count++;
                    } // also take d
                    else {
                        break;
                    }
                }

                $i--; // move pointer to b
                // crate sub expression (b c d)
                $sub = new self();
                $sub->tokens = array_splice($this->tokens, $i, $term_count);

                // rewrite ourseleves as a (b c d)
                array_splice($this->tokens, $i, 0, [$sub]);
                $sub->modifier = $sub->tokens[0]->modifier & QST_OR;
                $sub->tokens[0]->modifier &= ~QST_OR;

                $sub->check_operator_priority();
            } else {
                $crt_prio = $prio;
            }
        }
    }
}

class QExpression extends QMultiToken
{
    /**
     * @var array<string, QSearchScope>
     */
    public $scopes = [];

    /**
     * @var array<int, QSingleToken>
     */
    public $stokens = [];

    /**
     * @var array<int, int>
     */
    public $stoken_modifiers = [];

    /**
     * @param QSearchScope[] $scopes
     */
    public function __construct(
        string $q,
        array $scopes
    ) {
        foreach ($scopes as $scope) {
            $this->scopes[$scope->id] = $scope;
            foreach ($scope->aliases as $alias) {
                $this->scopes[strtolower($alias)] = $scope;
            }
        }
        $i = 0;
        $this->parse_expression($q, $i, 0, $this);
        // manipulate the tree so that 'a OR b c' is the same as 'b c OR a'
        $this->check_operator_priority();
        $this->build_single_tokens($this, 0);
    }

    private function build_single_tokens(QMultiToken $expr, int $this_is_not): void
    {
        for ($i = 0; $i < count($expr->tokens); $i++) {
            $token = $expr->tokens[$i];
            $crt_is_not = ($token->modifier ^ $this_is_not) & QST_NOT; // no negation OR double negation -> no negation;

            if ($token instanceof QSingleToken) {
                $token->idx = count($this->stokens);
                $this->stokens[] = $token;

                $modifier = $token->modifier;
                if ($crt_is_not) {
                    $modifier |= QST_NOT;
                } else {
                    $modifier &= ~QST_NOT;
                }
                $this->stoken_modifiers[] = $modifier;
            } else {
                $this->build_single_tokens($token, $crt_is_not);
            }
        }
    }
}

/**
 * Structure of results being filled from different tables
 */
class QResults
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $all_tags = [];

    /**
     * @var array<int, array<int, int>>
     */
    public array $tag_ids = [];

    /**
     * Populated exclusively from query2array($query, null, ...) calls (id lists).
     *
     * @var array<int, list<string|null>>
     */
    public array $tag_iids = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $all_cats = [];

    /**
     * @var array<int, array<int, int>>
     */
    public array $cat_ids = [];

    /**
     * Populated exclusively from query2array($query, null, ...) calls (id lists).
     *
     * @var array<int, list<string|null>>
     */
    public array $cat_iids = [];

    /**
     * Populated exclusively from query2array($query, null, ...) calls (id lists).
     *
     * @var array<int, list<string|null>>
     */
    public array $images_iids = [];

    /**
     * array_unique(array_merge(...)) of images_iids/cat_iids/tag_iids — same
     * element type as those three, but array_unique() doesn't renumber keys
     * so this is a plain (possibly sparse) array, not a list.
     *
     * @var array<int, array<int, string|null>>
     */
    public array $iids = [];
}

/**
 * @param string[] $fields
 * @return non-falsy-string[]
 */
function qsearch_get_text_token_search_sql(QSingleToken $token, array $fields): array
{
    global $page;

    $clauses = [];
    $variants = array_merge([$token->term], $token->variants);
    $fts = [];
    foreach ($variants as $variant) {
        $use_ft = mb_strlen((string) $variant) > 3;
        if ($token->modifier & QST_WILDCARD_BEGIN) {
            $use_ft = false;
        }
        if (($token->modifier & (QST_QUOTED | QST_WILDCARD_END)) == (QST_QUOTED | QST_WILDCARD_END)) {
            $use_ft = false;
        }

        if ($use_ft) {
            $parts = preg_split('/[' . preg_quote('-\'!"#$%&()*+,./:;<=>?@[\]^`{|}~', '/') . ']+/', (string) $variant);
            if ($parts === false) {
                throw new Exception('qsearch_get_text_token_search_sql(): preg_split() failed');
            }
            $max = max(array_map(mb_strlen(...), $parts));
            if ($max < 4) {
                $use_ft = false;
            }
        }

        if (! $use_ft) {// odd term or too short for full text search; fallback to regex but unfortunately this is diacritic/accent sensitive
            if (! isset($page['use_regexp_ICU'])) {
                // Prior to MySQL 8.0.4, MySQL used the Henry Spencer regular expression library to support
                // regular expression operations, rather than International Components for Unicode (ICU)
                $page['use_regexp_ICU'] = false;
                $db_version = pwg_get_db_version();
                if (! preg_match('/mariadb/i', $db_version) and version_compare($db_version, '8.0.4', '>')) {
                    $page['use_regexp_ICU'] = true;
                }
            }

            $pre = ($token->modifier & QST_WILDCARD_BEGIN) ? '' : ($page['use_regexp_ICU'] ? '\\\\b' : '[[:<:]]');
            $post = ($token->modifier & QST_WILDCARD_END) ? '' : ($page['use_regexp_ICU'] ? '\\\\b' : '[[:>:]]');
            foreach ($fields as $field) {
                $clauses[] = $field . ' REGEXP \'' . $pre . addslashes(preg_quote((string) $variant)) . $post . '\'';
            }
        } else {
            $ft = $variant;
            if ($token->modifier & QST_QUOTED) {
                $ft = '"' . $ft . '"';
            }
            if ($token->modifier & QST_WILDCARD_END) {
                $ft .= '*';
            }
            $fts[] = $ft;
        }
    }

    if (count($fts)) {
        $clauses[] = 'MATCH(' . implode(', ', $fields) . ') AGAINST( \'' . addslashes(implode(' ', $fts)) . '\' IN BOOLEAN MODE)';
    }
    return $clauses;
}

function qsearch_get_images(QExpression $expr, QResults $qsr): void
{
    $qsr->images_iids = array_fill(0, count($expr->stokens), []);

    $query_base = 'SELECT id from ' . IMAGES_TABLE . ' i WHERE
';
    for ($i = 0; $i < count($expr->stokens); $i++) {
        $token = $expr->stokens[$i];
        $scope = $token->scope;
        $scope_id = $scope !== null ? $scope->id : 'photo';
        $clauses = [];

        $like = addslashes((string) $token->term);
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $like); // escape LIKE specials %_
        $file_like = 'CONVERT(file, CHAR) LIKE \'%' . $like . '%\'';

        // every case below other than 'photo'/'file'/'author'/default is
        // only reachable when $scope was non-null (it's the source of
        // $scope_id itself in that case), hence the asserts.
        switch ($scope_id) {
            case 'photo':
                $clauses[] = $file_like;
                $clauses = array_merge($clauses, qsearch_get_text_token_search_sql($token, ['name', 'comment']));
                break;

            case 'file':
                $clauses[] = $file_like;
                break;
            case 'author':
                if (strlen((string) $token->term)) {
                    $clauses = array_merge($clauses, qsearch_get_text_token_search_sql($token, ['author']));
                } elseif ($token->modifier & QST_WILDCARD) {
                    $clauses[] = 'author IS NOT NULL';
                } else {
                    $clauses[] = 'author IS NULL';
                }
                break;
            case 'width':
            case 'height':
                assert($scope !== null);
                $clauses[] = $scope->get_sql($scope_id, $token);
                break;
            case 'ratio':
                assert($scope !== null);
                $clauses[] = $scope->get_sql('width/height', $token);
                break;
            case 'size':
                assert($scope !== null);
                $clauses[] = $scope->get_sql('width*height', $token);
                break;
            case 'hits':
                assert($scope !== null);
                $clauses[] = $scope->get_sql('hit', $token);
                break;
            case 'score':
                assert($scope !== null);
                $clauses[] = $scope->get_sql('rating_score', $token);
                break;
            case 'filesize':
                assert($scope !== null);
                $clauses[] = $scope->get_sql('1024*filesize', $token);
                break;
            case 'created':
                assert($scope !== null);
                $clauses[] = $scope->get_sql('date_creation', $token);
                break;
            case 'posted':
                assert($scope !== null);
                $clauses[] = $scope->get_sql('date_available', $token);
                break;
            case 'id':
                assert($scope !== null);
                $clauses[] = $scope->get_sql($scope_id, $token);
                break;
            default:
                // allow plugins to have their own scope with columns added in db by themselves
                $clauses_after_hook = trigger_change('qsearch_get_images_sql_scopes', $clauses, $token, $expr);
                $clauses = is_array($clauses_after_hook) ? array_values(array_filter($clauses_after_hook, 'is_string')) : $clauses;
                break;
        }
        if (! empty($clauses)) {
            $query = $query_base . '(' . implode("\n OR ", $clauses) . ')';
            // query2array() with a value_name and no key_name always returns a
            // sequential list already, so no array_values() call is needed here.
            $qsr->images_iids[$i] = query2array($query, null, 'id');
        }
    }
}

function qsearch_get_tags(QExpression $expr, QResults $qsr): void
{
    $token_tag_ids = $qsr->tag_iids = array_fill(0, count($expr->stokens), []);
    $all_tags = [];

    for ($i = 0; $i < count($expr->stokens); $i++) {
        $token = $expr->stokens[$i];
        if (isset($token->scope) && $token->scope->id != 'tag') {
            continue;
        }
        if (empty($token->term)) {
            continue;
        }

        $clauses = qsearch_get_text_token_search_sql($token, ['name']);
        $query = 'SELECT * FROM ' . TAGS_TABLE . '
WHERE (' . implode("\n OR ", $clauses) . ')';
        $result = pwg_query($query);
        while ($tag = pwg_db_fetch_assoc($result)) {
            // 'id' is TAGS_TABLE's non-null auto-increment primary key, so it's
            // always a numeric string here — the is_numeric() guard only
            // protects against a genuinely malformed row.
            if (! is_numeric($tag['id'])) {
                continue;
            }
            $tag_id = (int) $tag['id'];
            $token_tag_ids[$i][] = $tag_id;
            $all_tags[$tag_id] = $tag;
        }
    }

    // check adjacent short words
    for ($i = 0; $i < count($expr->stokens) - 1; $i++) {
        if ((strlen((string) $expr->stokens[$i]->term) <= 3 || strlen((string) $expr->stokens[$i + 1]->term) <= 3)
          && (($expr->stoken_modifiers[$i] & (QST_QUOTED | QST_WILDCARD)) == 0)
          && (($expr->stoken_modifiers[$i + 1] & (QST_BREAK | QST_QUOTED | QST_WILDCARD)) == 0)) {
            $common = array_intersect($token_tag_ids[$i], $token_tag_ids[$i + 1]);
            if (count($common)) {
                $token_tag_ids[$i] = $token_tag_ids[$i + 1] = $common;
            }
        }
    }

    // get images
    $positive_ids = $not_ids = [];
    for ($i = 0; $i < count($expr->stokens); $i++) {
        $tag_ids = $token_tag_ids[$i];
        $token = $expr->stokens[$i];

        if (! empty($tag_ids)) {
            $query = '
SELECT image_id FROM ' . IMAGE_TAG_TABLE . '
  WHERE tag_id IN (' . implode(',', $tag_ids) . ')
  GROUP BY image_id';
            // query2array() with a value_name and no key_name always returns a
            // sequential list already, so no array_values() call is needed here.
            $qsr->tag_iids[$i] = query2array($query, null, 'image_id');
            if ($expr->stoken_modifiers[$i] & QST_NOT) {
                $not_ids = array_merge($not_ids, $tag_ids);
            } else {
                if (strlen((string) $token->term) > 2 || count($expr->stokens) == 1 || isset($token->scope) || ($token->modifier & (QST_WILDCARD | QST_QUOTED))) {// add tag ids to list only if the word is not too short (such as de / la /les ...)
                    $positive_ids = array_merge($positive_ids, $tag_ids);
                }
            }
        } elseif (isset($token->scope) && $token->scope->id == 'tag' && strlen($token->term) == 0) {
            if ($token->modifier & QST_WILDCARD) {// eg. 'tag:*' returns all tagged images
                $qsr->tag_iids[$i] = query2array('SELECT DISTINCT image_id FROM ' . IMAGE_TAG_TABLE, null, 'image_id');
            } else {// eg. 'tag:' returns all untagged images
                $qsr->tag_iids[$i] = query2array('SELECT id FROM ' . IMAGES_TABLE . ' LEFT JOIN ' . IMAGE_TAG_TABLE . ' ON id=image_id WHERE image_id IS NULL', null, 'id');
            }
        }
    }

    $all_tags = array_intersect_key($all_tags, array_flip(array_diff($positive_ids, $not_ids)));
    usort($all_tags, tag_alpha_compare(...));
    foreach ($all_tags as &$tag) {
        $tag['name'] = trigger_change('render_tag_name', $tag['name'], $tag);
    }
    $qsr->all_tags = $all_tags;
    $qsr->tag_ids = $token_tag_ids;
}

function qsearch_get_categories(QExpression $expr, QResults $qsr): void
{
    global $user, $conf;

    $token_cat_ids = $qsr->cat_iids = array_fill(0, count($expr->stokens), []);
    $all_cats = [];

    for ($i = 0; $i < count($expr->stokens); $i++) {
        $token = $expr->stokens[$i];
        if (isset($token->scope) && $token->scope->id != 'category') { // not relevant yet
            continue;
        }
        if (empty($token->term)) {
            continue;
        }

        $clauses = qsearch_get_text_token_search_sql($token, ['name', 'comment']);
        $query = '
SELECT
    *
  FROM ' . CATEGORIES_TABLE . '
    INNER JOIN ' . USER_CACHE_CATEGORIES_TABLE . ' ON id = cat_id and user_id = ' . $user['id'] . '
  WHERE (' . implode("\n OR ", $clauses) . ')';
        $result = pwg_query($query);
        while ($cat = pwg_db_fetch_assoc($result)) {
            // 'id' is CATEGORIES_TABLE's non-null auto-increment primary key, so
            // it's always a numeric string here — the is_numeric() guard only
            // protects against a genuinely malformed row.
            if (! is_numeric($cat['id'])) {
                continue;
            }
            $cat_id = (int) $cat['id'];
            $token_cat_ids[$i][] = $cat_id;
            $all_cats[$cat_id] = $cat;
        }
    }

    // check adjacent short words
    for ($i = 0; $i < count($expr->stokens) - 1; $i++) {
        if ((strlen((string) $expr->stokens[$i]->term) <= 3 || strlen((string) $expr->stokens[$i + 1]->term) <= 3)
          && (($expr->stoken_modifiers[$i] & (QST_QUOTED | QST_WILDCARD)) == 0)
          && (($expr->stoken_modifiers[$i + 1] & (QST_BREAK | QST_QUOTED | QST_WILDCARD)) == 0)) {
            $common = array_intersect($token_cat_ids[$i], $token_cat_ids[$i + 1]);
            if (count($common)) {
                $token_cat_ids[$i] = $token_cat_ids[$i + 1] = $common;
            }
        }
    }

    // get images
    $positive_ids = $not_ids = [];
    for ($i = 0; $i < count($expr->stokens); $i++) {
        $cat_ids = $token_cat_ids[$i];
        $token = $expr->stokens[$i];

        if (! empty($cat_ids)) {
            if ($conf['quick_search_include_sub_albums']) {
                $query = '
SELECT
    id
  FROM ' . CATEGORIES_TABLE . '
    INNER JOIN ' . USER_CACHE_CATEGORIES_TABLE . ' ON id = cat_id and user_id = ' . $user['id'] . '
  WHERE id IN (' . implode(',', get_subcat_ids($cat_ids)) . ')
;';
                // id is CATEGORIES_TABLE's NOT NULL primary key.
                $cat_ids = array_map(intval(...), query2array($query, null, 'id'));
            }

            $query = '
SELECT image_id FROM ' . IMAGE_CATEGORY_TABLE . '
  WHERE category_id IN (' . implode(',', $cat_ids) . ')
  GROUP BY image_id';
            // query2array() with a value_name and no key_name always returns a
            // sequential list already, so no array_values() call is needed here.
            $qsr->cat_iids[$i] = query2array($query, null, 'image_id');
            if ($expr->stoken_modifiers[$i] & QST_NOT) {
                $not_ids = array_merge($not_ids, $cat_ids);
            } else {
                if (strlen((string) $token->term) > 2 || count($expr->stokens) == 1 || isset($token->scope) || ($token->modifier & (QST_WILDCARD | QST_QUOTED))) {// add cat ids to list only if the word is not too short (such as de / la /les ...)
                    $positive_ids = array_merge($positive_ids, $cat_ids);
                }
            }
        } elseif (isset($token->scope) && $token->scope->id == 'category' && strlen($token->term) == 0) {
            if ($token->modifier & QST_WILDCARD) {// eg. 'category:*' returns all images associated to an album
                $qsr->cat_iids[$i] = query2array('SELECT DISTINCT image_id FROM ' . IMAGE_CATEGORY_TABLE, null, 'image_id');
            } else {// eg. 'category:' returns all orphan images
                $qsr->cat_iids[$i] = query2array('SELECT id FROM ' . IMAGES_TABLE . ' LEFT JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id=image_id WHERE image_id IS NULL', null, 'id');
            }
        }
    }

    $all_cats = array_intersect_key($all_cats, array_flip(array_diff($positive_ids, $not_ids)));
    usort($all_cats, tag_alpha_compare(...));
    foreach ($all_cats as &$cat) {
        $cat['name'] = trigger_change('render_category_name', $cat['name'], $cat);
    }
    $qsr->all_cats = $all_cats;
    $qsr->cat_ids = $token_cat_ids;
}

/**
 * Built exclusively from QResults::$images_iids/$cat_iids/$tag_iids (or, on
 * recursion, from this same function), all of which hold list<string|null>.
 *
 * @param string[] $ignored_terms
 * @return array<int, string|null>
 */
function qsearch_eval(QMultiToken $expr, QResults $qsr, bool &$qualifies, array &$ignored_terms): array
{
    $qualifies = false; // until we find at least one positive term
    $ignored_terms = [];

    $ids = $not_ids = [];

    for ($i = 0; $i < count($expr->tokens); $i++) {
        $crt = $expr->tokens[$i];
        $crt_qualifies = false;
        $crt_ignored_terms = [];
        if ($crt instanceof QSingleToken) {
            // idx is only null before QExpression::build_single_tokens()
            // runs; by the time qsearch_eval() is called (always on an
            // already-built QExpression), every QSingleToken has one.
            assert($crt->idx !== null);
            $crt_ids = $qsr->iids[$crt->idx] = array_unique(
                array_merge(
                    $qsr->images_iids[$crt->idx],
                    $qsr->cat_iids[$crt->idx],
                    $qsr->tag_iids[$crt->idx]
                )
            );
            $crt_qualifies = count($crt_ids) > 0 || count($qsr->tag_ids[$crt->idx]) > 0;
            $crt_ignored_terms = $crt_qualifies ? [] : [(string) $crt];
        } else {
            $crt_ids = qsearch_eval($crt, $qsr, $crt_qualifies, $crt_ignored_terms);
        }

        $modifier = $crt->modifier;
        if ($modifier & QST_NOT) {
            $not_ids = array_unique(array_merge($not_ids, $crt_ids));
        } else {
            $ignored_terms = array_merge($ignored_terms, $crt_ignored_terms);
            if ($modifier & QST_OR) {
                $ids = array_unique(array_merge($ids, $crt_ids));
                $qualifies = $qualifies || $crt_qualifies;
            } elseif ($crt_qualifies) {
                if ($qualifies) {
                    $ids = array_intersect($ids, $crt_ids);
                } else {
                    $ids = $crt_ids;
                }
                $qualifies = true;
            }
        }
    }

    if (count($not_ids)) {
        $ids = array_diff($ids, $not_ids);
    }
    return $ids;
}

/**
 * Returns the search results corresponding to a quick/query search.
 * A quick/query search returns many items (search is not strict), but results
 * are sorted by relevance unless $super_order_by is true. Returns:
 *  array (
 *    'items' => array of matching images
 *    'qs'    => array(
 *      'unmatched_terms' => array of terms from the input string that were not matched
 *      'matching_tags' => array of matching tags
 *      'matching_cats' => array of matching categories
 *      'matching_cats_no_images' =>array(99) - matching categories without images
 *      )
 *    )
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function get_quick_search_results(string $q, array $options)
{
    global $persistent_cache, $conf, $user;

    $cache_key = $persistent_cache->make_key([
        strtolower($q),
        $conf['order_by'],
        $user['id'], $user['cache_update_time'],
        isset($options['permissions']) ? (bool) $options['permissions'] : true,
        $options['images_where'] ?? '',
    ]);
    if ($persistent_cache->get($cache_key, $res)) {
        return $res;
    }

    $res = get_quick_search_results_no_cache($q, $options);

    if (is_array($res['items']) and count($res['items'])) {// cache the results only if not empty - otherwise it is useless
        $persistent_cache->set($cache_key, $res, 300);
    }
    return $res;
}

/**
 * @see get_quick_search_results but without result caching
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function get_quick_search_results_no_cache(string $q, array $options): array
{
    global $conf;

    $q = trim(stripslashes((string) $q));
    $search_results =
      [
          'items' => [],
          'qs' => [
              'q' => $q,
              'unmatched_terms' => [],
          ],
      ];

    // no in-tree listener registers for 'qsearch_pre', so $q is always still
    // a string here, but a plugin listener could theoretically return
    // something else — fall back to the pre-hook value rather than crash a
    // user-facing search.
    $q_after_hook = trigger_change('qsearch_pre', $q);
    $q = is_string($q_after_hook) ? $q_after_hook : $q;

    $scopes = [];
    $scopes[] = new QSearchScope('tag', ['tags']);
    $scopes[] = new QSearchScope('photo', ['photos']);
    $scopes[] = new QSearchScope('file', ['filename']);
    $scopes[] = new QSearchScope('author', [], true);
    $scopes[] = new QNumericRangeScope('width', []);
    $scopes[] = new QNumericRangeScope('height', []);
    $scopes[] = new QNumericRangeScope('ratio', [], false, 0.001);
    $scopes[] = new QNumericRangeScope('size', []);
    $scopes[] = new QNumericRangeScope('filesize', []);
    $scopes[] = new QNumericRangeScope('hits', ['hit', 'visit', 'visits']);
    $scopes[] = new QNumericRangeScope('score', ['rating'], true);
    $scopes[] = new QNumericRangeScope('id', []);

    $createdDateAliases = ['taken', 'shot'];
    $postedDateAliases = ['added'];
    if ($conf['calendar_datefield'] == 'date_creation') {
        $createdDateAliases[] = 'date';
    } else {
        $postedDateAliases[] = 'date';
    }
    $scopes[] = new QDateRangeScope('created', $createdDateAliases, true);
    $scopes[] = new QDateRangeScope('posted', $postedDateAliases);

    // allow plugins to add their own scopes
    $scopes_after_hook = trigger_change('qsearch_get_scopes', $scopes);
    $scopes = is_array($scopes_after_hook)
        ? array_values(array_filter($scopes_after_hook, static fn (mixed $s): bool => $s instanceof QSearchScope))
        : $scopes;
    $expression = new QExpression($q, $scopes);

    // get inflections for terms
    $inflector = null;
    $lang_code = substr(get_default_language(), 0, 2);
    @include_once PHPWG_ROOT_PATH . 'include/inflectors/' . $lang_code . '.php';
    $class_name = 'Inflector_' . $lang_code;
    if (class_exists($class_name)) {
        $inflector = new $class_name();
        if (! $inflector instanceof InflectorInterface) {
            throw new \LogicException("qsearch: {$class_name} does not implement InflectorInterface");
        }
        foreach ($expression->stokens as $token) {
            if (isset($token->scope) && ! $token->scope->is_text) {
                continue;
            }
            if (strlen((string) $token->term) > 2
              && ($token->modifier & (QST_QUOTED | QST_WILDCARD)) == 0
              && strcspn((string) $token->term, '\'0123456789') == strlen((string) $token->term)) {
                $token->variants = array_unique(array_diff($inflector->get_variants($token->term), [$token->term]));
            }
        }
    }

    trigger_notify('qsearch_expression_parsed', $expression);
    // var_export($expression);

    if (count($expression->stokens) == 0) {
        return $search_results;
    }
    $qsr = new QResults();
    qsearch_get_tags($expression, $qsr);
    qsearch_get_categories($expression, $qsr);
    qsearch_get_images($expression, $qsr);

    // allow plugins to evaluate their own scopes
    trigger_notify('qsearch_before_eval', $expression, $qsr);

    $tmp = false; // top-level "qualifies" out-param, unused by this caller
    $ids = qsearch_eval($expression, $qsr, $tmp, $search_results['qs']['unmatched_terms']);

    $debug[] = "<!--\nparsed: " . htmlspecialchars((string) $expression);
    $debug[] = count($expression->stokens) . ' tokens';
    for ($i = 0; $i < count($expression->stokens); $i++) {
        $debug[] = htmlspecialchars((string) $expression->stokens[$i]) . ': ' . count($qsr->tag_ids[$i]) . ' tags, ' . count($qsr->tag_iids[$i]) . ' tiids, ' . count($qsr->images_iids[$i]) . ' iiids, ' . count($qsr->iids[$i]) . ' iids'
          . ' modifier:' . dechex($expression->stoken_modifiers[$i])
          . (! empty($expression->stokens[$i]->variants) ? ' variants: ' . htmlspecialchars(implode(', ', $expression->stokens[$i]->variants)) : '');
    }
    $debug[] = 'before perms ' . count($ids);

    $search_results['qs']['matching_tags'] = $qsr->all_tags;
    $search_results['qs']['matching_cats'] = $qsr->all_cats;
    // no in-tree listener registers for 'qsearch_results', so $search_results
    // keeps its original array<string, mixed> shape here, but a plugin
    // listener could theoretically return something else — only string-keyed
    // entries of a genuine array response are merged back in.
    $search_results_after_hook = trigger_change('qsearch_results', $search_results, $expression, $qsr);
    if (is_array($search_results_after_hook)) {
        foreach ($search_results_after_hook as $hook_key => $hook_value) {
            if (is_string($hook_key)) {
                $search_results[$hook_key] = $hook_value;
            }
        }
    }
    if (isset($search_results['items']) and is_array($search_results['items'])) {
        $extra_ids = [];
        foreach ($search_results['items'] as $extra_id) {
            if (is_string($extra_id) or $extra_id === null) {
                $extra_ids[] = $extra_id;
            }
        }
        $ids = array_merge($ids, $extra_ids);
    }

    global $template;

    if (empty($ids)) {
        $debug[] = '-->';
        $template->append('footer_elements', implode("\n", $debug));
        return $search_results;
    }

    $permissions = ! isset($options['permissions']) ? true : $options['permissions'];

    $where_clauses = [];
    $where_clauses[] = 'i.id IN (' . implode(',', $ids) . ')';
    if (! empty($options['images_where']) and is_scalar($options['images_where'])) {
        $where_clauses[] = '(' . $options['images_where'] . ')';
    }
    if ($permissions) {
        $where_clauses[] = get_sql_condition_FandF(
            [
                'forbidden_categories' => 'category_id',
                'forbidden_images' => 'i.id',
            ],
            null,
            true
        );
    }

    $query = '
SELECT DISTINCT(id) FROM ' . IMAGES_TABLE . ' i';
    if ($permissions) {
        $query .= '
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id';
    }
    $query .= '
  WHERE ' . implode("\n AND ", $where_clauses) . "\n" .
    $conf['order_by'];

    $ids = query2array($query, null, 'id');

    $debug[] = count($ids) . ' final photo count -->';
    $template->append('footer_elements', implode("\n", $debug));

    $search_results['items'] = $ids;
    return $search_results;
}

/**
 * Returns an array of 'items' corresponding to the search id.
 * It can be either a quick search or a regular search.
 *
 * @return array<string, mixed>
 */
function get_search_results(int|string $search_id, ?bool $super_order_by, string $images_where = ''): array
{
    $search = get_search_array($search_id);
    if ($search === false) {
        bad_request('this search identifier does not exist');
    }
    if (! isset($search['q']) or ! is_string($search['q'])) {
        return get_regular_search_results($search, $images_where);
    } else {
        return get_quick_search_results($search['q'], [
            'super_order_by' => $super_order_by,
            'images_where' => $images_where,
        ]);
    }
}

/**
 * @return string[]|null
 */
function split_allwords(string $raw_allwords): ?array
{
    $words = null;

    // we specify the list of characters to trim, to add the ".". We don't want to split words
    // on "." but on ". ", and we have to deal with trailing dots.
    $raw_allwords = trim($raw_allwords, " \n\r\t\v\x00.");

    if (! preg_match('/^\s*$/', $raw_allwords)) {
        $drop_char_match = [';', '&', '(', ')', '<', '>', '`', '\'', '"', '|', ',', '@', '?', '%', '. ', '[', ']', '{', '}', ':', '\\', '/', '=', '\'', '!', '*'];
        $drop_char_replace = [' ', ' ', ' ', ' ', ' ', ' ', '', '', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', '', ' ', ' ', ' ', ' ', ' '];

        // Split words
        $split = preg_split(
            '/\s+/',
            str_replace(
                $drop_char_match,
                $drop_char_replace,
                $raw_allwords
            )
        );
        if ($split === false) {
            throw new Exception('split_allwords(): preg_split() failed');
        }
        $words = array_unique($split);
    }

    return $words;
}

function get_available_search_uuid(): string
{
    $candidate = 'psk-' . date('Ymd') . '-' . generate_key(10);

    $query = '
SELECT
    COUNT(*)
  FROM ' . SEARCH_TABLE . '
  WHERE search_uuid = \'' . $candidate . '\'
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$counter] = $row;
    if ($counter == 0) {
        return $candidate;
    } else {
        return get_available_search_uuid();
    }
}

/**
 * @param array<string, mixed> $rules
 * @return array{0: string, 1: string}
 */
function save_search(array $rules, ?int $forked_from = null): array
{
    global $user;

    $row = pwg_db_fetch_row(pwg_query('SELECT NOW()'));
    assert($row !== null);
    [$dbnow] = $row;
    $search_uuid = get_available_search_uuid();

    single_insert(
        SEARCH_TABLE,
        [
            'rules' => pwg_db_real_escape_string(serialize($rules)),
            'created_on' => $dbnow,
            'created_by' => $user['user_id'],
            'search_uuid' => $search_uuid,
            'forked_from' => $forked_from,
        ]
    );

    if (! is_a_guest() and ! is_generic()) {
        $rules_fields = $rules['fields'] ?? [];
        userprefs_update_param('gallery_search_filters', array_keys(is_array($rules_fields) ? $rules_fields : []));
    }

    $url = make_index_url(
        [
            'section' => 'search',
            'search' => $search_uuid,
        ]
    );

    return [$search_uuid, $url];
}
