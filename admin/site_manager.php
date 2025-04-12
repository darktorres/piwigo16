<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

if (! $conf->enable_synchronization) {
    exit('synchronization is disabled');
}

functions_user::check_status(ACCESS_ADMINISTRATOR);

if (! empty($_POST) ||
    isset($_GET['action'])
) {
    functions::check_pwg_token();
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

$my_base_url = functions_url::get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('site_update');
$tabsheet->select('site_manager');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                        new site creation form                         |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit']) &&
    ! empty($_POST['galleries_url'])
) {
    $is_remote = functions_url::url_is_remote($_POST['galleries_url']);

    if ($is_remote) {
        functions_html::fatal_error('remote sites not supported');
    }

    $url = preg_replace('/[\/]*$/', '', $_POST['galleries_url']);
    $url .= '/';

    if (! (strpos($url, '.') === 0)) {
        $url = './' . $url;
    }

    // site must not exists
    $query = <<<SQL
        SELECT COUNT(id) AS count
        FROM sites
        WHERE galleries_url = '{$url}';
        SQL;
    $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

    if ($row['count'] > 0) {
        $page['errors'][] = functions::l10n('This site already exists') . ' [' . $url . ']';
    }

    if (count($page['errors']) == 0) {
        if (! file_exists($url)) {
            $page['errors'][] = functions::l10n('Directory does not exist') . ' [' . $url . ']';
        }
    }

    if (count($page['errors']) == 0) {
        $query = <<<SQL
            INSERT INTO sites
                (galleries_url)
            VALUES
                ('{$url}');
            SQL;
        functions_mysqli::pwg_query($query);
        $page['infos'][] = $url . ' ' . functions::l10n('created');
    }
}

// +-----------------------------------------------------------------------+
// |                            actions on site                            |
// +-----------------------------------------------------------------------+
if (isset($_GET['site']) &&
    is_numeric($_GET['site'])
) {
    $page['site'] = $_GET['site'];
}

if (isset($_GET['action']) &&
    isset($page['site'])
) {
    $query = <<<SQL
        SELECT galleries_url
        FROM sites
        WHERE id = {$page['site']};
        SQL;
    list($galleries_url) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

    switch ($_GET['action']) {
        case 'delete':
            functions_admin::delete_site($page['site']);
            $page['infos'][] = $galleries_url . ' ' . functions::l10n('deleted');
            break;
    }
}

$template->assign(
    [
        'F_ACTION' => functions_url::get_root_url() . 'admin.php' . functions_url::get_query_string_diff(['action', 'site', 'pwg_token']),
        'PWG_TOKEN' => functions::get_pwg_token(),
        'ADMIN_PAGE_TITLE' => functions::l10n('Synchronize'),
    ]
);

$query = <<<SQL
    SELECT c.site_id, COUNT(DISTINCT c.id) AS nb_categories, COUNT(i.id) AS nb_images
    FROM categories AS c
    LEFT JOIN images AS i ON c.id = i.storage_category_id
    WHERE c.site_id IS NOT NULL
    GROUP BY c.site_id;
    SQL;
$sites_detail = functions_mysqli::query2array($query, 'site_id');

$query = <<<SQL
    SELECT *
    FROM sites;
    SQL;
$result = functions_mysqli::pwg_query($query);

while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
    $is_remote = functions_url::url_is_remote($row['galleries_url']);
    $base_url = './admin.php';
    $base_url .= '?page=site_manager';
    $base_url .= '&amp;site=' . $row['id'];
    $base_url .= '&amp;pwg_token=' . functions::get_pwg_token();
    $base_url .= '&amp;action=';

    $update_url = './admin.php';
    $update_url .= '?page=site_update';
    $update_url .= '&amp;site=' . $row['id'];

    $tpl_var =
      [
          'NAME' => $row['galleries_url'],
          'TYPE' => functions::l10n($is_remote ? 'Remote' : 'Local'),
          'CATEGORIES' => (int) $sites_detail[$row['id']]['nb_categories'],
          'IMAGES' => (int) $sites_detail[$row['id']]['nb_images'],
          'U_SYNCHRONIZE' => $update_url,
      ];

    if ($row['id'] != 1) {
        $tpl_var['U_DELETE'] = $base_url . 'delete';
    }

    $plugin_links = [];
    //$plugin_links is array of array composed of U_HREF, U_HINT & U_CAPTION
    $plugin_links =
      functions_plugins::trigger_change(
          'get_admins_site_links',
          $plugin_links,
          $row['id'],
          $is_remote
      );
    $tpl_var['plugin_links'] = $plugin_links;

    $template->append('sites', $tpl_var);
}

$template->assign_var_from_handle('ADMIN_CONTENT', 'site_manager');
