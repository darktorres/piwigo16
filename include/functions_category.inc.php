<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Category\CategoryService;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\category
 */

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function global_rank_compare(array $a, array $b): int
{
    return ServiceLocator::get(CategoryService::class)->globalRankCompare($a, $b);
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function rank_compare(array $a, array $b): int
{
    return ServiceLocator::get(CategoryService::class)->rankCompare($a, $b);
}

function check_restrictions(int $category_id): void
{
    ServiceLocator::get(CategoryService::class)->checkRestrictions($category_id);
}

/** @return array<mixed> */
function get_categories_menu(): array
{
    return ServiceLocator::get(CategoryService::class)->getCategoriesMenu();
}

/** @return array<mixed>|null */
function get_cat_info(int|string $id): ?array
{
    return ServiceLocator::get(CategoryService::class)->getCatInfo($id);
}

/** @return array<mixed> */
function get_category_preferred_image_orders(): array
{
    return ServiceLocator::get(CategoryService::class)->getCategoryPreferredImageOrders();
}

/**
 * @param list<array<string, mixed>> $categories
 * @param int[]|string               $selecteds
 */
function display_select_categories(array $categories, array|string $selecteds, string $blockname, bool|string $fullname = true): void
{
    ServiceLocator::get(CategoryService::class)->displaySelectCategories($categories, $selecteds, $blockname, $fullname);
}

/** @param int[]|string $selecteds */
function display_select_cat_wrapper(string $query, array|string $selecteds, string $blockname, bool|string $fullname = true): void
{
    ServiceLocator::get(CategoryService::class)->displaySelectCatWrapper($query, $selecteds, $blockname, $fullname);
}

/**
 * @param array<int|string>|int|string $ids
 * @return array<int>
 */
function get_subcat_ids(array|int|string $ids): array
{
    return ServiceLocator::get(CategoryService::class)->getSubcatIds($ids);
}

/** @param string[] $permalinks */
function get_cat_id_from_permalinks(array $permalinks, int &$idx): ?int
{
    return ServiceLocator::get(CategoryService::class)->getCatIdFromPermalinks($permalinks, $idx);
}

function get_display_images_count(mixed $cat_nb_images, mixed $cat_count_images, mixed $cat_count_categories, bool|string $short_message = true, string $separator = '\n'): string
{
    return ServiceLocator::get(CategoryService::class)->getDisplayImagesCount(
        is_numeric($cat_nb_images) ? (int) $cat_nb_images : 0,
        is_numeric($cat_count_images) ? (int) $cat_count_images : 0,
        is_numeric($cat_count_categories) ? (int) $cat_count_categories : 0,
        $short_message,
        $separator
    );
}

/** @param array<string, mixed> $category */
function get_random_image_in_category(array $category, bool $recursive = true): ?int
{
    return ServiceLocator::get(CategoryService::class)->getRandomImageInCategory($category, $recursive);
}

/**
 * @param array<string, mixed>               $userdata
 * @return array<string, array<string, mixed>>
 */
function get_computed_categories(array &$userdata, ?int $filter_days = null): array
{
    return ServiceLocator::get(CategoryService::class)->getComputedCategories($userdata, $filter_days);
}

/**
 * @param array<string, array<string, mixed>> $cats
 * @param array<string, mixed>                $cat
 */
function remove_computed_category(array &$cats, array $cat): void
{
    ServiceLocator::get(CategoryService::class)->removeComputedCategory($cats, $cat);
}

/**
 * @param int[]|int|string $cat_ids
 * @return int[]
 */
function get_image_ids_for_categories(array|int|string $cat_ids, string $mode = 'AND', ?string $extra_images_where_sql = '', string $order_by = '', bool $use_permissions = true): array
{
    return ServiceLocator::get(CategoryService::class)->getImageIdsForCategories($cat_ids, $mode, $extra_images_where_sql, $order_by, $use_permissions);
}

/**
 * @param array<mixed>  $items
 * @param int[]         $excluded_cat_ids
 * @return array<string, array<string, mixed>>
 */
function get_common_categories(array $items, ?int $max = null, array $excluded_cat_ids = [], bool $use_permissions = true): array
{
    return ServiceLocator::get(CategoryService::class)->getCommonCategories($items, $max, $excluded_cat_ids, $use_permissions);
}

/**
 * @param array<mixed>  $items
 * @param int[]         $excluded_cat_ids
 * @return array<mixed>
 */
function get_related_categories_menu(array $items, array $excluded_cat_ids = []): array
{
    return ServiceLocator::get(CategoryService::class)->getRelatedCategoriesMenu($items, $excluded_cat_ids);
}
