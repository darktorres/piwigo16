<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\languages;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if( !defined("PHPWG_ROOT_PATH") )
{
  die ("Hacking attempt!");
}

if (!functions_user::is_webmaster())
{
  $page['warnings'][] = str_replace('%s', functions::l10n('user_status_webmaster'), functions::l10n('%s status is required to edit parameters.'));
}

$template->set_filenames(array('languages' => 'languages_installed.tpl'));

$base_url = functions_url::get_root_url().'admin.php?page='.$page['page'];

$languages = new languages();
$languages->get_db_languages();

//--------------------------------------------------perform requested actions
functions::check_input_parameter('action', $_GET, false, '/^(activate|deactivate|set_default|delete)$/');
functions::check_input_parameter('language', $_GET, false, '/^('.join('|', array_keys($languages->fs_languages)).')$/');

if (isset($_GET['action']) and isset($_GET['language']) and functions_user::is_webmaster())
{
  $page['errors'] = $languages->perform_action($_GET['action'], $_GET['language']);

  if (empty($page['errors']))
  {
    functions::redirect($base_url);
  }
}

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+
$default_language = functions_user::get_default_language();

$tpl_languages = array();

foreach($languages->fs_languages as $language_id => $language)
{
  $language['u_action'] = functions_url::add_url_params($base_url, array('language' => $language_id));

  if (in_array($language_id, array_keys($languages->db_languages)))
  {
    $language['state'] = 'active';
    $language['deactivable'] = true;

    if (count($languages->db_languages) <= 1)
    {
      $language['deactivable'] = false;
      $language['deactivate_tooltip'] = functions::l10n('Impossible to deactivate this language, you need at least one language.');
    }

    if ($language_id == $default_language)
    {
      $language['deactivable'] = false;
      $language['deactivate_tooltip'] = functions::l10n('Impossible to deactivate this language, first set another language as default.');
    }
  }
  else
  {
    $language['state'] = 'inactive';
  }

  if ($language_id == $default_language)
  {
    $language['is_default'] = true;
    array_unshift($tpl_languages, $language);
  }
  else
  {
    $language['is_default'] = false;
    $tpl_languages[] = $language;
  }
}

$template->assign(
  array(
    'languages' => $tpl_languages,
    )
  );
$template->append('language_states', 'active');
$template->append('language_states', 'inactive');


$missing_language_ids = array_diff(
    array_keys($languages->db_languages),
    array_keys($languages->fs_languages)
  );

foreach($missing_language_ids as $language_id)
{
  $query = '
UPDATE '.USER_INFOS_TABLE.'
  SET language = \''.functions_user::get_default_language().'\'
  WHERE language = \''.$language_id.'\'
;';
  functions_mysqli::pwg_query($query);

  $query = '
DELETE
  FROM '.LANGUAGES_TABLE.'
  WHERE id= \''.$language_id.'\'
;';
  functions_mysqli::pwg_query($query);
}

$template->assign('isWebmaster', (functions_user::is_webmaster()) ? 1 : 0);
$template->assign('ADMIN_PAGE_TITLE', functions::l10n('Languages'));
$template->assign('CONF_ENABLE_EXTENSIONS_INSTALL', $conf['enable_extensions_install']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'languages');
?>
