<?php

declare(strict_types=1);

use Piwigo\Url\UrlGenerator;
use Piwigo\Admin\Tabsheet;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\AuthException;
use Piwigo\Exception\ConfigException;
use Piwigo\Site\SiteRepository;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

if (!Config::enableSynchronization()) {
    throw new ConfigException('synchronization is disabled');
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

$my_base_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';

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
    $siteRepo = ServiceLocator::get(SiteRepository::class);
    if ($siteRepo->countByUrl($url) > 0) {
        PageState::current()->addError(l10n('This site already exists').' ['.$url.']');
    }
    if (count($page['errors']) == 0) {
        if (! file_exists($url)) {
            PageState::current()->addError(l10n('Directory does not exist').' ['.$url.']');
        }
    }

    if (count($page['errors']) == 0) {
        $siteRepo->insert($url);
        PageState::current()->addInfo($url.' '.l10n('created'));
    }
}

// +-----------------------------------------------------------------------+
// |                            actions on site                            |
// +-----------------------------------------------------------------------+
if (isset($_GET['site']) and is_numeric($_GET['site'])) {
    $page['site'] = $_GET['site'];
}
if (isset($_GET['action']) and isset($page['site'])) {
    $galleries_url = ServiceLocator::get(SiteRepository::class)
        ->findGalleriesUrlById((int) $page['site']);
    switch ($_GET['action']) {
        case 'delete':
            {
                delete_site($page['site']);
                PageState::current()->addInfo($galleries_url.' '.l10n('deleted'));
                break;
            }
    }
}

$template->assign(
    [
    'F_ACTION'  => ServiceLocator::get(UrlGenerator::class)->admin().get_query_string_diff(['action','site','pwg_token']),
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
$sites_detail = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), null, 'site_id');

foreach (ServiceLocator::get(SiteRepository::class)->findAll() as $row) {
    $row_id_str = is_scalar($row['id']) ? (string) $row['id'] : '';
    $is_remote = url_is_remote(is_scalar($row['galleries_url']) ? (string)$row['galleries_url'] : '');
    $base_url = ServiceLocator::get(UrlGenerator::class)->admin('site_manager');
    $base_url .= '&amp;site='.$row_id_str;
    $base_url .= '&amp;pwg_token='.get_pwg_token();
    $base_url .= '&amp;action=';

    $update_url = ServiceLocator::get(UrlGenerator::class)->admin('site_update');
    $update_url .= '&amp;site='.$row_id_str;

    $site_id = is_numeric($row['id']) ? (int)$row['id'] : 0;
    $tpl_var =
      [
        'NAME' => $row['galleries_url'],
        'TYPE' => l10n($is_remote ? 'Remote' : 'Local'),
        'CATEGORIES' => is_numeric($sites_detail[(string) $site_id]['nb_categories'] ?? null) ? (int) $sites_detail[(string) $site_id]['nb_categories'] : 0,
        'IMAGES' => is_numeric($sites_detail[(string) $site_id]['nb_images'] ?? null) ? (int) $sites_detail[(string) $site_id]['nb_images'] : 0,
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
