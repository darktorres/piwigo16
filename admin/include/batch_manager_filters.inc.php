<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new \Piwigo\Exception\AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang, $collection, $base_url;


$prefilters = [
  ['ID' => 'caddie', 'NAME' => l10n('Caddie')],
  ['ID' => 'favorites', 'NAME' => l10n('Your favorites')],
  ['ID' => 'last_import', 'NAME' => l10n('Last import')],
  ['ID' => 'no_album', 'NAME' => l10n('With no album') . ' (' . l10n('Orphans') . ')'],
  ['ID' => 'no_tag', 'NAME' => l10n('With no tag')],
  ['ID' => 'duplicates', 'NAME' => l10n('Duplicates')],
  ['ID' => 'all_photos', 'NAME' => l10n('All')],
];

if (\Piwigo\Config\Config::enableSynchronization()) {
    $prefilters[] = ['ID' => 'no_virtual_album', 'NAME' => l10n('With no virtual album')];
    $prefilters[] = ['ID' => 'no_sync_md5sum', 'NAME' => l10n('With no checksum')];
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function UC_name_compare(array $a, array $b): int
{
    $aName = is_scalar($a['NAME']) ? (string) $a['NAME'] : '';
    $bName = is_scalar($b['NAME']) ? (string) $b['NAME'] : '';
    return strcmp(strtolower($aName), strtolower($bName));
}

$prefilters = trigger_change('get_batch_manager_prefilters', $prefilters);

// Sort prefilters by localized name.
usort($prefilters, fn (array $a, array $b): int => strcmp(strtolower((string) $a['NAME']), strtolower((string) $b['NAME'])));

$template->assign(
    [
    'conf_checksum_compute_blocksize' => \Piwigo\Config\Config::checksumComputeBlocksize(),
    'prefilters' => $prefilters,
    'filter' => $_SESSION['bulk_manager_filter'],
    'selection' => $collection,
    'all_elements' => $page['cat_elements_id'],
    'START' => $page['start'],
    'PWG_TOKEN' => get_pwg_token(),
    'U_DISPLAY' => $base_url . get_query_string_diff(['display']),
    'F_ACTION' => $base_url . get_query_string_diff(['cat', 'start', 'tag', 'filter']),
    'ADMIN_PAGE_TITLE' => l10n('Batch Manager'),
  ]
);

if (isset($page['no_md5sum_number'])) {
    $template->assign(
        [
        'NB_NO_MD5SUM' => $page['no_md5sum_number'],
    ]
    );
} else {
    $template->assign('NB_NO_MD5SUM', '');
}

// privacy level
$level_options = [];
foreach (\Piwigo\Config\Config::availablePermissionLevels() as $level) {
    $level_options[$level] = l10n(sprintf('Level %d', $level));

    if (0 == $level) {
        $level_options[$level] = l10n('Everybody');
    }
}
$bulk_manager_filter = is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];
$template->assign(
    [
    'filter_level_options' => $level_options,
    'filter_level_options_selected' => $bulk_manager_filter['level'] ?? 0,
  ]
);

// tags
$filter_tags = [];

if (!empty($bulk_manager_filter['tags'])) {
    $filter_tags_ids = is_array($bulk_manager_filter['tags']) ? $bulk_manager_filter['tags'] : [];
    $query = '
SELECT
    id,
    name
  FROM ' . TAGS_TABLE . '
  WHERE id IN (' . implode(',', array_map(fn ($v) => is_scalar($v) ? (string) $v : '0', $filter_tags_ids)) . ')
;';

    $filter_tags = get_taglist($query);
}

$template->assign('filter_tags', $filter_tags);

// in the filter box, which category to select by default
$selected_category = null;
$selected_category_name = '';

if (isset($bulk_manager_filter['category'])) {
    $selected_category = is_numeric($bulk_manager_filter['category']) ? (int) $bulk_manager_filter['category'] : 0;
    $selected_category_name = get_cat_display_name_from_id($selected_category);
}

$template->assign('filter_category_selected_name', strip_tags($selected_category_name));
$template->assign('filter_category_selected', $selected_category);

// Dissociate from a category : categories listed for dissociation can only
// represent virtual links. We can't create orphans. Links to physical
// categories can't be broken.
$associated_categories = [];

if (count($page['cat_elements_id']) > 0) {
    $query = '
SELECT
    DISTINCT(category_id) AS id
  FROM ' . IMAGE_CATEGORY_TABLE . ' AS ic
    JOIN ' . IMAGES_TABLE . ' AS i ON i.id = ic.image_id
  WHERE ic.image_id IN (' . implode(',', $page['cat_elements_id']) . ')
    AND (
      ic.category_id != i.storage_category_id
      OR i.storage_category_id IS NULL
    )
;';

    $associated_categories = \Piwigo\Db\QueryHelper::fetch($query, 'id', 'id');
}

$template->assign('associated_categories', $associated_categories);

load_language('help_quick_search.lang');
