<?php
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

if( !defined("PHPWG_ROOT_PATH") )
{
  die ("Hacking attempt!");
}

functions_user::check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = functions_url::get_root_url().'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('tags');
$tabsheet->select('');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                           delete orphan tags                          |
// +-----------------------------------------------------------------------+

if (isset($_GET['action']) and 'delete_orphans' == $_GET['action'])
{
  functions::check_pwg_token();

  functions_admin::delete_orphan_tags();
  $_SESSION['message_tags'] = functions::l10n('Orphan tags deleted');
  functions::redirect(functions_url::get_root_url().'admin.php?page=tags');
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(array('tags' => 'tags.tpl'));

$template->assign(
  array(
    'F_ACTION' => PHPWG_ROOT_PATH.'admin.php?page=tags',
    'PWG_TOKEN' => functions::get_pwg_token(),
    )
  );

// +-----------------------------------------------------------------------+
// |                              orphan tags                              |
// +-----------------------------------------------------------------------+

$warning_tags = "";

$orphan_tags = functions_admin::get_orphan_tags();

$orphan_tag_names_array = '[]';
$orphan_tag_names = array();
foreach ($orphan_tags as $tag)
{
  $orphan_tag_names[] = functions_plugins::trigger_change('render_tag_name', $tag['name'], $tag);
}

if (count($orphan_tag_names) > 0)
{
  $warning_tags = sprintf(
    functions::l10n('You have %d orphan tags %s'),
    count($orphan_tag_names),
    '<a 
      class="icon-eye"
      data-url="'.functions_url::get_root_url().'admin.php?page=tags&amp;action=delete_orphans&amp;pwg_token='.functions::get_pwg_token().'">'
      .functions::l10n('Review').'</a>'
    );

  $orphan_tag_names_array = '["';
  $orphan_tag_names_array.= implode(
    '" ,"',
    array_map(
      htmlentities(...),
      $orphan_tag_names,
      array_fill(0 , count($orphan_tag_names) , ENT_QUOTES)
    )
  );
  $orphan_tag_names_array.= '"]';
}

$template->assign(
  array(
    'orphan_tag_names_array' => $orphan_tag_names_array,
    'warning_tags' => $warning_tags,
    )
  );

$message_tags = '';
if (isset($_SESSION['message_tags']))
{
  $message_tags = $_SESSION['message_tags'];
  unset($_SESSION['message_tags']);
}
$template->assign('message_tags', $message_tags);

// +-----------------------------------------------------------------------+
// |                             form creation                             |
// +-----------------------------------------------------------------------+
$per_page = 100;

// tag counters
$query = '
SELECT tag_id, COUNT(image_id) AS counter
  FROM '.IMAGE_TAG_TABLE.'
  GROUP BY tag_id';
$tag_counters = functions::simple_hash_from_query($query, 'tag_id', 'counter');

// all tags
$query = '
SELECT name, id, url_name
  FROM '.TAGS_TABLE.'
;';
$result = functions_mysqli::pwg_query($query);
$all_tags = array();
while ($tag = functions_mysqli::pwg_db_fetch_assoc($result))
{
  $raw_name = $tag['name'];
  $tag['raw_name'] = $raw_name;
  $tag['name'] = functions_plugins::trigger_change('render_tag_name', $raw_name, $tag);
  $counter = intval(@$tag_counters[ $tag['id'] ]);
  if ($counter > 0) 
  {
    $tag['counter'] = intval(@$tag_counters[ $tag['id'] ]);
  }

  $alt_names = functions_plugins::trigger_change('get_tag_alt_names', array(), $raw_name);
  $alt_names = array_diff( array_unique($alt_names), array($tag['name']) );
  if (count($alt_names))
  {
    $tag['alt_names'] = implode(', ', $alt_names);
  }
  $all_tags[] = $tag;
}
usort($all_tags, functions_html::tag_alpha_compare(...));

$template->assign(
  array(
    'first_tags' => array_slice($all_tags, 0, $per_page),
    'data' => $all_tags,
    'total' => count($all_tags),
    'per_page' => $per_page,
    'ADMIN_PAGE_TITLE' => functions::l10n('Tags'),
    )
  );

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'tags');

?>
