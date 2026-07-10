<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Returns the number of available tags for the connected user.
 */
function get_nb_available_tags(): int
{
    /** @var array<string, mixed> $user */
    global $user;
    if (! isset($user['nb_available_tags'])) {
        $user['nb_available_tags'] = count(get_available_tags());
        single_update(
            USER_CACHE_TABLE,
            [
                'nb_available_tags' => $user['nb_available_tags'],
            ],
            [
                'user_id' => $user['id'],
            ]
        );
    }
    return is_numeric($user['nb_available_tags']) ? (int) $user['nb_available_tags'] : 0;
}

/**
 * Returns all available tags for the connected user (not sorted).
 * The returned list can be a subset of all existing tags due to permissions,
 * also tags with no images are not returned.
 *
 * @param array<int, int|string> $tag_ids
 * @return array<int, array<string, mixed>> [id, name, counter, url_name]
 */
function get_available_tags(array $tag_ids = []): array
{
    /**
     * @var \PersistentFileCache $persistent_cache
     * @var array<string, mixed> $user
     */
    global $persistent_cache, $user;

    $use_persistent_cache = true;

    // we can find top fatter tags among reachable images
    $query = '
SELECT tag_id, COUNT(DISTINCT(it.image_id)) AS counter
  FROM ' . IMAGE_CATEGORY_TABLE . ' ic
    INNER JOIN ' . IMAGE_TAG_TABLE . ' it
    ON ic.image_id=it.image_id
  WHERE 1=1
  ' . get_sql_condition_FandF(
        [
            'forbidden_categories' => 'category_id',
            'visible_categories' => 'category_id',
            'visible_images' => 'ic.image_id',
        ],
        ' AND '
    );

    if (count($tag_ids) > 0) {
        $use_persistent_cache = false;

        $query .= '
    AND tag_id IN (' . implode(',', $tag_ids) . ')
';
    }

    $query .= '
  GROUP BY tag_id
;';

    if ($use_persistent_cache) {
        $user_id = $user['id'] ?? null;
        $user_id = is_scalar($user_id) ? (string) $user_id : '';
        $user_cache_update_time = $user['cache_update_time'] ?? null;
        $user_cache_update_time = is_scalar($user_cache_update_time) ? (string) $user_cache_update_time : '';
        $cache_key = $persistent_cache->make_key(__FUNCTION__ . $user_id . $user_cache_update_time);
        if (! $persistent_cache->get($cache_key, $tag_counters)) {
            $tag_counters = query2array($query, 'tag_id', 'counter');
            $persistent_cache->set($cache_key, $tag_counters);
        }
    } else {
        $tag_counters = query2array($query, 'tag_id', 'counter');
    }

    // $persistent_cache->get()'s by-reference $value output param is
    // declared mixed (a cache hit could genuinely hold anything), so
    // narrow once here regardless of which branch above ran.
    $tag_counters = is_array($tag_counters) ? $tag_counters : [];

    if (empty($tag_counters)) {
        return [];
    }

    $query = '
SELECT *
  FROM ' . TAGS_TABLE;

    if (count($tag_counters) < 1000) {
        $query .= '
  WHERE id IN (' . implode(',', array_keys($tag_counters)) . ')
';
    }
    $result = pwg_query($query);

    $tags = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        if (! is_string($row['id'])) {
            continue;
        }
        if (isset($tag_counters[$row['id']])) {
            $counter_value = $tag_counters[$row['id']];
            $row['counter'] = is_scalar($counter_value) ? intval($counter_value) : 0;
            $row['name_raw'] = $row['name'];
            $row['name'] = trigger_change('render_tag_name', $row['name'], $row);
            $tags[] = $row;
        }
    }
    return $tags;
}

/**
 * Returns all tags even associated to no image.
 *
 * @return array<int, array<string, mixed>> [id, name, url_name]
 */
function get_all_tags(): array
{
    $query = '
SELECT *
  FROM ' . TAGS_TABLE . '
;';
    $result = pwg_query($query);
    $tags = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $row['name_raw'] = $row['name'];
        $row['name'] = trigger_change('render_tag_name', $row['name'], $row);
        $tags[] = $row;
    }

    usort($tags, tag_alpha_compare(...));

    return $tags;
}

/**
 * Giving a set of tags with a counter for each one, calculate the display
 * level of each tag.
 *
 * The level of each tag depends on the average count of tags. This
 * calculation method avoid having very different levels for tags having
 * nearly the same count when set are small.
 *
 * @param array<int, array<string, mixed>> $tags at least [id, counter]
 * @return array<int, array<string, mixed>> [..., level]
 */
function add_level_to_tags($tags): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (count($tags) == 0) {
        return $tags;
    }

    $total_count = 0;

    foreach ($tags as $tag) {
        $total_count += is_numeric($tag['counter']) ? (int) $tag['counter'] : 0;
    }

    // average count of available tags will determine the level of each tag
    $tag_average_count = $total_count / count($tags);

    // tag levels threshold calculation: a tag with an average rate must have
    // the middle level.
    // conf_default.inc.php sets this to 5; narrow once here and reuse the
    // local variable everywhere below instead of re-reading the mixed-typed
    // $conf offset (which would re-widen back to mixed at each site).
    $tags_levels = is_numeric($conf['tags_levels']) ? (int) $conf['tags_levels'] : 5;

    $threshold_of_level = [];
    for ($i = 1; $i < $tags_levels; $i++) {
        $threshold_of_level[$i] =
          2 * $i * $tag_average_count / $tags_levels;
    }

    // display sorted tags
    foreach ($tags as &$tag) {
        $tag['level'] = 1;

        // based on threshold, determine current tag level
        for ($i = $tags_levels - 1; $i >= 1; $i--) {
            if ($tag['counter'] > $threshold_of_level[$i]) {
                $tag['level'] = $i + 1;
                break;
            }
        }
    }
    unset($tag);

    return $tags;
}

/**
 * Return the list of image ids corresponding to given tags.
 * AND & OR mode supported.
 *
 * @param int[] $tag_ids
 * @param string $mode
 * @param string|null $extra_images_where_sql - optionally apply a sql where
 *   filter to retrieved images; null is treated the same as '' (both are
 *   empty() below), and admin/batch_manager.php passes null explicitly
 * @param string|null $order_by - optionally overwrite default photo order;
 *   null is treated the same as '' for the same reason
 * @return array<int|string, mixed>
 */
function get_image_ids_for_tags($tag_ids, $mode = 'AND', $extra_images_where_sql = '', $order_by = '', bool $use_permissions = true): array
{
    /** @var array<string, mixed> $conf */
    global $conf;
    if (empty($tag_ids)) {
        return [];
    }

    $query = '
SELECT id
  FROM ' . IMAGES_TABLE . ' i ';

    if ($use_permissions) {
        $query .= '
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON id=ic.image_id';
    }

    $query .= '
    INNER JOIN ' . IMAGE_TAG_TABLE . ' it ON id=it.image_id
    WHERE tag_id IN (' . implode(',', $tag_ids) . ')';

    if ($use_permissions) {
        $query .= get_sql_condition_FandF(
            [
                'forbidden_categories' => 'category_id',
                'visible_categories' => 'category_id',
                'visible_images' => 'id',
            ],
            "\n  AND"
        );
    }

    $query .= (empty($extra_images_where_sql) ? '' : " \nAND (" . $extra_images_where_sql . ')') . '
  GROUP BY id';

    if ($mode == 'AND' and count($tag_ids) > 1) {
        $query .= '
  HAVING COUNT(DISTINCT tag_id)=' . count($tag_ids);
    }
    $query .= "\n" . (empty($order_by) ? (is_string($conf['order_by']) ? $conf['order_by'] : '') : $order_by);

    return query2array($query, null, 'id');
}

/**
 * Return a list of tags corresponding to given items.
 *
 * @param int[] $items
 * @param int $max_tags
 * @param int[] $excluded_tag_ids
 * @return array<int, array<string, mixed>> [id, name, counter, url_name]
 */
function get_common_tags($items, $max_tags, $excluded_tag_ids = []): array
{
    if (empty($items)) {
        return [];
    }
    $query = '
SELECT t.*, count(*) AS counter
  FROM ' . IMAGE_TAG_TABLE . '
    INNER JOIN ' . TAGS_TABLE . ' t ON tag_id = id
  WHERE image_id IN (' . implode(',', $items) . ')';
    if (! empty($excluded_tag_ids)) {
        $query .= '
    AND tag_id NOT IN (' . implode(',', $excluded_tag_ids) . ')';
    }
    $query .= '
  GROUP BY t.id
  ORDER BY ';
    if ($max_tags > 0) { // TODO : why ORDER field is in the if ?
        $query .= 'counter DESC
  LIMIT ' . $max_tags;
    } else {
        $query .= 'NULL';
    }

    $result = pwg_query($query);
    $tags = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $row['name'] = trigger_change('render_tag_name', $row['name'], $row);
        $tags[] = $row;
    }
    usort($tags, tag_alpha_compare(...));
    return $tags;
}

/**
 * Return a list of tags corresponding to any of ids, url_names or names.
 *
 * @param array<int|string> $ids functions_url.inc.php's parse_section_url()
 *   passes raw preg_match() capture strings, never cast to int — only used
 *   in an implode() SQL context below, so numeric strings work identically
 * @param string[] $url_names
 * @param string[] $names
 * @return array<int|string, mixed> [id, name, url_name]
 */
function find_tags($ids = [], $url_names = [], $names = []): array
{
    $where_clauses = [];
    if (! empty($ids)) {
        $where_clauses[] = 'id IN (' . implode(',', $ids) . ')';
    }
    if (! empty($url_names)) {
        $where_clauses[] =
          'url_name IN (\'' . implode('\', \'', $url_names) . '\')';
    }
    if (! empty($names)) {
        $where_clauses[] =
          'name IN (\'' . implode('\', \'', $names) . '\')';
    }
    if (empty($where_clauses)) {
        return [];
    }

    $query = '
SELECT *
  FROM ' . TAGS_TABLE . '
  WHERE ' . implode('
    OR ', $where_clauses);

    return query2array($query);
}

/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function tags_id_compare(array $a, array $b): int
{
    return ($a['id'] < $b['id']) ? -1 : 1;
}

/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function tags_counter_compare(array $a, array $b): int
{
    if ($a['counter'] == $b['counter']) {
        return tags_id_compare($a, $b);
    }

    return ($a['counter'] < $b['counter']) ? +1 : -1;
}
