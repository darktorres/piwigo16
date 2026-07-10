<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals. $base_url/$collection are set by the two including
// batch_manager_*.php controllers; the rest by include/common.inc.php.
/**
 * @var string $base_url
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var \Template $template
 */
global $base_url, $collection, $conf, $page, $template;

// Locally-typed snapshot of $_SESSION['bulk_manager_filter']. It is always
// written as an array by admin/batch_manager.php (which includes this file,
// via batch_manager_global.php / batch_manager_unit.php), before this file
// runs; this guards against corrupted/foreign session state and lets
// PHPStan track a real array shape for the reads below (this file never
// writes to $_SESSION['bulk_manager_filter']).
/** @var array<string, mixed> $bulk_manager_filter */
$bulk_manager_filter = isset($_SESSION['bulk_manager_filter']) && is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];

// $page['cat_elements_id'] is always a list of scalar image ids, and
// $page['start'] is always set (0 or a validated numeric $_REQUEST value),
// set by admin/batch_manager.php (which includes this file, via
// batch_manager_global.php / batch_manager_unit.php) before this file runs;
// PHPStan cannot see across that include boundary, so we narrow both once
// here for every use below.
$cat_elements_id = is_array($page['cat_elements_id'])
    ? array_map('intval', array_filter($page['cat_elements_id'], 'is_numeric'))
    : [];
$page_start = is_numeric($page['start']) ? (int) $page['start'] : 0;

$prefilters = [
    [
        'ID' => 'caddie',
        'NAME' => l10n('Caddie'),
    ],
    [
        'ID' => 'favorites',
        'NAME' => l10n('Your favorites'),
    ],
    [
        'ID' => 'last_import',
        'NAME' => l10n('Last import'),
    ],
    [
        'ID' => 'no_album',
        'NAME' => l10n('With no album') . ' (' . l10n('Orphans') . ')',
    ],
    [
        'ID' => 'no_tag',
        'NAME' => l10n('With no tag'),
    ],
    [
        'ID' => 'duplicates',
        'NAME' => l10n('Duplicates'),
    ],
    [
        'ID' => 'all_photos',
        'NAME' => l10n('All'),
    ],
];

if ($conf['enable_synchronization']) {
    $prefilters[] = [
        'ID' => 'no_virtual_album',
        'NAME' => l10n('With no virtual album'),
    ];
    $prefilters[] = [
        'ID' => 'no_sync_md5sum',
        'NAME' => l10n('With no checksum'),
    ];
}

/**
 * usort()'s callable contract requires accepting any array key type (not
 * just string), even though $prefilters entries are always string-keyed
 * in practice -- narrowing the @param here would make this incompatible
 * with usort's expected callable(array<mixed>, array<mixed>): int shape.
 *
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function UC_name_compare(array $a, array $b): int
{
    $a_name = is_string($a['NAME']) ? $a['NAME'] : '';
    $b_name = is_string($b['NAME']) ? $b['NAME'] : '';

    return strcmp(strtolower($a_name), strtolower($b_name));
}

$changed_prefilters = trigger_change('get_batch_manager_prefilters', $prefilters);

// Plugins may return anything from this modifier event; only accept a real
// array of arrays back, otherwise keep the built-in prefilter list above.
if (is_array($changed_prefilters)) {
    $prefilters = array_filter($changed_prefilters, 'is_array');
}

// Sort prefilters by localized name.
usort($prefilters, 'UC_name_compare');

$template->assign(
    [
        'conf_checksum_compute_blocksize' => $conf['checksum_compute_blocksize'],
        'prefilters' => $prefilters,
        'filter' => $bulk_manager_filter,
        'selection' => $collection,
        'all_elements' => $cat_elements_id,
        'START' => $page_start,
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
$available_permission_levels = $conf['available_permission_levels'];
$available_permission_levels = is_array($available_permission_levels) ? $available_permission_levels : [];

$level_options = [];
foreach ($available_permission_levels as $level) {
    // config_default.inc.php seeds this as [0, 1, 2, 4, 8] (always int); a
    // non-int entry would come from a broken custom config override and has
    // no meaningful privacy level to render.
    if (! is_int($level)) {
        continue;
    }

    $level_options[$level] = l10n(sprintf('Level %d', $level));

    if ($level == 0) {
        $level_options[$level] = l10n('Everybody');
    }
}
$template->assign(
    [
        'filter_level_options' => $level_options,
        'filter_level_options_selected' => $bulk_manager_filter['level']
          ?? 0,
    ]
);

// tags
$filter_tags = [];

if (! empty($bulk_manager_filter['tags']) && is_array($bulk_manager_filter['tags'])) {
    $filter_tags_ids = array_filter($bulk_manager_filter['tags'], 'is_scalar');

    $query = '
SELECT
    id,
    name
  FROM ' . TAGS_TABLE . '
  WHERE id IN (' . implode(',', $filter_tags_ids) . ')
;';

    $filter_tags = get_taglist($query);
}

$template->assign('filter_tags', $filter_tags);

// in the filter box, which category to select by default
$selected_category = null;
$selected_category_name = '';

if (isset($bulk_manager_filter['category']) && is_numeric($bulk_manager_filter['category'])) {
    $selected_category = intval($bulk_manager_filter['category']);
    $selected_category_name = get_cat_display_name_from_id($selected_category);
}

$template->assign('filter_category_selected_name', strip_tags($selected_category_name));
$template->assign('filter_category_selected', $selected_category);

// Dissociate from a category : categories listed for dissociation can only
// represent virtual links. We can't create orphans. Links to physical
// categories can't be broken.
$associated_categories = [];

if (count($cat_elements_id) > 0) {
    $query = '
SELECT
    DISTINCT(category_id) AS id
  FROM ' . IMAGE_CATEGORY_TABLE . ' AS ic
    JOIN ' . IMAGES_TABLE . ' AS i ON i.id = ic.image_id
  WHERE ic.image_id IN (' . implode(',', $cat_elements_id) . ')
    AND (
      ic.category_id != i.storage_category_id
      OR i.storage_category_id IS NULL
    )
;';

    $associated_categories = query2array($query, 'id', 'id');
}

$template->assign('associated_categories', $associated_categories);

load_language('help_quick_search.lang');
