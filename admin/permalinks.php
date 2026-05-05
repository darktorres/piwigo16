<?php

declare(strict_types=1);

use Piwigo\Template\TemplateRegistry;
use Piwigo\Exception\AuthException;
use Piwigo\Core\ServiceLocator;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Core\PageState;
use Doctrine\DBAL\Connection;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * @return mixed[]
 */
/**
 * @param string[] $sortable_by
 * @param string[]|null $get_rejects
 * @return array<mixed>
 */
function parse_sort_variables(
    array $sortable_by,
    ?string $default_field,
    string $get_param,
    ?array $get_rejects,
    ?string $template_var,
    string $anchor = ''
): array {
    $template = TemplateRegistry::current();
    $url_components = parse_url(is_scalar($_SERVER['REQUEST_URI'] ?? null) ? (string) $_SERVER['REQUEST_URI'] : '');
    if ($url_components === false) {
        $url_components = ['path' => '', 'query' => ''];
    }

    $base_url = $url_components['path'] ?? '';

    parse_str($url_components['query'] ?? '', $vars);
    $is_first = true;
    foreach ($vars as $key => $value) {
        if (!in_array($key, $get_rejects ?? []) and $key != $get_param) {
            $base_url .= $is_first ? '?' : '&amp;';
            $is_first = false;

            if (!in_array($key, ['page', 'psf', 'dpsf', 'pwg_token'])) {
                fatal_error('unexpected URL get key');
            }

            $base_url .= urlencode((string) $key).'='.urlencode(is_string($value) ? $value : '');
        }
    }

    $ret = [];
    foreach ($sortable_by as $field) {
        $url = $base_url;
        $disp = '↓'; // TODO: an small image is better

        if ($field !== ($_GET[$get_param] ?? null)) {
            if ($default_field != $field) { // the first should be the default
                $url = add_url_params($url, [$get_param => $field]);
            } elseif (!isset($_GET[$get_param])) {
                $ret[] = $field;
                $disp = '<em>'.$disp.'</em>';
            }
        } else {
            $ret[] = $field;
            $disp = '<em>'.$disp.'</em>';
        }
        if (isset($template_var)) {
            $template->assign(
                $template_var.strtoupper((string) $field),
                '<a href="'.$url.$anchor.'" title="'.l10n('Sort order').'">'.$disp.'</a>'
            );
        }
    }
    return $ret;
}

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


require_once(PHPWG_ROOT_PATH.'admin/include/functions_permalinks.php');

check_input_parameter('cat_id', $_POST, false, PATTERN_ID);

$selected_cat = [];
if (isset($_POST['set_permalink']) and $_POST['cat_id'] > 0) {
    check_pwg_token();
    $permalink = is_scalar($_POST['permalink'] ?? null) ? (string) $_POST['permalink'] : '';
    $postCatId = is_scalar($_POST['cat_id']) ? (string) $_POST['cat_id'] : '';
    if (empty($permalink)) {
        delete_cat_permalink($postCatId, isset($_POST['save']));
    } else {
        set_cat_permalink($postCatId, $permalink, isset($_POST['save']));
    }
    $selected_cat = [(int) $postCatId];
} elseif (isset($_GET['delete_permanent'])) {
    check_pwg_token();
    $deleted = ServiceLocator::get(PermalinkRepository::class)
        ->deleteOldPermalinkByValue(is_scalar($_GET['delete_permanent']) ? (string) $_GET['delete_permanent'] : '');
    if (!$deleted) {
        PageState::current()->addError(l10n('Cannot delete the old permalink !'));
    }
}


$template->set_filename('permalinks', 'permalinks.tpl');

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'permalinks';
require(PHPWG_ROOT_PATH.'admin/include/albums_tab.inc.php');


$query = '
SELECT
  id, permalink,
  CONCAT(id, " - ", name, IF(permalink IS NULL, "", " &radic;") ) AS name,
  uppercats, global_rank
FROM '.CATEGORIES_TABLE;

display_select_cat_wrapper($query, $selected_cat, 'categories', false);

$pwg_token = get_pwg_token();

// --- generate display of active permalinks -----------------------------------
$sort_by = parse_sort_variables(
    ['id', 'name', 'permalink'],
    'name',
    'psf',
    ['delete_permanent'],
    'SORT_'
);

$sortBy0 = is_scalar($sort_by[0] ?? null) ? (string) $sort_by[0] : '';
$permalinkQuery = 'SELECT id, permalink, uppercats, global_rank FROM ' . CATEGORIES_TABLE . ' WHERE permalink IS NOT NULL';
if ($sortBy0 === 'id' || $sortBy0 === 'permalink') {
    $permalinkQuery .= ' ORDER BY ' . $sortBy0;
}
$categories = [];
foreach (ServiceLocator::get(Connection::class)
    ->executeQuery($permalinkQuery)->fetchAllAssociative() as $row) {
    $row['name'] = get_cat_display_name_cache(is_scalar($row['uppercats'] ?? null) ? (string) $row['uppercats'] : '');
    $categories[] = $row;
}

if ($sort_by[0] == 'name') {
    usort($categories, global_rank_compare(...));
}
$template->assign('permalinks', $categories);

// --- generate display of old permalinks --------------------------------------

$sort_by = parse_sort_variables(
    ['cat_id','permalink','date_deleted','last_hit','hit'],
    null,
    'dpsf',
    ['delete_permanent'],
    'SORT_OLD_',
    '#old_permalinks'
);

$url_del_base = get_root_url().'admin.php?page=permalinks';
$sortByOld0 = is_scalar($sort_by[0] ?? null) ? (string) $sort_by[0] : '';
$oldPermalinkQuery = 'SELECT * FROM ' . OLD_PERMALINKS_TABLE;
if (count($sort_by) && $sortByOld0 !== '') {
    $oldPermalinkQuery .= ' ORDER BY ' . $sortByOld0;
}
$deleted_permalinks = [];
foreach (ServiceLocator::get(Connection::class)
    ->executeQuery($oldPermalinkQuery)->fetchAllAssociative() as $row) {
    $row['name'] = get_cat_display_name_cache((string)(is_numeric($row['cat_id']) ? (int)$row['cat_id'] : 0));
    $row['U_DELETE'] =
        add_url_params(
            $url_del_base,
            ['delete_permanent' => $row['permalink'],'pwg_token' => $pwg_token]
        );
    $deleted_permalinks[] = $row;
}

$template->assign([
  'PWG_TOKEN' => $pwg_token,
  'U_HELP' => get_root_url().'admin/popuphelp.php?page=permalinks',
  'deleted_permalinks' => $deleted_permalinks,
  'ADMIN_PAGE_TITLE' => l10n('Albums'),
  'page_data_json' => json_encode([
      'nb_cats' => count($categories),
  ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
  ]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'permalinks');
