<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\search
 */

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

/** @return array<string,mixed>|null */
function get_search_info(int|string $candidate): ?array
{
    $page = &$GLOBALS['page'];
    if (!is_array($page)) {
        $page = [];
    }
    // $candidate might be a search.id or a search_uuid
    $clause_pattern = get_search_id_pattern($candidate);

    if (empty($clause_pattern)) {
        throw new \Piwigo\Exception\ValidationException('Invalid search identifier');
    }

    $query = '
SELECT *
  FROM '.SEARCH_TABLE.'
  WHERE '.sprintf($clause_pattern, $candidate).'
;';
    $searches = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

    if (count($searches) > 0) {
        // we don't want spies to be able to see the search rules of any prior search (performed
        // by any user). We don't want them to be try index.php?/search/123 then index.php?/search/124
        // and so on. That's why we have implemented search_uuid with random characters.
        //
        // We also don't want to break old search urls with only the numeric id, so we only break if
        // there is no uuid.
        //
        // We also don't want to die if we're in the API.
        if (script_basename() != 'ws' and 'id = %u' == $clause_pattern and isset($searches[0]['search_uuid'])) {
            fatal_error('this search is not reachable with its id, need the search_uuid instead');
        }

        if (isset($page['section']) and 'search' == $page['section']) {
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
 * @param int|string $search_id
 * @return array<mixed>
 */
function get_search_array($search_id): array
{
    $search = get_search_info($search_id);

    if (empty($search)) {
        bad_request('this search identifier does not exist');
    }

    $rules = $search['rules'] ?? '';
    $result = unserialize(is_string($rules) ? $rules : '');
    return is_array($result) ? $result : [];
}

/**
 * Returns the list of items corresponding to the advanced search array.
 *
 * @param array<mixed> $search
 * @return array<mixed>
 */
function get_regular_search_results(array $search, ?string $images_where = ''): array
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    $logger->debug(__FUNCTION__, $search);

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

    $display_filters = safe_unserialize(\Piwigo\Config\Config::filtersViews() ?? '');

    foreach ($display_filters as $filt_name => $filt_conf) {
        $filt_conf = is_array($filt_conf) ? $filt_conf : [];
        if (isset($filt_conf['access'])) {
            $access = is_scalar($filt_conf['access']) ? (string) $filt_conf['access'] : '';
            $filt_name_str = (string) $filt_name;
            $filt_entry = is_array($display_filters[$filt_name_str] ?? null) ? (array) $display_filters[$filt_name_str] : [];
            if ($access == 'everybody' or ($access == 'admins-only' and is_admin()) or ($access == 'registered-users' and is_classic_user())) {
                $filt_entry['access'] = true;
            } else {
                $filt_entry['access'] = false;
            }
            $display_filters[$filt_name_str] = $filt_entry;
        }
    }

    //
    // expert
    //
    $expert_filter = is_array($display_filters['expert'] ?? null) ? (array) $display_filters['expert'] : [];
    $allwords_filter = is_array($display_filters['words'] ?? null) ? (array) $display_filters['words'] : [];
    $author_filter = is_array($display_filters['author'] ?? null) ? (array) $display_filters['author'] : [];
    $filetype_filter = is_array($display_filters['file_type'] ?? null) ? (array) $display_filters['file_type'] : [];
    $added_by_filter = is_array($display_filters['added_by'] ?? null) ? (array) $display_filters['added_by'] : [];
    $album_filter = is_array($display_filters['album'] ?? null) ? (array) $display_filters['album'] : [];
    $post_date_filter = is_array($display_filters['post_date'] ?? null) ? (array) $display_filters['post_date'] : [];
    $creation_date_filter = is_array($display_filters['creation_date'] ?? null) ? (array) $display_filters['creation_date'] : [];
    $ratio_filter = is_array($display_filters['ratio'] ?? null) ? (array) $display_filters['ratio'] : [];
    $rating_filter = is_array($display_filters['rating'] ?? null) ? (array) $display_filters['rating'] : [];
    $file_size_filter = is_array($display_filters['file_size'] ?? null) ? (array) $display_filters['file_size'] : [];
    $height_filter = is_array($display_filters['height'] ?? null) ? (array) $display_filters['height'] : [];
    $width_filter = is_array($display_filters['width'] ?? null) ? (array) $display_filters['width'] : [];
    $tags_filter = is_array($display_filters['tags'] ?? null) ? (array) $display_filters['tags'] : [];

    $search_fields = is_array($search['fields'] ?? null) ? $search['fields'] : [];

    $expert_fields = is_array($search_fields['expert'] ?? null) ? $search_fields['expert'] : [];
    if (isset($search_fields['expert']) and !empty($expert_fields['string']) and $expert_filter['access']) {
        $has_filters_filled = true;

        $expert_string = is_scalar($expert_fields['string']) ? (string) $expert_fields['string'] : '';
        $expert_qsr = get_quick_search_results($expert_string, []) ?? [];
        $image_ids_for_filter['expert'] = is_array($expert_qsr['items'] ?? null) ? $expert_qsr['items'] : [];
    }

    //
    // allwords
    //
    $allwords_fields = is_array($search_fields['allwords'] ?? null) ? $search_fields['allwords'] : [];
    if (isset($search_fields['allwords']) and !empty($allwords_fields['words']) and count(is_array($allwords_fields['fields'] ?? null) ? $allwords_fields['fields'] : []) > 0 and $allwords_filter['access']) {
        $has_filters_filled = true;

        // 1) we search in regular fields (ie, the ones in the piwigo_images table)
        $fields = ['file', 'name', 'comment', 'author'];

        $allwords_field_list = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($allwords_fields['fields'] ?? null) ? $allwords_fields['fields'] : []);
        if (isset($allwords_fields['fields'])) {
            $fields = array_intersect($fields, $allwords_field_list);
        }

        $cat_fields_dictionnary = [
          'cat-title' => 'name',
          'cat-desc' => 'comment',
        ];
        $cat_fields = array_intersect(array_keys($cat_fields_dictionnary), $allwords_field_list);

        // in the OR mode, request must be :
        // ((field1 LIKE '%word1%' OR field2 LIKE '%word1%')
        // OR (field1 LIKE '%word2%' OR field2 LIKE '%word2%'))
        //
        // in the AND mode :
        // ((field1 LIKE '%word1%' OR field2 LIKE '%word1%')
        // AND (field1 LIKE '%word2%' OR field2 LIKE '%word2%'))
        $word_clauses = [];
        $cat_ids_by_word = $tag_ids_by_word = [];
        $allwords_words = is_array($allwords_fields['words']) ? $allwords_fields['words'] : [];
        foreach ($allwords_words as $word) {
            $word = is_scalar($word) ? (string) $word : '';
            $field_clauses = [];
            foreach ($fields as $field) {
                $field_clauses[] = $field." LIKE '%".$word."%'";
            }

            if (count($cat_fields) > 0) {
                $cat_word_clauses = [];
                $cat_field_clauses = [];
                foreach ($cat_fields as $cat_field) {
                    $cat_field_clauses[] = $cat_fields_dictionnary[$cat_field]." LIKE '%".$word."%'";
                }

                // adds brackets around where clauses
                $cat_word_clauses[] = implode(' OR ', $cat_field_clauses);

                $query = '
SELECT
    id
  FROM '.CATEGORIES_TABLE.'
  WHERE '.implode(' OR ', $cat_word_clauses).'
;';
                $cat_ids = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'));
                $cat_ids_by_word[$word] = $cat_ids;
                if (count($cat_ids) > 0) {
                    $query = '
SELECT
    image_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id IN ('.implode(',', $cat_ids).')
;';
                    $cat_image_ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'image_id');

                    if (count($cat_image_ids) > 0) {
                        $field_clauses[] = 'id IN ('.implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $cat_image_ids)).')';
                    }
                }
            }

            // search_in_tags
            if (in_array('tags', $allwords_field_list)) {
                $query = '
SELECT
    id
  FROM '.TAGS_TABLE.'
  WHERE name LIKE \'%'.$word.'%\'
;';
                $tag_ids = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'));
                $tag_ids_by_word[$word] = $tag_ids;
                if (count($tag_ids) > 0) {
                    $query = '
SELECT
    image_id
  FROM '.IMAGE_TAG_TABLE.'
  WHERE tag_id IN ('.implode(',', $tag_ids).')
;';
                    $tag_image_ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'image_id');

                    if (count($tag_image_ids) > 0) {
                        $field_clauses[] = 'id IN ('.implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $tag_image_ids)).')';
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
                function (string &$s): void {
                    $s = '('.$s.')';
                }
            );
        }

        // make sure the "mode" is either OR or AND
        $allwords_mode = is_scalar($allwords_fields['mode'] ?? null) ? (string) $allwords_fields['mode'] : 'AND';
        if (!in_array($allwords_mode, ['OR', 'AND'])) {
            $allwords_mode = 'AND';
        }

        $filter_clause = "\n         ".implode(
            "\n         ". $allwords_mode. "\n         ",
            $word_clauses
        );

        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE '.$filter_clause.'
  '.$forbidden.'
;';
        $image_ids_for_filter['allwords'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');

        if (count($cat_ids_by_word) > 0) {
            $matching_cat_ids = null;
            foreach ($cat_ids_by_word as $idx => $cat_ids) {
                if (is_null($matching_cat_ids)) {
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
                if (is_null($matching_tag_ids)) {
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
    $author_fields = is_array($search_fields['author'] ?? null) ? $search_fields['author'] : [];
    $author_words = is_array($author_fields['words'] ?? null) ? $author_fields['words'] : [];
    if (isset($search_fields['author']) and count($author_words) > 0 and $author_filter['access']) {
        $has_filters_filled = true;

        $author_clauses = [];
        foreach ($author_words as $word) {
            $word = is_scalar($word) ? (string) $word : '';
            $author_clauses[] = "author = '".$word."'";
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE ('.implode(' OR ', $author_clauses).')
  '.$forbidden.'
;';
        $image_ids_for_filter['author'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    }

    //
    // filetypes
    //
    $filetypes_list = is_array($search_fields['filetypes'] ?? null) ? $search_fields['filetypes'] : [];
    if (!empty($search_fields['filetypes']) and $filetype_filter['access']) {
        $has_filters_filled = true;

        $filetypes_clauses = [];
        foreach ($filetypes_list as $ext) {
            $ext = is_scalar($ext) ? (string) $ext : '';
            $filetypes_clauses[] = 'path LIKE \'%.'.$ext.'\'';
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE ('.implode(' OR ', $filetypes_clauses).')
  '.$forbidden.'
;';
        $image_ids_for_filter['filetypes'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    }

    //
    // added_by
    //
    $added_by_list = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($search_fields['added_by'] ?? null) ? $search_fields['added_by'] : []);
    if (!empty($search_fields['added_by']) and $added_by_filter['access']) {
        $has_filters_filled = true;

        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE added_by IN ('.implode(',', $added_by_list).')
  '.$forbidden.'
;';
        $image_ids_for_filter['added_by'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    }

    //
    // cat
    //
    $cat_fields_data = is_array($search_fields['cat'] ?? null) ? $search_fields['cat'] : [];
    $cat_words = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($cat_fields_data['words'] ?? null) ? $cat_fields_data['words'] : []);
    if (isset($search_fields['cat']) and !empty($cat_words) and $album_filter['access']) {
        $has_filters_filled = true;

        $cat_sub_inc = !empty($cat_fields_data['sub_inc']);
        if ($cat_sub_inc) {
            // searching all the categories id of sub-categories
            $cat_ids = get_subcat_ids($cat_words);
        } else {
            // DEFERRED: cat_ids are used as-is without checking existence or user
            // access — the SQL safely returns empty results for missing categories,
            // but forbidden categories may still be matched.
            $cat_ids = $cat_words;
        }

        // in case the album would no longer exists, we consider the filter on album no longer active
        if (!empty($cat_ids)) {
            $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE category_id IN ('.implode(',', $cat_ids).')
  '.$forbidden.'
;';
            $image_ids_for_filter['cat'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
        }
    }

    //
    // date_posted
    //
    $date_posted_fields = is_array($search_fields['date_posted'] ?? null) ? $search_fields['date_posted'] : [];
    $date_posted_preset = is_scalar($date_posted_fields['preset'] ?? null) ? (string) $date_posted_fields['preset'] : '';
    if (!empty($date_posted_preset) and $post_date_filter['access']) {

        $has_filters_filled = true;

        $options = [
          '24h' => '24 HOUR',
          '7d' => '7 DAY',
          '30d' => '30 DAY',
          '3m' => '3 MONTH',
          '6m' => '6 MONTH',
        ];

        $date_posted_clause = '';
        if (isset($options[$date_posted_preset])) {
            $date_posted_clause = 'date_available > SUBDATE(NOW(), INTERVAL '.$options[$date_posted_preset].')';
        } elseif ('custom' == $date_posted_preset and isset($date_posted_fields['custom'])) {
            $date_posted_subclauses = [];
            $date_posted_custom = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($date_posted_fields['custom']) ? $date_posted_fields['custom'] : []);
            $custom_dates = array_flip($date_posted_custom);

            foreach (array_keys($custom_dates) as $custom_date) {
                // in real-life tests, we have determined "where year(date_available) = 2024" was
                // far less (4 times less) than "where date_available between '2024-01-01 00:00:00' and '2024-12-31 23:59:59'"
                // so let's find the begin/end for each custom date
                // ... and also, no need to search for images of 2023-10-16 if 2023-10 is already requested
                $begin = $end = null;

                $ymd = substr((string) $custom_date, 0, 1);
                if ('y' == $ymd) {
                    $year = substr((string) $custom_date, 1);
                    $begin = $year.'-01-01 00:00:00';
                    $end = $year.'-12-31 23:59:59';
                } elseif ('m' == $ymd) {
                    [$year, $month] = explode('-', substr((string) $custom_date, 1));

                    if (!isset($custom_dates['y'.$year])) {
                        $begin = $year.'-'.$month.'-01 00:00:00';
                        $end = $year.'-'.$month.'-'.cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year).' 23:59:59';
                    }
                } elseif ('d' == $ymd) {
                    [$year, $month, $day] = explode('-', substr((string) $custom_date, 1));

                    if (!isset($custom_dates['y'.$year]) and !isset($custom_dates['m'.$year.'-'.$month])) {
                        $begin = $year.'-'.$month.'-'.$day.' 00:00:00';
                        $end = $year.'-'.$month.'-'.$day.' 23:59:59';
                    }
                }

                if (!empty($begin)) {
                    $date_posted_subclauses[] = 'date_available BETWEEN "'.$begin.'" AND "'.$end.'"';
                }
            }

            $date_posted_clause = '('.implode(' OR ', prepend_append_array_items($date_posted_subclauses, '(', ')')).')';
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE '.$date_posted_clause.'
  '.$forbidden.'
;';

        $image_ids_for_filter['date_posted'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    }

    //
    // date_created
    //
    $date_created_fields = is_array($search_fields['date_created'] ?? null) ? $search_fields['date_created'] : [];
    $date_created_preset = is_scalar($date_created_fields['preset'] ?? null) ? (string) $date_created_fields['preset'] : '';
    if (!empty($date_created_preset) and $creation_date_filter['access']) {

        $has_filters_filled = true;

        $options = [
          '7d' => '7 DAY',
          '30d' => '30 DAY',
          '3m' => '3 MONTH',
          '6m' => '6 MONTH',
          '12m' => '12 MONTH',
        ];

        $date_created_clause = '';
        if (isset($options[$date_created_preset])) {
            $date_created_clause = 'date_creation > SUBDATE(NOW(), INTERVAL '.$options[$date_created_preset].')';
        } elseif ('custom' == $date_created_preset and isset($date_created_fields['custom'])) {
            $date_created_subclauses = [];
            $date_created_custom = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($date_created_fields['custom']) ? $date_created_fields['custom'] : []);
            $custom_dates = array_flip($date_created_custom);

            foreach (array_keys($custom_dates) as $custom_date) {
                // in real-life tests, we have determined "where year(date_creation) = 2024" was
                // far less (4 times less) than "where date_creation between '2024-01-01 00:00:00' and '2024-12-31 23:59:59'"
                // so let's find the begin/end for each custom date
                // ... and also, no need to search for images of 2023-10-16 if 2023-10 is already requested
                $begin = $end = null;

                $ymd = substr((string) $custom_date, 0, 1);
                if ('y' == $ymd) {
                    $year = substr((string) $custom_date, 1);
                    $begin = $year.'-01-01 00:00:00';
                    $end = $year.'-12-31 23:59:59';
                } elseif ('m' == $ymd) {
                    [$year, $month] = explode('-', substr((string) $custom_date, 1));

                    if (!isset($custom_dates['y'.$year])) {
                        $begin = $year.'-'.$month.'-01 00:00:00';
                        $end = $year.'-'.$month.'-'.cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year).' 23:59:59';
                    }
                } elseif ('d' == $ymd) {
                    [$year, $month, $day] = explode('-', substr((string) $custom_date, 1));

                    if (!isset($custom_dates['y'.$year]) and !isset($custom_dates['m'.$year.'-'.$month])) {
                        $begin = $year.'-'.$month.'-'.$day.' 00:00:00';
                        $end = $year.'-'.$month.'-'.$day.' 23:59:59';
                    }
                }

                if (!empty($begin)) {
                    $date_created_subclauses[] = 'date_creation BETWEEN "'.$begin.'" AND "'.$end.'"';
                }
            }

            $date_created_clause = '('.implode(' OR ', prepend_append_array_items($date_created_subclauses, '(', ')')).')';
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE '.$date_created_clause.'
  '.$forbidden.'
;';

        $image_ids_for_filter['date_created'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    }

    //
    // ratios
    //
    $ratios_list = is_array($search_fields['ratios'] ?? null) ? $search_fields['ratios'] : [];
    if (!empty($search_fields['ratios']) and $ratio_filter['access']) {
        $has_filters_filled = true;

        $clause_for_ratio = [
          'Portrait'  => 'width/height < 0.95',
          'square'    => 'width/height BETWEEN 0.95 AND 1.05',
          'Landscape' => '(width/height > 1.05 AND width/height < 2)',
          'Panorama'  => 'width/height >= 2',
        ];

        $ratios_clauses = [];
        foreach ($ratios_list as $r) {
            $r = is_scalar($r) ? (string) $r : '';
            $ratios_clauses[] = $clause_for_ratio[$r];
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE ('.implode(' OR ', $ratios_clauses).')
  '.$forbidden.'
;';
        $image_ids_for_filter['ratios'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    }

    //
    // ratings
    //
    $ratings_list = is_array($search_fields['ratings'] ?? null) ? $search_fields['ratings'] : [];
    if (\Piwigo\Config\Config::rateEnabled() and !empty($search_fields['ratings']) and $rating_filter['access']) {
        $has_filters_filled = true;

        $filter_clauses = [];
        foreach ($ratings_list as $r) {
            $r = is_scalar($r) ? (int) $r : 0;
            if (0 == $r) {
                $filter_clauses[] = 'rating_score IS NULL';
            } else {
                $filter_clauses[] = '(rating_score >= '.($r - 1).' AND rating_score < '.$r.')';
            }
        }

        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE ('.implode(' OR ', $filter_clauses).')
  '.$forbidden.'
;';
        $image_ids_for_filter['ratings'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    }

    //
    // filesize
    //
    $filesize_min = is_numeric($search_fields['filesize_min'] ?? null) ? (int) $search_fields['filesize_min'] : 0;
    $filesize_max = is_numeric($search_fields['filesize_max'] ?? null) ? (int) $search_fields['filesize_max'] : 0;
    if (!empty($search_fields['filesize_min']) and !empty($search_fields['filesize_max']) and $file_size_filter['access']) {
        $has_filters_filled = true;

        // because of conversion from kB to mB, approximation, then conversion back to kB,
        // we need to slightly enlarge the range for search
        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE filesize BETWEEN '.($filesize_min - 100).' AND '.($filesize_max + 100).'
  '.$forbidden.'
;';
        $image_ids_for_filter['filesize'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    }

    //
    // height
    //
    $height_min = is_numeric($search_fields['height_min'] ?? null) ? (int) $search_fields['height_min'] : 0;
    $height_max = is_numeric($search_fields['height_max'] ?? null) ? (int) $search_fields['height_max'] : 0;
    if (!empty($search_fields['height_min']) and !empty($search_fields['height_max']) and $height_filter['access']) {
        $has_filters_filled = true;

        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE height BETWEEN '.$height_min.' AND '.$height_max.'
  '.$forbidden.'
;';
        $image_ids_for_filter['height'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    }

    //
    // width
    //
    $width_min = is_numeric($search_fields['width_min'] ?? null) ? (int) $search_fields['width_min'] : 0;
    $width_max = is_numeric($search_fields['width_max'] ?? null) ? (int) $search_fields['width_max'] : 0;
    if (!empty($search_fields['width_min']) and !empty($search_fields['width_max']) and $width_filter['access']) {
        $has_filters_filled = true;

        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE width BETWEEN '.$width_min.' AND '.$width_max.'
  '.$forbidden.'
;';
        $image_ids_for_filter['width'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    }

    //
    // tags
    //
    $tags_fields = is_array($search_fields['tags'] ?? null) ? $search_fields['tags'] : [];
    $tags_words = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($tags_fields['words'] ?? null) ? $tags_fields['words'] : []);
    $tags_mode = is_scalar($tags_fields['mode'] ?? null) ? (string) $tags_fields['mode'] : 'AND';
    if (isset($search_fields['tags']) and !empty($tags_words) and $tags_filter['access']) {
        $has_filters_filled = true;

        $image_ids_for_filter['tags'] = get_image_ids_for_tags(
            $tags_words,
            $tags_mode
        );
    }

    //
    // custom search
    //
    if (!empty($images_where)) {
        $query = '
SELECT
    DISTINCT(id)
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id
  WHERE '.$images_where.'
  '.$forbidden.'
;';
        $image_ids_for_filter['custom'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    }

    $items = [];
    if (!empty($image_ids_for_filter)) {
        /** @var array<int, array<string>> $typed_filter_values */
        $typed_filter_values = array_map(
            /** @param array<mixed> $v */
            static function (array $v): array {
                return array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $v);
            },
            array_values($image_ids_for_filter)
        );
        if (count($typed_filter_values) > 1) {
            $items = array_values(array_unique(array_intersect(...$typed_filter_values)));
        } else {
            $items = $typed_filter_values[0];
        }
    }

    $logger->debug(__FUNCTION__.' '.count($items).' items in $unsorted_items');

    if (count($items) > 1) {
        $query = '
SELECT
    id
  FROM '.IMAGES_TABLE.' i
  WHERE id IN ('.implode(',', $items).')
  '.\Piwigo\Config\Config::orderBy();

        $items = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
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
 *
 * @param string $filter_name
 */
function get_clause_for_filter($filter_name): string
{
    $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

    $other_filters_items = get_items_for_filter($filter_name);
    if (false === $other_filters_items) {
        $details = is_array($page['search_details'] ?? null) ? $page['search_details'] : [];
        $forbidden = is_string($details['forbidden'] ?? null) ? $details['forbidden'] : '';
        return '1=1'.$forbidden;
    }

    return 'image_id IN ('.implode(',', $other_filters_items).')';
}

/**
 * Returns the list of items (image_ids) to be used to build filter values
 * for a given filter. Depends on the other filters. Use a cache to avoid
 * computing the same large array_intersect several times.
 *
 * @since 15
 *
 * @param string $filter_name
 *
 * @return array<int>|false of image_ids
 */
function get_items_for_filter(string $filter_name)
{
    $logger = \Piwigo\Core\LoggerRegistry::current();
    $page = &$GLOBALS['page'];
    if (!is_array($page)) {
        $page = [];
    }
    $detailsRaw = $page['search_details'] ?? [];
    $details = is_array($detailsRaw) ? $detailsRaw : [];
    $imageIdsForFilter = is_array($details['image_ids_for_filter'] ?? null) ? $details['image_ids_for_filter'] : [];
    $other_filters = array_diff(array_keys($imageIdsForFilter), [$filter_name]);

    if (empty($other_filters)) {
        return false;
    }

    $cache_key = md5(implode(',', $other_filters));

    $details = is_array($page['search_details'] ?? null) ? $page['search_details'] : [];
    $cache = is_array($details[__FUNCTION__] ?? null) ? $details[__FUNCTION__] : [];
    if (!isset($cache[$cache_key])) {
        $function_start = get_moment();

        $first = $imageIdsForFilter[array_shift($other_filters)] ?? [];
        $other_filters_items = is_array($first)
            ? array_values(array_filter($first, fn ($v) => is_int($v) || is_string($v)))
            : [];
        foreach ($other_filters as $other_filter) {
            $nextRaw = is_array($imageIdsForFilter[$other_filter] ?? null) ? $imageIdsForFilter[$other_filter] : [];
            $next = array_filter($nextRaw, fn ($v) => is_int($v) || is_string($v));
            $other_filters_items = array_intersect($other_filters_items, $next);
        }

        $other_filters_items = array_values(array_unique($other_filters_items));

        $debug_msg = '['.__FUNCTION__.'] cache computed for '.(count($other_filters) + 1).' other filters';
        $debug_msg .= ' ('.count($other_filters_items).' items)';
        $debug_msg .= ', time = '.get_elapsed_time($function_start, get_moment());
        $logger->debug($debug_msg);

        if (empty($other_filters_items)) {
            $other_filters_items = [-1];
        }

        $cache[$cache_key] = $other_filters_items;
        $details[__FUNCTION__] = $cache;
        $page['search_details'] = $details;
    }

    $rawCached = is_array($cache[$cache_key]) ? $cache[$cache_key] : [];
    $cached = [];
    foreach ($rawCached as $v) {
        if (is_int($v) || is_string($v)) {
            $cached[] = (int) $v;
        }
    }
    return $cached;
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
    /** @param string[] $aliases */
    public function __construct(public string $id, public array $aliases, public bool $nullable = false, public bool $is_text = true)
    {
    }

    public function parse(QSingleToken $token): bool
    {
        if (!$this->nullable && 0 == strlen((string) $token->term)) {
            return false;
        }
        return true;
    }

    public function process_char(string &$ch, string &$crt_token): bool
    {
        return false;
    }
}

class QNumericRangeScope extends \Piwigo\Search\QSearchScope
{
    /** @param string[] $aliases */
    public function __construct(string $id, array $aliases, bool $nullable = false, private int|float $epsilon = 0)
    {
        parent::__construct($id, $aliases, $nullable, false);
    }

    #[\Override]
    public function parse(\Piwigo\Search\QSingleToken $token): bool
    {
        $str = $token->term;
        $strict = [0,0];
        $range_requested = true;
        if (($pos = strpos((string) $str, '..')) !== false) {
            $range = [ substr((string) $str, 0, $pos), substr((string) $str, $pos + 2)];
        } elseif ('>' === ($str[0] ?? '')) {// ratio:>1
            $range = [ substr((string) $str, 1), ''];
            $strict[0] = 1;
        } elseif ('<' === ($str[0] ?? '')) { // size:<5mp
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
                $val = floatval((float)$matches[1] / (float)$matches[2]);
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
                    if ($i && !$range_requested) {// round up the upper limit if possible - e.g 6k goes up to 6999, but 6.12k goes only up to 6129
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

        if (!$this->nullable && $range[0] === '' && $range[1] === '') {
            return false;
        }
        $token->scope_data = [ 'range' => $range, 'strict' => $strict ];
        return true;
    }

    public function get_sql(string $field, \Piwigo\Search\QSingleToken $token): string
    {
        $scope_data = is_array($token->scope_data) ? $token->scope_data : [];
        $range = is_array($scope_data['range'] ?? null) ? $scope_data['range'] : ['', ''];
        $strict = is_array($scope_data['strict'] ?? null) ? $scope_data['strict'] : [0, 0];
        $range0 = is_scalar($range[0] ?? null) ? (string) $range[0] : '';
        $range1 = is_scalar($range[1] ?? null) ? (string) $range[1] : '';
        $strict0 = !empty($strict[0]);
        $strict1 = !empty($strict[1]);
        $clauses = [];
        if ($range0 !== '') {
            $clauses[] = $field.' >'.($strict0 ? '' : '=').$range0.' ';
        }
        if ($range1 !== '') {
            $clauses[] = $field.' <'.($strict1 ? '' : '=').$range1.' ';
        }

        if (empty($clauses)) {
            if ($token->modifier & QST_WILDCARD) {
                return $field.' IS NOT NULL';
            } else {
                return $field.' IS NULL';
            }
        }
        return '('.implode(' AND ', $clauses).')';
    }
}


class QDateRangeScope extends \Piwigo\Search\QSearchScope
{
    /** @param string[] $aliases */
    public function __construct(string $id, array $aliases, bool $nullable = false)
    {
        parent::__construct($id, $aliases, $nullable, false);
    }

    #[\Override]
    public function parse(\Piwigo\Search\QSingleToken $token): bool
    {
        $str = $token->term;
        $strict = [0,0];
        if (($pos = strpos((string) $str, '..')) !== false) {
            $range = [ substr((string) $str, 0, $pos), substr((string) $str, $pos + 2)];
        } elseif ('>' === ($str[0] ?? '')) {
            $range = [ substr((string) $str, 1), ''];
            $strict[0] = 1;
        } elseif ('<' === ($str[0] ?? '')) {
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
                if (!isset($matches[1])) {
                    $matches[1] = ($i ^ $strict[$i]) ? 12 : 1;
                }
                if (!isset($matches[2])) {
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

        if (!$this->nullable && $range[0] == '' && $range[1] == '') {
            return false;
        }

        $token->scope_data = $range;
        return true;
    }

    public function get_sql(string $field, \Piwigo\Search\QSingleToken $token): string
    {
        $scope_data = is_array($token->scope_data) ? $token->scope_data : ['', ''];
        $val0 = is_scalar($scope_data[0] ?? null) ? (string) $scope_data[0] : '';
        $val1 = is_scalar($scope_data[1] ?? null) ? (string) $scope_data[1] : '';
        $clauses = [];
        if ($val0 != '') {
            $clauses[] = $field.' >= \'' . $val0.'\'';
        }
        if ($val1 != '') {
            $clauses[] = $field.' <= \'' . $val1.'\'';
        }

        if (empty($clauses)) {
            if ($token->modifier & QST_WILDCARD) {
                return $field.' IS NOT NULL';
            } else {
                return $field.' IS NULL';
            }
        }
        return '('.implode(' AND ', $clauses).')';
    }
}

/**
 * Analyzes and splits the quick/query search query $q into tokens.
 * q='john bill' => 2 tokens 'john' 'bill'
 * Special characters for MySql full text search (+,<,>,~) appear in the token modifiers.
 * The query can contain a phrase: 'Pierre "New York"' will return 'pierre' qnd 'new york'.
 *
 * @param string $q
 */

/** Represents a single word or quoted phrase to be searched.*/
class QSingleToken implements \Stringable
{
    public bool $is_single = true; /* the actual word/phrase string*/
    /** @var string[] */
    public array $variants = [];

    public mixed $scope_data = null;
    public int $idx = 0;

    public function __construct(public string $term, public int $modifier, public ?QSearchScope $scope)
    {
    }

    public function __toString(): string
    {
        $s = '';
        if (isset($this->scope)) {
            $s .= $this->scope->id .':';
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

/** Represents an expression of several words or sub expressions to be searched.*/
class QMultiToken implements \Stringable
{
    public bool $is_single = false;
    public int $modifier = 0;
    /** @var array<\Piwigo\Search\QSingleToken|\Piwigo\Search\QMultiToken> */
    public array $tokens = []; // the actual array of QSingleToken or QMultiToken

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
     * @param-out null $scope
     */
    private function push(string &$token, int &$modifier, ?\Piwigo\Search\QSearchScope &$scope): void
    {
        if (strlen((string) $token) || (isset($scope) && $scope->nullable)) {
            if (isset($scope)) {
                $modifier |= QST_BREAK;
            }
            $this->tokens[] = new \Piwigo\Search\QSingleToken($token, $modifier, $scope);
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
    protected function parse_expression(string $q, int &$qi, int $level, \Piwigo\Search\QExpression $root): void
    {
        $crt_token = '';
        $crt_modifier = 0;
        /** @var ?\Piwigo\Search\QSearchScope $crt_scope */
        $crt_scope = null;

        for ($stop = false; !$stop && $qi < strlen($q); $qi++) {
            $ch = $q[$qi];
            if (($crt_modifier & QST_QUOTED) == 0) {
                switch ($ch) {
                    case '(':
                        if (strlen((string) $crt_token)) {
                            $this->push($crt_token, $crt_modifier, $crt_scope);
                        }
                        $sub = new \Piwigo\Search\QMultiToken();
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
                        if (!isset($scope) || isset($crt_scope)) { // white space
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
                        if (isset($crt_scope) && !$crt_scope->is_text) {
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
                        if (!$crt_scope || !$crt_scope->process_char($ch, $crt_token)) {
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
            if ($token instanceof \Piwigo\Search\QSingleToken) {
                if (($token->modifier & QST_QUOTED) == 0
                  && str_ends_with((string) $token->term, '*')) {
                    $token->term = rtrim((string) $token->term, '*');
                    $token->modifier |= QST_WILDCARD_END;
                }

                if (!isset($token->scope)
                  && ($token->modifier & (QST_QUOTED | QST_WILDCARD)) == 0) {
                    if ('not' == strtolower((string) $token->term)) {
                        if ($i + 1 < count($this->tokens)) {
                            $this->tokens[$i + 1]->modifier |= QST_NOT;
                        }
                        $token->term = '';
                    }
                    if ('or' == strtolower((string) $token->term)) {
                        if ($i + 1 < count($this->tokens)) {
                            $this->tokens[$i + 1]->modifier |= QST_OR;
                        }
                        $token->term = '';
                    }
                    if ('and' == strtolower((string) $token->term)) {
                        $token->term = '';
                    }
                }

                if (!strlen((string) $token->term)
                  && (!isset($token->scope) || !$token->scope->nullable)) {
                    $remove = true;
                }

                if (isset($token->scope)
                  && !$token->scope->parse($token)) {
                    $remove = true;
                }
            } elseif (!count($token->tokens)) {
                $remove = true;
            }
            if ($remove) {
                array_splice($this->tokens, $i, 1);
                if ($i < count($this->tokens) && $this->tokens[$i] instanceof \Piwigo\Search\QSingleToken) {
                    $this->tokens[$i]->modifier |= QST_BREAK;
                }
                $i--;
            }
        }

        if ($level > 0 && count($this->tokens) && $this->tokens[0] instanceof \Piwigo\Search\QSingleToken) {
            $this->tokens[0]->modifier |= QST_BREAK;
        }
    }

    /**
    * Applies recursively a search scope to all sub single tokens. We allow 'tag:(John Bill)' but we cannot evaluate
    * scopes on expressions so we rewrite as '(tag:John tag:Bill)'
    */
    protected function apply_scope(\Piwigo\Search\QSearchScope $scope): void
    {
        for ($i = 0; $i < count($this->tokens); $i++) {
            if ($this->tokens[$i] instanceof \Piwigo\Search\QSingleToken) {
                if (!isset($this->tokens[$i]->scope)) {
                    $this->tokens[$i]->scope = $scope;
                }
            } elseif ($this->tokens[$i] instanceof \Piwigo\Search\QMultiToken) {
                $this->tokens[$i]->apply_scope($scope);
            }
        }
    }

    private static function priority(int $modifier): int
    {
        return $modifier & QST_OR ? 0 : 1;
    }

    /* because evaluations occur left to right, we ensure that 'a OR b c d' is interpreted as 'a OR (b c d)'*/
    protected function check_operator_priority(): void
    {
        $crt_prio = 0;
        for ($i = 0; $i < count($this->tokens); $i++) {
            if ($this->tokens[$i] instanceof \Piwigo\Search\QMultiToken) {
                $this->tokens[$i]->check_operator_priority();
            }
            if ($i == 1) {
                $crt_prio = self::priority($this->tokens[$i]->modifier);
            }
            if ($i <= 1) {
                continue;
            }
            $prio = self::priority($this->tokens[$i]->modifier);
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
                $sub = new \Piwigo\Search\QMultiToken();
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

class QExpression extends \Piwigo\Search\QExpression
{
    /** @param array<\Piwigo\Search\QSearchScope> $scopes */
    public function __construct(string $q, array $scopes)
    {
        // replicate parent constructor logic using our extended parse_expression
        foreach ($scopes as $scope) {
            $this->scopes[$scope->id] = $scope;
            foreach ($scope->aliases as $alias) {
                $this->scopes[strtolower($alias)] = $scope;
            }
        }
        $i = 0;
        $this->parse_expression($q, $i, 0, $this);
        //manipulate the tree so that 'a OR b c' is the same as 'b c OR a'
        $this->check_operator_priority();
        $this->build_single_tokens_impl($this, 0);
    }

    private function build_single_tokens_impl(\Piwigo\Search\QMultiToken $expr, int $this_is_not): void
    {
        for ($i = 0; $i < count($expr->tokens); $i++) {
            $token = $expr->tokens[$i];
            $crt_is_not = ($token->modifier ^ $this_is_not) & QST_NOT; // no negation OR double negation -> no negation;

            if ($token instanceof \Piwigo\Search\QSingleToken) {
                $token->idx = count($this->stokens);
                $this->stokens[] = $token;

                $modifier = $token->modifier;
                if ($crt_is_not) {
                    $modifier |= QST_NOT;
                } else {
                    $modifier &= ~QST_NOT;
                }
                $this->stoken_modifiers[] = $modifier;
            } elseif ($token instanceof \Piwigo\Search\QMultiToken) {
                $this->build_single_tokens_impl($token, $this_is_not);
            }
        }
    }
}

/**
  Structure of results being filled from different tables
*/
class QResults
{
    /** @var array<mixed> */
    public array $all_tags = [];
    /** @var array<int, int[]> */
    public array $tag_ids = [];
    /** @var array<int, int[]> */
    public array $tag_iids = [];
    /** @var array<mixed> */
    public array $all_cats = [];
    /** @var array<int, int[]> */
    public array $cat_ids = [];
    /** @var array<int, int[]> */
    public array $cat_iids = [];
    /** @var array<int, int[]> */
    public array $images_iids = [];
    /** @var array<int, int[]> */
    public array $iids = [];
}

/**
 * @param string[] $fields
 * @return non-falsy-string[]
 */
function qsearch_get_text_token_search_sql(\Piwigo\Search\QSingleToken $token, array $fields): array
{
    $page = &$GLOBALS['page'];
    if (!is_array($page)) {
        $page = [];
    }
    $clauses = [];
    $variants = array_merge([$token->term], $token->variants);
    $fts = [];
    foreach ($variants as $variant) {
        $use_ft = mb_strlen((string) $variant) > 3;
        if ($token->modifier & QST_WILDCARD_BEGIN) {
            $use_ft = false;
        }
        if (($token->modifier & (QST_QUOTED | QST_WILDCARD_END)) === (QST_QUOTED | QST_WILDCARD_END)) {
            $use_ft = false;
        }

        if ($use_ft) {
            $parts = preg_split('/['.preg_quote('-\'!"#$%&()*+,./:;<=>?@[\]^`{|}~', '/').']+/', (string) $variant);
            $max = ($parts !== false) ? max(array_map(mb_strlen(...), $parts)) : 0;
            if ($max < 4) {
                $use_ft = false;
            }
        }

        if (!$use_ft) {// odd term or too short for full text search; fallback to regex but unfortunately this is diacritic/accent sensitive
            if (!isset($page['use_regexp_ICU'])) {
                // Prior to MySQL 8.0.4, MySQL used the Henry Spencer regular expression library to support
                // regular expression operations, rather than International Components for Unicode (ICU)
                $page['use_regexp_ICU'] = false;
                $db_version = \Piwigo\Db\DbInfo::version();
                if (!preg_match('/mariadb/i', $db_version) and version_compare($db_version, '8.0.4', '>')) {
                    $page['use_regexp_ICU'] = true;
                }
            }

            $pre = ($token->modifier & QST_WILDCARD_BEGIN) ? '' : ($page['use_regexp_ICU'] ? '\\\\b' : '[[:<:]]');
            $post = ($token->modifier & QST_WILDCARD_END) ? '' : ($page['use_regexp_ICU'] ? '\\\\b' : '[[:>:]]');
            foreach ($fields as $field) {
                $clauses[] = $field.' REGEXP \''.$pre.addslashes(preg_quote((string) $variant)).$post.'\'';
            }
        } else {
            $ft = $variant;
            if ($token->modifier & QST_QUOTED) {
                $ft = '"'.$ft.'"';
            }
            if ($token->modifier & QST_WILDCARD_END) {
                $ft .= '*';
            }
            $fts[] = $ft;
        }
    }

    if (count($fts)) {
        $clauses[] = 'MATCH('.implode(', ', $fields).') AGAINST( \''.addslashes(implode(' ', $fts)).'\' IN BOOLEAN MODE)';
    }
    return $clauses;
}

function qsearch_get_images(\Piwigo\Search\QExpression $expr, \Piwigo\Search\QResults $qsr): void
{
    $qsr->images_iids = array_fill(0, count($expr->stokens), []);

    $query_base = 'SELECT id from '.IMAGES_TABLE.' i WHERE
';
    for ($i = 0; $i < count($expr->stokens); $i++) {
        $token = $expr->stokens[$i];
        $scope_id = isset($token->scope) ? $token->scope->id : 'photo';
        $token_scope = $token->scope;
        $clauses = [];

        $like = addslashes((string) $token->term);
        $like = str_replace(['%','_'], ['\\%','\\_'], $like); // escape LIKE specials %_
        $file_like = 'CONVERT(file, CHAR) LIKE \'%'.$like.'%\'';

        switch ($scope_id) {
            case 'photo':
                $clauses[] = $file_like;
                $clauses = array_merge($clauses, qsearch_get_text_token_search_sql($token, ['name','comment']));
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
                if ($token_scope !== null) {
                    $clauses[] = $token_scope->get_sql($scope_id, $token);
                }
                break;
            case 'ratio':
                if ($token_scope !== null) {
                    $clauses[] = $token_scope->get_sql('width/height', $token);
                }
                break;
            case 'size':
                if ($token_scope !== null) {
                    $clauses[] = $token_scope->get_sql('width*height', $token);
                }
                break;
            case 'hits':
                if ($token_scope !== null) {
                    $clauses[] = $token_scope->get_sql('hit', $token);
                }
                break;
            case 'score':
                if ($token_scope !== null) {
                    $clauses[] = $token_scope->get_sql('rating_score', $token);
                }
                break;
            case 'filesize':
                if ($token_scope !== null) {
                    $clauses[] = $token_scope->get_sql('1024*filesize', $token);
                }
                break;
            case 'created':
                if ($token_scope !== null) {
                    $clauses[] = $token_scope->get_sql('date_creation', $token);
                }
                break;
            case 'posted':
                if ($token_scope !== null) {
                    $clauses[] = $token_scope->get_sql('date_available', $token);
                }
                break;
            case 'id':
                if ($token_scope !== null) {
                    $clauses[] = $token_scope->get_sql($scope_id, $token);
                }
                break;
            default:
                // allow plugins to have their own scope with columns added in db by themselves
                $clauses = trigger_change('qsearch_get_images_sql_scopes', $clauses, $token, $expr);
                break;
        }
        if (!empty($clauses)) {
            $query = $query_base.'('.implode("\n OR ", $clauses).')';
            $qsr->images_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'));
        }
    }
}

function qsearch_get_tags(\Piwigo\Search\QExpression $expr, \Piwigo\Search\QResults $qsr): void
{
    $token_tag_ids = $qsr->tag_iids = array_fill(0, count($expr->stokens), []);
    $all_tags = [];

    for ($i = 0; $i < count($expr->stokens); $i++) {
        $token = $expr->stokens[$i];
        if (isset($token->scope) && 'tag' != $token->scope->id) {
            continue;
        }
        if (empty($token->term)) {
            continue;
        }

        $clauses = qsearch_get_text_token_search_sql($token, ['name']);
        $query = 'SELECT * FROM ' . TAGS_TABLE . ' WHERE (' . implode("\n OR ", $clauses) . ')';
        foreach (\Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
            ->executeQuery($query)->fetchAllAssociative() as $tag) {
            $tag_id = is_numeric($tag['id']) ? (int) $tag['id'] : 0;
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
                $token_tag_ids[$i] = $token_tag_ids[$i + 1] = array_values($common);
            }
        }
    }

    // get images
    $positive_ids = $not_ids = [];
    for ($i = 0; $i < count($expr->stokens); $i++) {
        $tag_ids = $token_tag_ids[$i];
        $token = $expr->stokens[$i];

        if (!empty($tag_ids)) {
            $query = '
SELECT image_id FROM '.IMAGE_TAG_TABLE.'
  WHERE tag_id IN ('.implode(',', $tag_ids).')
  GROUP BY image_id';
            $qsr->tag_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'image_id'));
            if ($expr->stoken_modifiers[$i] & QST_NOT) {
                $not_ids = array_merge($not_ids, $tag_ids);
            } else {
                if (strlen((string) $token->term) > 2 || count($expr->stokens) == 1 || isset($token->scope) || ($token->modifier & (QST_WILDCARD | QST_QUOTED))) {// add tag ids to list only if the word is not too short (such as de / la /les ...)
                    $positive_ids = array_merge($positive_ids, $tag_ids);
                }
            }
        } elseif (isset($token->scope) && 'tag' == $token->scope->id && strlen($token->term) == 0) {
            if ($token->modifier & QST_WILDCARD) {// eg. 'tag:*' returns all tagged images
                $qsr->tag_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT image_id FROM '.IMAGE_TAG_TABLE)->fetchAllAssociative(), 'image_id'));
            } else {// eg. 'tag:' returns all untagged images
                $qsr->tag_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery('SELECT id FROM '.IMAGES_TABLE.' LEFT JOIN '.IMAGE_TAG_TABLE.' ON id=image_id WHERE image_id IS NULL')->fetchAllAssociative(), 'id'));
            }
        }
    }

    $all_tags = array_intersect_key($all_tags, array_flip(array_diff($positive_ids, $not_ids)));
    usort($all_tags, tag_alpha_compare(...));
    foreach ($all_tags as &$tag) {
        $tag_name = trigger_change('render_tag_name', $tag['name'], $tag);
        $tag['name'] = is_string($tag_name) ? $tag_name : (is_scalar($tag_name) ? (string) $tag_name : '');
    }
    $qsr->all_tags = $all_tags;
    $qsr->tag_ids = $token_tag_ids;
}

function qsearch_get_categories(\Piwigo\Search\QExpression $expr, \Piwigo\Search\QResults $qsr): void
{
    $userId = \Piwigo\Users\CurrentUser::get()->id;

    $token_cat_ids = $qsr->cat_iids = array_fill(0, count($expr->stokens), []);
    $all_cats = [];

    for ($i = 0; $i < count($expr->stokens); $i++) {
        $token = $expr->stokens[$i];
        if (isset($token->scope) && 'category' != $token->scope->id) { // not relevant yet
            continue;
        }
        if (empty($token->term)) {
            continue;
        }

        $clauses = qsearch_get_text_token_search_sql($token, ['name', 'comment']);
        $query = 'SELECT * FROM ' . CATEGORIES_TABLE .
            ' INNER JOIN ' . USER_CACHE_CATEGORIES_TABLE . ' ON id = cat_id AND user_id = ' . $userId .
            ' WHERE (' . implode("\n OR ", $clauses) . ')';
        foreach (\Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
            ->executeQuery($query)->fetchAllAssociative() as $cat) {
            $cat_id = is_numeric($cat['id']) ? (int) $cat['id'] : 0;
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
                $token_cat_ids[$i] = $token_cat_ids[$i + 1] = array_values($common);
            }
        }
    }

    // get images
    $positive_ids = $not_ids = [];
    for ($i = 0; $i < count($expr->stokens); $i++) {
        $cat_ids = $token_cat_ids[$i];
        $token = $expr->stokens[$i];

        if (!empty($cat_ids)) {
            if (\Piwigo\Config\Config::quickSearchIncludeSubAlbums()) {
                $query = '
SELECT
    id
  FROM '.CATEGORIES_TABLE.'
    INNER JOIN '.USER_CACHE_CATEGORIES_TABLE.' ON id = cat_id and user_id = '.$userId.'
  WHERE id IN ('.implode(',', get_subcat_ids($cat_ids)) .')
;';
                $cat_ids = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'));
            }

            $query = '
SELECT image_id FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id IN ('.implode(',', $cat_ids).')
  GROUP BY image_id';
            $qsr->cat_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'image_id'));
            if ($expr->stoken_modifiers[$i] & QST_NOT) {
                $not_ids = array_merge($not_ids, $cat_ids);
            } else {
                if (strlen((string) $token->term) > 2 || count($expr->stokens) == 1 || isset($token->scope) || ($token->modifier & (QST_WILDCARD | QST_QUOTED))) {// add cat ids to list only if the word is not too short (such as de / la /les ...)
                    $positive_ids = array_merge($positive_ids, $cat_ids);
                }
            }
        } elseif (isset($token->scope) && 'category' == $token->scope->id && strlen($token->term) == 0) {
            if ($token->modifier & QST_WILDCARD) {// eg. 'category:*' returns all images associated to an album
                $qsr->cat_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT image_id FROM '.IMAGE_CATEGORY_TABLE)->fetchAllAssociative(), 'image_id'));
            } else {// eg. 'category:' returns all orphan images
                $qsr->cat_iids[$i] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery('SELECT id FROM '.IMAGES_TABLE.' LEFT JOIN '.IMAGE_CATEGORY_TABLE.' ON id=image_id WHERE image_id IS NULL')->fetchAllAssociative(), 'id'));
            }
        }
    }

    $all_cats = array_intersect_key($all_cats, array_flip(array_diff($positive_ids, $not_ids)));
    usort($all_cats, tag_alpha_compare(...));
    foreach ($all_cats as &$cat) {
        $cat_name = trigger_change('render_category_name', $cat['name'], $cat);
        $cat['name'] = is_string($cat_name) ? $cat_name : (is_scalar($cat_name) ? (string) $cat_name : '');
    }
    $qsr->all_cats = $all_cats;
    $qsr->cat_ids = $token_cat_ids;
}


/**
 * @param string[] $ignored_terms
 * @return int[]
 */
function qsearch_eval(\Piwigo\Search\QMultiToken $expr, \Piwigo\Search\QResults $qsr, bool &$qualifies, array &$ignored_terms): array
{
    $qualifies = false; // until we find at least one positive term
    $ignored_terms = [];

    $ids = $not_ids = [];
    $crt_qualifies = false;
    $crt_ignored_terms = [];

    for ($i = 0; $i < count($expr->tokens); $i++) {
        $crt = $expr->tokens[$i];
        $crt_ids = [];
        if ($crt instanceof \Piwigo\Search\QSingleToken) {
            $crt_ids = $qsr->iids[$crt->idx] = array_unique(
                array_merge(
                    $qsr->images_iids[$crt->idx],
                    $qsr->cat_iids[$crt->idx],
                    $qsr->tag_iids[$crt->idx]
                )
            );
            $crt_qualifies = count($crt_ids) > 0 || count($qsr->tag_ids[$crt->idx]) > 0;
            $crt_ignored_terms = $crt_qualifies ? [] : [(string)$crt];
        } elseif ($crt instanceof \Piwigo\Search\QMultiToken) {
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
 * @param string $q
 * @param array<mixed> $options
 * @return array<mixed>|null
 */
function get_quick_search_results(string $q, array $options)
{
    $persistent_cache = \Piwigo\Cache\PersistentCacheRegistry::current();
    $currentUser = \Piwigo\Users\CurrentUser::get();
    $cacheUpdate = is_scalar($currentUser->rawAttributes['cache_update_time'] ?? null)
        ? (string) $currentUser->rawAttributes['cache_update_time']
        : '';

    $cache_key = $persistent_cache->make_key([
      strtolower($q),
      \Piwigo\Config\Config::orderBy(),
      $currentUser->id, $cacheUpdate,
      isset($options['permissions']) ? (bool)$options['permissions'] : true,
      $options['images_where'] ?? '',
      ]);
    /** @var mixed $res */
    $res = false;
    $cache_hit = $persistent_cache->get($cache_key, $res);
    if ($cache_hit) {
        return is_array($res) ? $res : null;
    }

    $res = get_quick_search_results_no_cache($q, $options);

    $res_items = is_array($res['items'] ?? null) ? $res['items'] : [];
    if (count($res_items)) {// cache the results only if not empty - otherwise it is useless
        $persistent_cache->set($cache_key, $res, 300);
    }
    return $res;
}

/**
 * @see get_quick_search_results but without result caching
 */
/**
 * @param array<mixed> $options
 * @return array<mixed>
 */
function get_quick_search_results_no_cache(string $q, array $options): array
{
    $q = trim(stripslashes((string) $q));
    $search_results =
      [
        'items' => [],
        'qs' => ['q' => $q],
      ];

    $q = trigger_change('qsearch_pre', $q);

    $scopes = [];
    $scopes[] = new \Piwigo\Search\QSearchScope('tag', ['tags']);
    $scopes[] = new \Piwigo\Search\QSearchScope('photo', ['photos']);
    $scopes[] = new \Piwigo\Search\QSearchScope('file', ['filename']);
    $scopes[] = new \Piwigo\Search\QSearchScope('author', [], true);
    $scopes[] = new \Piwigo\Search\QNumericRangeScope('width', []);
    $scopes[] = new \Piwigo\Search\QNumericRangeScope('height', []);
    $scopes[] = new \Piwigo\Search\QNumericRangeScope('ratio', [], false, 0.001);
    $scopes[] = new \Piwigo\Search\QNumericRangeScope('size', []);
    $scopes[] = new \Piwigo\Search\QNumericRangeScope('filesize', []);
    $scopes[] = new \Piwigo\Search\QNumericRangeScope('hits', ['hit', 'visit', 'visits']);
    $scopes[] = new \Piwigo\Search\QNumericRangeScope('score', ['rating'], true);
    $scopes[] = new \Piwigo\Search\QNumericRangeScope('id', []);

    $createdDateAliases = ['taken', 'shot'];
    $postedDateAliases = ['added'];
    if (\Piwigo\Config\Config::calendarDatefield() == 'date_creation') {
        $createdDateAliases[] = 'date';
    } else {
        $postedDateAliases[] = 'date';
    }
    $scopes[] = new \Piwigo\Search\QDateRangeScope('created', $createdDateAliases, true);
    $scopes[] = new \Piwigo\Search\QDateRangeScope('posted', $postedDateAliases);

    // allow plugins to add their own scopes
    $scopes_result = trigger_change('qsearch_get_scopes', $scopes);
    $scopes = $scopes_result;
    $expression = new \Piwigo\Search\QExpression($q, $scopes);

    // get inflections for terms
    $lang_code = substr(get_default_language(), 0, 2);
    include_once(PHPWG_ROOT_PATH.'include/inflectors/InflectorInterface.php');
    include_once(PHPWG_ROOT_PATH.'include/inflectors/en.php');
    include_once(PHPWG_ROOT_PATH.'include/inflectors/fr.php');
    $inflector = match($lang_code) {
        'en' => new Inflector_en(),
        'fr' => new Inflector_fr(),
        default => null,
    };
    if ($inflector !== null) {
        foreach ($expression->stokens as $token) {
            if (isset($token->scope) && !$token->scope->is_text) {
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
    //var_export($expression);

    if (count($expression->stokens) == 0) {
        return $search_results;
    }
    $qsr = new \Piwigo\Search\QResults();
    qsearch_get_tags($expression, $qsr);
    qsearch_get_categories($expression, $qsr);
    qsearch_get_images($expression, $qsr);

    // allow plugins to evaluate their own scopes
    trigger_notify('qsearch_before_eval', $expression, $qsr);

    $tmp = false;
    $search_results['qs']['unmatched_terms'] = [];
    $ids = qsearch_eval($expression, $qsr, $tmp, $search_results['qs']['unmatched_terms']);

    $debug[] = "<!--\nparsed: ".htmlspecialchars((string)$expression);
    $debug[] = count($expression->stokens).' tokens';
    for ($i = 0; $i < count($expression->stokens); $i++) {
        $debug[] = htmlspecialchars((string) $expression->stokens[$i]).': '.count($qsr->tag_ids[$i]).' tags, '.count($qsr->tag_iids[$i]).' tiids, '.count($qsr->images_iids[$i]).' iiids, '.count($qsr->iids[$i]).' iids'
          .' modifier:'.dechex($expression->stoken_modifiers[$i])
          .(!empty($expression->stokens[$i]->variants) ? ' variants: '.htmlspecialchars(implode(', ', $expression->stokens[$i]->variants)) : '');
    }
    $debug[] = 'before perms '.count($ids);

    $search_results['qs']['matching_tags'] = $qsr->all_tags;
    $search_results['qs']['matching_cats'] = $qsr->all_cats;
    $search_results = trigger_change('qsearch_results', $search_results, $expression, $qsr);
    $extra_items = array_map(static fn (mixed $v): int => (int) $v, $search_results['items']);
    $ids = array_merge($ids, $extra_items);

    if (empty($ids)) {
        $debug[] = '-->';
        \Piwigo\Template\TemplateRegistry::current()->append('footer_elements', implode("\n", $debug));
        return $search_results;
    }

    $permissions = !isset($options['permissions']) ? true : $options['permissions'];

    $where_clauses = [];
    $where_clauses[] = 'i.id IN ('. implode(',', $ids) . ')';
    if (!empty($options['images_where'])) {
        $where_clauses[] = '('.(is_scalar($options['images_where']) ? (string) $options['images_where'] : '').')';
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
SELECT DISTINCT(id) FROM '.IMAGES_TABLE.' i';
    if ($permissions) {
        $query .= '
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON id = ic.image_id';
    }
    $query .= '
  WHERE '.implode("\n AND ", $where_clauses)."\n".
    \Piwigo\Config\Config::orderBy();

    $ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');

    $debug[] = count($ids).' final photo count -->';
    \Piwigo\Template\TemplateRegistry::current()->append('footer_elements', implode("\n", $debug));

    $search_results['items'] = $ids;
    return $search_results;
}

/**
 * Returns an array of 'items' corresponding to the search id.
 * It can be either a quick search or a regular search.
 *
 * @param int $search_id
 * @return array<mixed>
 */
/** @return array<mixed> */
function get_search_results(int|string $search_id, bool $super_order_by, ?string $images_where = ''): array
{
    $search = get_search_array($search_id);
    if (!isset($search['q'])) {
        return get_regular_search_results($search, $images_where);
    } else {
        $search_q = is_scalar($search['q']) ? (string) $search['q'] : '';
        return get_quick_search_results($search_q, ['super_order_by' => $super_order_by, 'images_where' => $images_where]) ?? [];
    }
}

/** @return string[]|null */
function split_allwords(string $raw_allwords): ?array
{
    $words = null;

    // we specify the list of characters to trim, to add the ".". We don't want to split words
    // on "." but on ". ", and we have to deal with trailing dots.
    $raw_allwords = trim($raw_allwords, " \n\r\t\v\x00.");

    if (!preg_match('/^\s*$/', $raw_allwords)) {
        $drop_char_match   = [';','&','(',')','<','>','`','\'','"','|',',','@','?','%','. ','[',']','{','}',':','\\','/','=','\'','!','*'];
        $drop_char_replace = [' ',' ',' ',' ',' ',' ', '', '', ' ',' ',' ',' ',' ',' ',' ' ,' ',' ',' ',' ',' ','' , ' ',' ',' ', ' ',' '];

        // Split words
        $split = preg_split(
            '/\s+/',
            str_replace(
                $drop_char_match,
                $drop_char_replace,
                $raw_allwords
            )
        );
        $words = $split !== false ? array_unique($split) : [];
    }

    return $words;
}

function get_available_search_uuid(): string
{
    $candidate = 'psk-'.date('Ymd').'-'.generate_key(10);
    $counter = \Piwigo\Core\ServiceLocator::get(\Piwigo\Search\SearchRepository::class)
        ->countByUuid($candidate);
    if (0 == $counter) {
        return $candidate;
    } else {
        return get_available_search_uuid();
    }
}

/**
 * @param array<mixed> $rules
 * @return array<mixed>
 */
function save_search(array $rules, int|string|null $forked_from = null): array
{
    $createdBy = \Piwigo\Users\CurrentUser::get()->id;

    $dbnow = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $search_uuid = get_available_search_uuid();

    single_insert(
        SEARCH_TABLE,
        [
        'rules' => serialize($rules),
        'created_on' => $dbnow,
        'created_by' => $createdBy,
        'search_uuid' => $search_uuid,
        'forked_from' => $forked_from,
    ]
    );

    if (!is_a_guest() and !is_generic()) {
        $rules_fields = is_array($rules['fields'] ?? null) ? $rules['fields'] : [];
        userprefs_update_param('gallery_search_filters', array_keys($rules_fields));
    }

    $url = make_index_url(
        [
        'section' => 'search',
        'search'  => $search_uuid,
    ]
    );

    return [$search_uuid, $url];
}
