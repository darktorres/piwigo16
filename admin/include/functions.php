<?php

declare(strict_types=1);

use Piwigo\Admin\AdminService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Core\ServiceLocator;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

include_once(PHPWG_ROOT_PATH . 'admin/include/functions_metadata.php');

// ── CategoryAdminService delegates ───────────────────────────────────────

function delete_site(mixed $id): void
{
    ServiceLocator::get(CategoryAdminService::class)->deleteSite($id);
}

/** @param int[] $ids */
function delete_categories(array $ids, string $photo_deletion_mode = 'no_delete'): void
{
    ServiceLocator::get(CategoryAdminService::class)->deleteCategories($ids, $photo_deletion_mode);
}

function images_integrity(): void
{
    ServiceLocator::get(CategoryAdminService::class)->imagesIntegrity();
}

/** @param 'all'|int|int[]|string[] $ids */
function update_category(array|string|int $ids = 'all'): void
{
    ServiceLocator::get(CategoryAdminService::class)->updateCategory($ids);
}

function categories_integrity(): void
{
    ServiceLocator::get(CategoryAdminService::class)->categoriesIntegrity();
}

/** @param array<mixed> $categories */
function save_categories_order(array $categories): void
{
    ServiceLocator::get(CategoryAdminService::class)->saveCategoriesOrder($categories);
}

function update_global_rank(): int
{
    return ServiceLocator::get(CategoryAdminService::class)->updateGlobalRank();
}

/** @param int[]|int|string $categories */
function set_cat_visible(array|int|string $categories, bool|string $value, bool $unlock_child = false): void
{
    ServiceLocator::get(CategoryAdminService::class)->setCatVisible($categories, $value, $unlock_child);
}

/** @param int[]|int|string $categories */
function set_cat_status(array|int|string $categories, string $value): void
{
    ServiceLocator::get(CategoryAdminService::class)->setCatStatus($categories, $value);
}

/**
 * @param array<int|string>|int|string $cat_ids
 * @return array<string>
 */
function get_uppercat_ids(array|int|string $cat_ids): array
{
    return ServiceLocator::get(CategoryAdminService::class)->getUppercatIds($cat_ids);
}

/** @return array<mixed> */
function get_category_representant_properties(string $image_id, ?string $size = null): array
{
    return ServiceLocator::get(ImageAdminService::class)->getCategoryRepresentantProperties($image_id, $size);
}

/** @param int[]|int $categories */
function set_random_representant(array|int $categories): void
{
    ServiceLocator::get(CategoryAdminService::class)->setRandomRepresentant($categories);
}

/**
 * @param int[]|int|string $cat_ids
 * @return string[]
 */
function get_fulldirs(array|int|string $cat_ids): array
{
    return ServiceLocator::get(CategoryAdminService::class)->getFulldirs($cat_ids);
}

#[\Deprecated(message: '2.4')]
function get_fs(string $path, mixed $recursive = true): mixed
{
    return ServiceLocator::get(ImageAdminService::class)->getFs($path, (bool) $recursive);
}

function sync_users(): void
{
    ServiceLocator::get(UserAdminService::class)->syncUsers();
}

function update_uppercats(): void
{
    ServiceLocator::get(CategoryAdminService::class)->updateUppercats();
}

function update_path(): void
{
    ServiceLocator::get(CategoryAdminService::class)->updatePath();
}

function move_categories(mixed $category_ids, mixed $new_parent = -1): void
{
    ServiceLocator::get(CategoryAdminService::class)->moveCategories($category_ids, $new_parent);
}

/**
 * @param array<mixed> $options
 * @return array<mixed>
 */
function create_virtual_category(string $category_name, int|string|null $parent_id = null, array $options = []): array
{
    return ServiceLocator::get(CategoryAdminService::class)->createVirtualCategory($category_name, $parent_id, $options);
}

function set_tags(mixed $tags, mixed $image_id): void
{
    ServiceLocator::get(TagAdminService::class)->setTags($tags, $image_id);
}

function add_tags(mixed $tags, mixed $images): void
{
    ServiceLocator::get(TagAdminService::class)->addTags($tags, $images);
}

/** @param int[]|int $tag_ids */
function delete_tags(array|int $tag_ids): void
{
    ServiceLocator::get(TagAdminService::class)->deleteTags($tag_ids);
}

function tag_id_from_tag_name(string $tag_name): int|string
{
    return ServiceLocator::get(TagAdminService::class)->tagIdFromTagName($tag_name);
}

/** @param array<int, int[]|string[]> $tags_of */
function set_tags_of(array $tags_of): void
{
    ServiceLocator::get(TagAdminService::class)->setTagsOf($tags_of);
}

/**
 * @param int[] $image_ids
 * @return array<mixed>
 */
function get_image_tag_ids(array $image_ids): array
{
    return ServiceLocator::get(TagAdminService::class)->getImageTagIds($image_ids);
}

/**
 * @param array<mixed> $taglist_before
 * @param array<mixed> $taglist_after
 * @return array<mixed>
 */
function compare_image_tag_lists(array $taglist_before, array $taglist_after): array
{
    return ServiceLocator::get(TagAdminService::class)->compareImageTagLists($taglist_before, $taglist_after);
}

/**
 * @param int[] $images
 * @param int[]|null $categories
 */
function fill_lounge(array $images, ?array $categories): void
{
    ServiceLocator::get(CategoryAdminService::class)->fillLounge($images, $categories);
}

/** @return array<mixed>|null */
function empty_lounge(bool $invalidate_user_cache = true): ?array
{
    return ServiceLocator::get(CategoryAdminService::class)->emptyLounge($invalidate_user_cache);
}

/**
 * @param int[] $images
 * @param int[] $categories
 */
function associate_images_to_categories(array $images, array $categories): void
{
    ServiceLocator::get(CategoryAdminService::class)->associateImagesToCategories($images, $categories);
}

function dissociate_images_from_category(mixed $images, string $category): int
{
    return ServiceLocator::get(CategoryAdminService::class)->dissociateImagesFromCategory($images, $category);
}

/**
 * @param int[] $images
 * @param int[] $categories
 */
function move_images_to_categories(array $images, array $categories): bool
{
    return ServiceLocator::get(CategoryAdminService::class)->moveImagesToCategories($images, $categories);
}

/**
 * @param int[] $sources
 * @param int[] $destinations
 */
function associate_categories_to_categories(array $sources, array $destinations): void
{
    ServiceLocator::get(CategoryAdminService::class)->associateCategoriesToCategories($sources, $destinations);
}

// ── AdminService delegates ────────────────────────────────────────────────

/** @return string[] */
function pwg_URL(): array
{
    return ServiceLocator::get(AdminService::class)->pwgURL();
}

function invalidate_user_cache(bool $full = true): void
{
    ServiceLocator::get(UserAdminService::class)->invalidateUserCache($full);
}

function invalidate_user_cache_nb_tags(): void
{
    ServiceLocator::get(UserAdminService::class)->invalidateUserCacheNbTags();
}

function create_table_add_character_set(mixed $query): string
{
    return ServiceLocator::get(AdminService::class)->createTableAddCharacterSet($query);
}

/** @return array<int,string> */
function get_user_access_level_html_options(int $MinLevelAccess = ACCESS_FREE, int $MaxLevelAccess = ACCESS_CLOSED): array
{
    return ServiceLocator::get(UserAdminService::class)->getUserAccessLevelHtmlOptions($MinLevelAccess, $MaxLevelAccess);
}

/** @return string[] */
function get_extents(mixed $start = ''): array
{
    return ServiceLocator::get(AdminService::class)->getExtents(is_scalar($start) ? (string) $start : '');
}

/** @return array<mixed> */
function create_tag(string $tag_name): array
{
    return ServiceLocator::get(TagAdminService::class)->createTag($tag_name);
}

function cat_admin_access(mixed $category_id): bool
{
    return ServiceLocator::get(UserAdminService::class)->catAdminAccess($category_id);
}

/**
 * @param array<mixed> $get_data
 * @param array<mixed> $post_data
 * @param-out string $dest
 */
function fetchRemote(string $src, mixed &$dest, array $get_data = [], array $post_data = [], string $user_agent = 'Piwigo', int $step = 0): bool
{
    return ServiceLocator::get(AdminService::class)->fetchRemote($src, $dest, $get_data, $post_data, $user_agent, $step);
}

function get_groupname(mixed $group_id): string|false
{
    return ServiceLocator::get(UserAdminService::class)->getGroupname($group_id);
}

/**
 * @param int[]|int $group_ids
 * @return array<int|string, mixed>|false
 */
function delete_groups(array|int $group_ids): false|array
{
    return ServiceLocator::get(UserAdminService::class)->deleteGroups($group_ids);
}

function get_username(mixed $user_id): false|string
{
    return ServiceLocator::get(UserAdminService::class)->getUsername($user_id);
}

function get_newsletter_subscribe_base_url(mixed $language = 'en_UK'): string
{
    return ServiceLocator::get(AdminService::class)->getNewsletterSubscribeBaseUrl(is_scalar($language) ? (string) $language : 'en_UK');
}

function get_old_newsletters_base_url(mixed $language = 'en_UK'): string
{
    return ServiceLocator::get(AdminService::class)->getOldNewslettersBaseUrl(is_scalar($language) ? (string) $language : 'en_UK');
}

function get_active_menu(mixed $menu_page): int
{
    return ServiceLocator::get(AdminService::class)->getActiveMenu($menu_page);
}

/** @return array<mixed> */
function get_taglist(string $query, bool $only_user_language = true): array
{
    return ServiceLocator::get(TagAdminService::class)->getTaglist($query, $only_user_language);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function get_taglist_from_rows(array $rows, bool $only_user_language = true): array
{
    return ServiceLocator::get(TagAdminService::class)->getTaglistFromRows($rows, $only_user_language);
}

/** @return int[] */
function get_tag_ids(mixed $raw_tags, mixed $allow_create = true): array
{
    return ServiceLocator::get(TagAdminService::class)->getTagIds($raw_tags, (bool) $allow_create);
}

/**
 * @param int[] $element_ids
 * @param string[] $name
 * @return int[]
 */
function order_by_name(array $element_ids, array $name): array
{
    return ServiceLocator::get(AdminService::class)->orderByName($element_ids, $name);
}

/**
 * @param int[]|int|string $category_ids
 * @param int[]|int|string $user_ids
 */
function add_permission_on_category(array|int|string $category_ids, array|int|string $user_ids): void
{
    ServiceLocator::get(CategoryAdminService::class)->addPermissionOnCategory($category_ids, $user_ids);
}

/** @return int[] */
function get_admins(mixed $include_webmaster = true): array
{
    return ServiceLocator::get(UserAdminService::class)->getAdmins((bool) $include_webmaster);
}

function clear_derivative_cache(mixed $types = 'all'): void
{
    ServiceLocator::get(ImageAdminService::class)->clearDerivativeCache($types);
}

function clear_derivative_cache_rec(string $path, string $pattern): bool
{
    return ServiceLocator::get(ImageAdminService::class)->clearDerivativeCacheRec($path, $pattern);
}

/** @param array<mixed> $infos */
function delete_element_derivatives(array $infos, string|int $type = 'all'): void
{
    ServiceLocator::get(ImageAdminService::class)->deleteElementDerivatives($infos, $type);
}

/** @return string[] */
function get_dirs(string $directory): array
{
    return ServiceLocator::get(AdminService::class)->getDirs($directory);
}

function deltree(string $path, ?string $trash_path = null): bool
{
    return ServiceLocator::get(AdminService::class)->deltree($path, $trash_path);
}

/**
 * @param string[] $requested
 * @return array<mixed>
 */
function get_admin_client_cache_keys(array $requested = []): array
{
    return ServiceLocator::get(AdminService::class)->getAdminClientCacheKeys($requested);
}

/** @return int[] */
function get_photos_no_md5sum(): array
{
    return ServiceLocator::get(ImageAdminService::class)->getPhotosNoMd5sum();
}

/** @param int[]|string $ids */
function add_md5sum(array|string $ids): int
{
    return ServiceLocator::get(ImageAdminService::class)->addMd5sum($ids);
}

function count_orphans(): int
{
    return ServiceLocator::get(ImageAdminService::class)->countOrphans();
}

/** @return int[] */
function get_orphans(): array
{
    return ServiceLocator::get(ImageAdminService::class)->getOrphans();
}

function save_images_order(mixed $category_id, mixed $images): void
{
    ServiceLocator::get(CategoryAdminService::class)->saveImagesOrder($category_id, $images);
}

/** @param int[] $image_ids */
function update_images_lastmodified(array $image_ids): void
{
    ServiceLocator::get(ImageAdminService::class)->updateImagesLastmodified($image_ids);
}

function number_format_human_readable(mixed $numbers): string
{
    return ServiceLocator::get(AdminService::class)->numberFormatHumanReadable($numbers);
}

/** @return array<string,mixed>|null */
function get_image_infos(int|string $image_id, bool $die_on_missing = false): ?array
{
    return ServiceLocator::get(ImageAdminService::class)->getImageInfos($image_id, $die_on_missing);
}

/** @return array<string, int|float> */
function get_cache_size_derivatives(string $path): array
{
    return ServiceLocator::get(ImageAdminService::class)->getCacheSizeDerivatives($path);
}

function fs_quick_check(): void
{
    ServiceLocator::get(ImageAdminService::class)->fsQuickCheck();
}

/** @return array<mixed> */
function get_piwigo_news(): array
{
    return ServiceLocator::get(AdminService::class)->getPiwigoNews();
}

function get_graphics_library(): string
{
    return ServiceLocator::get(AdminService::class)->getGraphicsLibrary();
}

function get_graphics_library_label(): string
{
    return ServiceLocator::get(AdminService::class)->getGraphicsLibraryLabel();
}

/** @return array<string,mixed> */
function get_pwg_general_statitics(): array
{
    return ServiceLocator::get(AdminService::class)->getPwgGeneralStatitics();
}

function get_installation_date(): ?string
{
    return ServiceLocator::get(AdminService::class)->getInstallationDate();
}

/** @return string[] */
function get_fs_directories(string $path, bool $recursive = true): array
{
    return ServiceLocator::get(ImageAdminService::class)->getFsDirectories($path, $recursive);
}

function delete_user(mixed $user_id): void
{
    ServiceLocator::get(UserAdminService::class)->deleteUser($user_id);
}

/**
 * @param int[] $ids
 * @return int[]
 */
function delete_element_files(array $ids): array
{
    return ServiceLocator::get(ImageAdminService::class)->deleteElementFiles($ids);
}

function delete_elements(mixed $ids, bool $physical_deletion = false): int
{
    $idsArr = is_array($ids) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ids) : [];
    return ServiceLocator::get(ImageAdminService::class)->deleteElements($idsArr, $physical_deletion);
}

function delete_orphan_tags(): void
{
    ServiceLocator::get(TagAdminService::class)->deleteOrphanTags();
}

/** @return array<mixed> */
function get_orphan_tags(): array
{
    return ServiceLocator::get(TagAdminService::class)->getOrphanTags();
}
