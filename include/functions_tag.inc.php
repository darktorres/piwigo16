<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\tag
 */


/**
 * Returns the number of available tags for the connected user.
 *
 * @return int
 */
function get_nb_available_tags(): int
{
    $user = &$GLOBALS['user'];
    if (!is_array($user)) {
        $user = [];
    }
    if (!isset($user['nb_available_tags'])) {
        $user['nb_available_tags'] = count(get_available_tags());
        single_update(
            USER_CACHE_TABLE,
            ['nb_available_tags' => $user['nb_available_tags']],
            ['user_id' => \Piwigo\Users\CurrentUser::get()->id]
        );
    }
    $nb = $user['nb_available_tags'];
    return is_numeric($nb) ? (int) $nb : 0;
}

/**
 * Returns all available tags for the connected user (not sorted).
 * The returned list can be a subset of all existing tags due to permissions,
 * also tags with no images are not returned.
 *
 * @return array [id, name, counter, url_name]
 */
/**
 * @param int[] $tag_ids
 * @return array<mixed>
 */
function get_available_tags(array $tag_ids = []): array
{
    $persistent_cache = \Piwigo\Cache\PersistentCacheRegistry::current();
    $user = \Piwigo\Users\CurrentUser::get()->rawAttributes;

    $use_persistent_cache = true;

    // we can find top fatter tags among reachable images
    $query = '
SELECT tag_id, COUNT(DISTINCT(it.image_id)) AS counter
  FROM '.IMAGE_CATEGORY_TABLE.' ic
    INNER JOIN '.IMAGE_TAG_TABLE.' it
    ON ic.image_id=it.image_id
  WHERE 1=1
  '.get_sql_condition_FandF(
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
    AND tag_id IN ('.implode(',', $tag_ids).')
';
    }

    $query .= '
  GROUP BY tag_id
;';

    if ($use_persistent_cache) {
        $userId = \Piwigo\Users\CurrentUser::get()->id;
        $cacheUpdate = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';
        $cache_key = $persistent_cache->make_key(__FUNCTION__.$userId.$cacheUpdate);
        $tag_counters = [];
        if (!$persistent_cache->get($cache_key, $tag_counters)) {
            $tag_counters = query2array($query, 'tag_id', 'counter');
            $persistent_cache->set($cache_key, $tag_counters);
        }
    } else {
        $tag_counters = query2array($query, 'tag_id', 'counter');
    }

    if (!is_array($tag_counters) || empty($tag_counters)) {
        return [];
    }

    $repo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagRepository::class);
    $rows = count($tag_counters) < 1000
        ? $repo->findByIds(array_map('intval', array_keys($tag_counters)))
        : $repo->findAll();

    $tags = [];
    foreach ($rows as $row) {
        $row_id = is_numeric($row['id']) ? (int) $row['id'] : (is_scalar($row['id']) ? (string) $row['id'] : '');
        if (isset($tag_counters[$row_id])) {
            $row['counter'] = is_scalar($tag_counters[$row_id]) ? intval($tag_counters[$row_id]) : 0;
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
 * @return array [id, name, url_name]
 */
/** @return array<mixed> */
function get_all_tags(): array
{
    $rows = \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagRepository::class)->findAll();
    $tags = [];
    foreach ($rows as $row) {
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
 * @param array $tags at least [id, counter]
 * @return array [..., level]
 */
/**
 * @param array<mixed> $tags
 * @return array<mixed>
 */
function add_level_to_tags(array $tags): array
{
    if (count($tags) == 0) {
        return $tags;
    }

    $total_count = 0;

    foreach ($tags as $tag) {
        if (is_array($tag)) {
            $total_count += is_numeric($tag['counter']) ? (int) $tag['counter'] : 0;
        }
    }

    // average count of available tags will determine the level of each tag
    $tag_average_count = $total_count / count($tags);

    // tag levels threshold calculation: a tag with an average rate must have
    // the middle level.
    $threshold_of_level = [];
    for ($i = 1; $i < \Piwigo\Config\Config::tagsLevels(); $i++) {
        $threshold_of_level[$i] =
          2 * $i * $tag_average_count / \Piwigo\Config\Config::tagsLevels();
    }

    // display sorted tags
    foreach ($tags as &$tag) {
        if (!is_array($tag)) {
            continue;
        }
        $tag['level'] = 1;

        // based on threshold, determine current tag level
        for ($i = \Piwigo\Config\Config::tagsLevels() - 1; $i >= 1; $i--) {
            if ((is_numeric($tag['counter']) ? (int) $tag['counter'] : 0) > $threshold_of_level[$i]) {
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
 * @param string $extra_images_where_sql - optionally apply a sql where filter to retrieved images
 * @param string $order_by - optionally overwrite default photo order
 */
/**
 * @param int[]|int|string $tag_ids
 * @return int[]
 */
function get_image_ids_for_tags(array|int|string $tag_ids, string $mode = 'AND', ?string $extra_images_where_sql = '', ?string $order_by = '', bool $use_permissions = true): array
{
    if (!is_array($tag_ids)) {
        $tag_ids = [$tag_ids];
    }
    if (empty($tag_ids)) {
        return [];
    }

    $query = '
SELECT id
  FROM '.IMAGES_TABLE.' i ';

    if ($use_permissions) {
        $query .= '
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' ic ON id=ic.image_id';
    }

    $query .= '
    INNER JOIN '.IMAGE_TAG_TABLE.' it ON id=it.image_id
    WHERE tag_id IN ('.implode(',', $tag_ids).')';

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

    $query .= (empty($extra_images_where_sql) ? '' : " \nAND (".$extra_images_where_sql.')').'
  GROUP BY id';

    if ($mode == 'AND' and count($tag_ids) > 1) {
        $query .= '
  HAVING COUNT(DISTINCT tag_id)='.count($tag_ids);
    }
    $query .= "\n".(empty($order_by) ? \Piwigo\Config\Config::orderBy() : $order_by);

    return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, query2array($query, null, 'id'));
}

/**
 * Return a list of tags corresponding to given items.
 *
 * @param int[] $items
 * @param int $max_tags
 * @param int[] $excluded_tag_ids
 * @return array [id, name, counter, url_name]
 */
/**
 * @param array<mixed> $items
 * @param int[] $excluded_tag_ids
 * @return array<mixed>
 */
function get_common_tags(array $items, int $max_tags, array $excluded_tag_ids = []): array
{
    if (empty($items)) {
        return [];
    }
    $imageIds = array_map(static fn (mixed $v): int => is_scalar($v) ? (int) $v : 0, $items);
    $rows = \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagRepository::class)
        ->findCommonTags($imageIds, $max_tags, $excluded_tag_ids);
    $tags = [];
    foreach ($rows as $row) {
        $row['name'] = trigger_change('render_tag_name', $row['name'], $row);
        $tags[] = $row;
    }
    usort($tags, tag_alpha_compare(...));
    return $tags;
}

/**
 * Return a list of tags corresponding to any of ids, url_names or names.
 *
 * @param int[]|string[] $ids
 * @param string[] $url_names
 * @param string[] $names
 * @return array [id, name, url_name]
 */
/**
 * @param int[]|string[] $ids
 * @param string[] $url_names
 * @param string[] $names
 * @return array<mixed>
 */
function find_tags(array $ids = [], array $url_names = [], array $names = []): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagRepository::class)
        ->findByIdUrlOrName(
            array_map('intval', $ids),
            array_map('strval', $url_names),
            array_map('strval', $names)
        );
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function tags_id_compare(array $a, array $b): int
{
    return ($a['id'] < $b['id']) ? -1 : 1;
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function tags_counter_compare(array $a, array $b): int
{
    if ($a['counter'] == $b['counter']) {
        return tags_id_compare($a, $b);
    }

    return ($a['counter'] < $b['counter']) ? +1 : -1;
}
