<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\plugins;
use Piwigo\inc\functions;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if( !defined("PHPWG_ROOT_PATH") )
{
  die ("Hacking attempt!");
}

if (!$conf['enable_extensions_install'])
{
  die('Piwigo extensions install/update system is disabled');
}

$template->set_filenames(array('plugins' => 'plugins_new.tpl'));

$base_url = functions_url::get_root_url().'admin.php?page='.$page['page'].'&tab='.$page['tab'];

$plugins = new plugins();

//------------------------------------------------------automatic installation
if (isset($_GET['revision']) and isset($_GET['extension']))
{
  if (!functions_user::is_webmaster())
  {
    $page['errors'][] = functions::l10n('Webmaster status is required.');
  }
  else
  {
    functions::check_pwg_token();
    
    $install_status = $plugins->extract_plugin_files('install', $_GET['revision'], $_GET['extension'], $plugin_id);

    functions::redirect($base_url.'&installstatus='.$install_status.'&plugin_id='.$plugin_id);
  }
}

//--------------------------------------------------------------install result
if (isset($_GET['installstatus']))
{
  switch ($_GET['installstatus'])
  {
    case 'ok':
      // since Piwigo 12, you need to be on the page of installed plugins to active a plugin with
      // a JS action, no need to provide plugin_id in URL, just link to the page of installed
      // plugins, filtered on deactivated plugins. The webmaster will have to find its newly
      // installed plugin and click on the activation switch.
      $activate_url = functions_url::get_root_url().'admin.php?page=plugins&amp;filter=deactivated';

      $page['infos'][] = functions::l10n('Plugin has been successfully copied');
      $page['infos'][] = '<a href="'. $activate_url . '">' . functions::l10n('Activate it now') . '</a>';

      if (isset($plugins->fs_plugins[$_GET['plugin_id']]))
      {
        functions::pwg_activity(
          'system',
          ACTIVITY_SYSTEM_PLUGIN,
          'install',
          array(
            'plugin_id' => $_GET['plugin_id'],
            'version' => $plugins->fs_plugins[$_GET['plugin_id']]['version'],
          )
        );
      }
      break;

    case 'temp_path_error':
      $page['errors'][] = functions::l10n('Can\'t create temporary file.');
      break;

    case 'dl_archive_error':
      $page['errors'][] = functions::l10n('Can\'t download archive.');
      break;

    case 'archive_error':
      $page['errors'][] = functions::l10n('Can\'t read or extract archive.');
      break;

    default:
      $page['errors'][] = functions::l10n('An error occured during extraction (%s).', htmlspecialchars($_GET['installstatus']));
      $page['errors'][] = functions::l10n('Please check "plugins" folder and sub-folders permissions (CHMOD).');
  }  
}

//---------------------------------------------------------------Order options
$template->assign('order_options',
  array(
    'date' => functions::l10n('Post date'),
    'revision' => functions::l10n('Last revisions'),
    'name' => functions::l10n('Name'),
    'author' => functions::l10n('Author'),
    'downloads' => functions::l10n('Number of downloads')));

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+

// Beta test : show plugins of last version on PEM if the current version isn't present
// If the current version in known, give the current and last version's compatible plugins
$beta_test = false;

if(isset($_GET['beta-test']) && $_GET['beta-test'] == 'true') 
{
  $beta_test = true;
}

if ($plugins->get_server_plugins(true, $beta_test))
{
  /* order plugins */
  if (functions_session::pwg_get_session_var('plugins_new_order') != null)
  {
    $order_selected = functions_session::pwg_get_session_var('plugins_new_order');
    $plugins->sort_server_plugins($order_selected);
    $template->assign('order_selected', $order_selected);
  }
  else
  {
    $plugins->sort_server_plugins('date');
    $template->assign('order_selected', 'date');
  }

  foreach($plugins->server_plugins as $plugin)
  {
    $ext_desc = trim($plugin['extension_description'], " \n\r");
    list($small_desc) = explode("\n", wordwrap($ext_desc, 200));

    $url_auto_install = htmlentities($base_url)
      . '&amp;revision=' . $plugin['revision_id']
      . '&amp;extension=' . $plugin['extension_id']
      . '&amp;pwg_token='.functions::get_pwg_token()
    ;

    // get the age of the last revision in days
    $last_revision_diff = date_diff(date_create($plugin['revision_date']), date_create());

    $certification = 1;
    $has_compatible_version = false;

    // Check if the current version is in the compatible version (not necessary if we are in beta test)
    if ($beta_test) {
      foreach ($plugin['compatible_with_versions'] as $vers) {
        if (functions::get_branch_from_version($vers) == functions::get_branch_from_version(PHPWG_VERSION))
        {
          $has_compatible_version = true;
        } 
      }
    } else {
      $has_compatible_version = true;
    }

    if (!$has_compatible_version) {
      $certification = -1;
    }
    elseif ($last_revision_diff->days < 90) // if the last revision is new of 3 month or less
    {
      $certification = 3;
    }
    elseif ($last_revision_diff->days < 180) // 6 month or less
    {
      $certification = 2;
    }
    elseif ($last_revision_diff->y > 3) // 3 years or less
    {
      $certification = 0;
    }
    // Between 6 month and 3 years : certification = 1

    $template->append('plugins', array(
      'ID' => $plugin['extension_id'],
      'EXT_NAME' => $plugin['extension_name'],
      'EXT_URL' => PEM_URL.'/extension_view.php?eid='.$plugin['extension_id'],
      'SMALL_DESC' => trim($small_desc, " \r\n"),
      'BIG_DESC' => $ext_desc,
      'VERSION' => $plugin['revision_name'],
      'REVISION_DATE' => preg_replace('/[^0-9]/', '', strtotime($plugin['revision_date'])),
      'REVISION_FORMATED_DATE' => functions::format_date($plugin['revision_date'], array('day','month','year')).", ".functions::time_since($plugin['revision_date'], "day"),
      'AUTHOR' => $plugin['author_name'],
      'DOWNLOADS' => $plugin['extension_nb_downloads'],
      'URL_INSTALL' => $url_auto_install,
      'CERTIFICATION' => $certification,
      'RATING' => $plugin['rating_score'],
      'NB_RATINGS' => $plugin['nb_ratings'],
      'SCREENSHOT' => (key_exists('screenshot_url', $plugin)) ? $plugin['screenshot_url']:'',
      'TAGS' => $plugin["tags"],
    ));
  }

  
}
else
{
  $page['errors'][] = functions::l10n('Can\'t connect to server.');
}

if (!$beta_test and preg_match('/(beta|RC)/', PHPWG_VERSION))
{
  $template->assign('BETA_URL', $base_url.'&amp;beta-test=true');
}
$template->assign('ADMIN_PAGE_TITLE', functions::l10n('Plugins'));
$template->assign('BETA_TEST', $beta_test);
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugins');
?>