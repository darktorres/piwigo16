<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\themes;
use Piwigo\inc\functions;
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


$base_url = functions_url::get_root_url().'admin.php?page='.$page['page'].'&tab='.$page['tab'];

$themes = new themes();

// +-----------------------------------------------------------------------+
// |                           setup check                                 |
// +-----------------------------------------------------------------------+

$themes_dir = PHPWG_ROOT_PATH.'themes';
if (!is_writable($themes_dir))
{
  $page['errors'][] = functions::l10n('Add write access to the "%s" directory', 'themes');
}

// +-----------------------------------------------------------------------+
// |                       perform installation                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['revision']) and isset($_GET['extension']))
{
  if (!functions_user::is_webmaster())
  {
    $page['errors'][] = functions::l10n('Webmaster status is required.');
  }
  else
  {
    functions::check_pwg_token();

    $install_status = $themes->extract_theme_files(
      'install',
      $_GET['revision'],
      $_GET['extension'],
      $theme_id
      );
    
    functions::redirect($base_url.'&installstatus='.$install_status.'&theme_id='.$theme_id);
  }
}

// +-----------------------------------------------------------------------+
// |                        installation result                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['installstatus']))
{
  switch ($_GET['installstatus'])
  {
    case 'ok':
      $page['infos'][] = functions::l10n('Theme has been successfully installed');

      if (isset($themes->fs_themes[$_GET['theme_id']]))
      {
        functions::pwg_activity(
          'system',
          ACTIVITY_SYSTEM_THEME,
          'install',
          array(
            'theme_id' => $_GET['theme_id'],
            'version' => $themes->fs_themes[$_GET['theme_id']]['version'],
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
      $page['errors'][] = functions::l10n(
        'An error occured during extraction (%s).',
        htmlspecialchars($_GET['installstatus'])
        );
  }  
}

// +-----------------------------------------------------------------------+
// |                          template output                              |
// +-----------------------------------------------------------------------+

$template->set_filenames(array('themes' => 'themes_new.tpl'));

if ($themes->get_server_themes(true)) // only new themes
{
  foreach($themes->server_themes as $theme)
  {
    $url_auto_install = htmlentities($base_url)
      . '&amp;revision=' . $theme['revision_id']
      . '&amp;extension=' . $theme['extension_id']
      . '&amp;pwg_token='.functions::get_pwg_token()
      ;

    $template->append(
      'new_themes',
      array(
        'name' => $theme['extension_name'],
        'thumbnail' => (key_exists('thumbnail_src', $theme)) ? $theme['thumbnail_src']:'',
        'screenshot' => (key_exists('screenshot_url', $theme)) ? $theme['screenshot_url']:'',
        'install_url' => $url_auto_install,
        )
      );
  }
}
else
{
  $page['errors'][] = functions::l10n('Can\'t connect to server.');
}

$template->assign('default_screenshot',
  functions_url::get_root_url().'admin/themes/'.functions_user::userprefs_get_param('admin_theme', 'roma').'/images/missing_screenshot.png'
);
$template->assign('ADMIN_PAGE_TITLE', functions::l10n('Themes'));

$template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
?>
