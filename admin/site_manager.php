<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new \Piwigo\Exception\AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

if (!\Piwigo\Config\Config::enableSynchronization()) {
    throw new \Piwigo\Exception\ConfigException('synchronization is disabled');
}

check_status(ACCESS_ADMINISTRATOR);

if (!empty($_POST) or isset($_GET['action'])) {
    check_pwg_token();
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+
$template->set_filenames(['site_manager' => 'site_manager.tpl']);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = get_root_url().'admin.php?page=';

$tabsheet = new Tabsheet();
$tabsheet->set_id('site_update');
$tabsheet->select('site_maager');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                        new site creation form                         |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit']) and !empty($_POST['galleries_url'])) {
    $galleries_url = is_scalar($_POST['galleries_url']) ? (string) $_POST['galleries_url'] : '';
    $is_remote = url_is_remote($galleries_url);
    if ($is_remote) {
        fatal_error('remote sites not supported');
    }
    $url = preg_replace('/[\/]*$/', '', $galleries_url);
    $url .= '/';
    if (! (str_starts_with($url, '.'))) {
        $url = './' . $url;
    }

    // site must not exists
    $siteRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Site\SiteRepository::class);
    if ($siteRepo->countByUrl($url) > 0) {
        \Piwigo\Core\PageState::current()->addError(l10n('This site already exists').' ['.$url.']');
    }
    if (count($page['errors']) == 0) {
        if (! file_exists($url)) {
            \Piwigo\Core\PageState::current()->addError(l10n('Directory does not exist').' ['.$url.']');
        }
    }

    if (count($page['errors']) == 0) {
        $siteRepo->insert($url);
        \Piwigo\Core\PageState::current()->addInfo($url.' '.l10n('created'));
    }
}

// +-----------------------------------------------------------------------+
// |                            actions on site                            |
// +-----------------------------------------------------------------------+
if (isset($_GET['site']) and is_numeric($_GET['site'])) {
    $page['site'] = $_GET['site'];
}
if (isset($_GET['action']) and isset($page['site'])) {
    $galleries_url = \Piwigo\Core\ServiceLocator::get(\Piwigo\Site\SiteRepository::class)
        ->findGalleriesUrlById((int) $page['site']);
    switch ($_GET['action']) {
        case 'delete':
            {
                delete_site($page['site']);
                \Piwigo\Core\PageState::current()->addInfo($galleries_url.' '.l10n('deleted'));
                break;
            }
    }
}

$template->assign(
    [
    'F_ACTION'  => get_root_url().'admin.php'.get_query_string_diff(['action','site','pwg_token']),
    'PWG_TOKEN' => get_pwg_token(),
    'ADMIN_PAGE_TITLE' => l10n('Synchronize'),
    'page_data_json' => json_encode([
        'str_delete_site_confirm' => l10n('Are you sure you want to delete this site?'),
    ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
    ]
);

$query = '
SELECT c.site_id, COUNT(DISTINCT c.id) AS nb_categories, COUNT(i.id) AS nb_images
  FROM '.CATEGORIES_TABLE.' AS c LEFT JOIN '.IMAGES_TABLE.' AS i
  ON c.id=i.storage_category_id 
  WHERE c.site_id IS NOT NULL
  GROUP BY c.site_id
;';
$sites_detail = query2array($query, 'site_id');

foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Site\SiteRepository::class)->findAll() as $row) {
    $is_remote = url_is_remote((string)$row['galleries_url']);
    $base_url = PHPWG_ROOT_PATH.'admin.php';
    $base_url .= '?page=site_manager';
    $base_url .= '&amp;site='.$row['id'];
    $base_url .= '&amp;pwg_token='.get_pwg_token();
    $base_url .= '&amp;action=';

    $update_url = PHPWG_ROOT_PATH.'admin.php';
    $update_url .= '?page=site_update';
    $update_url .= '&amp;site='.$row['id'];

    $site_id = (int)$row['id'];
    $tpl_var =
      [
        'NAME' => $row['galleries_url'],
        'TYPE' => l10n($is_remote ? 'Remote' : 'Local'),
        'CATEGORIES' => (int) ($sites_detail[(string) $site_id]['nb_categories'] ?? 0),
        'IMAGES' => (int) ($sites_detail[(string) $site_id]['nb_images'] ?? 0),
        'U_SYNCHRONIZE' => $update_url,
       ];

    if ($row['id'] != 1) {
        $tpl_var['U_DELETE'] = $base_url.'delete';
    }

    $plugin_links = [];
    //$plugin_links is array of array composed of U_HREF, U_HINT & U_CAPTION
    $plugin_links =
      trigger_change(
          'get_admins_site_links',
          $plugin_links,
          $row['id'],
          $is_remote
      );
    $tpl_var['plugin_links'] = $plugin_links;

    $template->append('sites', $tpl_var);
}

$template->assign_var_from_handle('ADMIN_CONTENT', 'site_manager');
