<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;
use Piwigo\Core\AccessLevel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Site\SiteRepository;
use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/** @var array<string, mixed> $conf */
global $conf;
/** @var array<string, mixed> $page */
global $page;
/** @var Template $template */
global $template;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

if (! (bool) $conf['enable_synchronization']) {
    die('synchronization is disabled');
}

check_status(AccessLevel::Administrator);

if (! empty($_POST) or isset($_GET['action'])) {
    check_pwg_token();
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+
$template->set_filenames([
    'site_manager' => 'site_manager.tpl',
]);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('site_update');
$tabsheet->select('site_maager');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                        new site creation form                         |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit']) and ! empty($_POST['galleries_url']) and is_string($_POST['galleries_url'])) {
    $galleries_url_input = $_POST['galleries_url'];
    $is_remote = url_is_remote($galleries_url_input);
    if ($is_remote) {
        fatal_error('remote sites not supported');
    }
    $url = preg_replace('/[\/]*$/', '', $galleries_url_input);
    $url .= '/';
    if (! (str_starts_with($url, '.'))) {
        $url = './' . $url;
    }

    // site must not exists
    $site_repo = new SiteRepository(DbConnection::build());
    // $page['errors'] is always a plain array, initialized by
    // include/common.inc.php
    assert(is_array($page['errors']));
    if ($site_repo->countByUrl($url) > 0) {
        $page['errors'][] = l10n('This site already exists') . ' [' . $url . ']';
    }
    if (count($page['errors']) == 0) {
        if (! file_exists($url)) {
            $page['errors'][] = l10n('Directory does not exist') . ' [' . $url . ']';
        }
    }

    if (count($page['errors']) == 0) {
        $site_repo->insert($url);
        // $page['infos'] is always a plain array, initialized by
        // include/common.inc.php
        assert(is_array($page['infos']));
        $page['infos'][] = $url . ' ' . l10n('created');
    }
}

// +-----------------------------------------------------------------------+
// |                            actions on site                            |
// +-----------------------------------------------------------------------+
if (isset($_GET['site']) and is_numeric($_GET['site'])) {
    $page['site'] = $_GET['site'];
}
if (isset($_GET['action']) and isset($page['site']) and is_numeric($page['site'])) {
    $site_id = (int) $page['site'];
    $galleries_url = new SiteRepository(DbConnection::build())
        ->findGalleriesUrlById($site_id);
    switch ($_GET['action']) {
        case 'delete':

            delete_site($site_id);
            // $page['infos'] is always a plain array, initialized by
            // include/common.inc.php
            assert(is_array($page['infos']));
            $page['infos'][] = $galleries_url . ' ' . l10n('deleted');
            break;

    }
}

$template->assign(
    [
        'F_ACTION' => get_root_url() . 'admin.php' . get_query_string_diff(['action', 'site', 'pwg_token']),
        'PWG_TOKEN' => get_pwg_token(),
        'ADMIN_PAGE_TITLE' => l10n('Synchronize'),
    ]
);

$query = '
SELECT c.site_id, COUNT(DISTINCT c.id) AS nb_categories, COUNT(i.id) AS nb_images
  FROM ' . Tables::categories() . ' AS c LEFT JOIN ' . Tables::images() . ' AS i
  ON c.id=i.storage_category_id
  WHERE c.site_id IS NOT NULL
  GROUP BY c.site_id
;';
/** @var array<int|string, array<string, string|null>> $sites_detail */
$sites_detail = hash_from_query($query, 'site_id');

foreach (new SiteRepository(DbConnection::build())->findAll() as $row) {
    // 'id' and 'galleries_url' are both NOT NULL columns on the sites
    // table (see install/piwigo_structure-mysql.sql), so findAll() never
    // returns null for either of these keys here.
    $id = is_scalar($row['id']) ? (string) $row['id'] : '';
    $galleries_url = is_string($row['galleries_url']) ? $row['galleries_url'] : '';
    $is_remote = url_is_remote($galleries_url);
    $base_url = PHPWG_ROOT_PATH . 'admin.php';
    $base_url .= '?page=site_manager';
    $base_url .= '&amp;site=' . $id;
    $base_url .= '&amp;pwg_token=' . get_pwg_token();
    $base_url .= '&amp;action=';

    $update_url = PHPWG_ROOT_PATH . 'admin.php';
    $update_url .= '?page=site_update';
    $update_url .= '&amp;site=' . $id;

    $tpl_var =
      [
          'NAME' => $galleries_url,
          'TYPE' => l10n($is_remote ? 'Remote' : 'Local'),
          'CATEGORIES' => (int) @$sites_detail[$id]['nb_categories'],
          'IMAGES' => (int) @$sites_detail[$id]['nb_images'],
          'U_SYNCHRONIZE' => $update_url,
      ];

    if ((int) $id !== 1) {
        $tpl_var['U_DELETE'] = $base_url . 'delete';
    }

    $plugin_links = [];
    // $plugin_links is array of array composed of U_HREF, U_HINT & U_CAPTION
    $plugin_links =
      trigger_change(
          'get_admins_site_links',
          $plugin_links,
          $id,
          $is_remote
      );
    $tpl_var['plugin_links'] = $plugin_links;

    $template->append('sites', $tpl_var);
}

$template->assign_var_from_handle('ADMIN_CONTENT', 'site_manager');
