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

function get_nb_available_tags(): int
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagService::class)->getNbAvailableTags();
}

/**
 * @param int[] $tag_ids
 * @return array<mixed>
 */
function get_available_tags(array $tag_ids = []): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagService::class)->getAvailableTags($tag_ids);
}

/** @return array<mixed> */
function get_all_tags(): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagService::class)->getAllTags();
}

/**
 * @param array<mixed> $tags
 * @return array<mixed>
 */
function add_level_to_tags(array $tags): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagService::class)->addLevelToTags($tags);
}

/**
 * @param int[]|int|string $tag_ids
 * @return int[]
 */
function get_image_ids_for_tags(array|int|string $tag_ids, string $mode = 'AND', ?string $extra_images_where_sql = '', ?string $order_by = '', bool $use_permissions = true): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagService::class)->getImageIdsForTags($tag_ids, $mode, $extra_images_where_sql, $order_by, $use_permissions);
}

/**
 * @param array<mixed> $items
 * @param int[]        $excluded_tag_ids
 * @return array<mixed>
 */
function get_common_tags(array $items, int $max_tags, array $excluded_tag_ids = []): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagService::class)->getCommonTags($items, $max_tags, $excluded_tag_ids);
}

/**
 * @param int[]|string[] $ids
 * @param string[]       $url_names
 * @param string[]       $names
 * @return array<mixed>
 */
function find_tags(array $ids = [], array $url_names = [], array $names = []): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagService::class)->findTags($ids, $url_names, $names);
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function tags_id_compare(array $a, array $b): int
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagService::class)->tagsIdCompare($a, $b);
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function tags_counter_compare(array $a, array $b): int
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Tag\TagService::class)->tagsCounterCompare($a, $b);
}
