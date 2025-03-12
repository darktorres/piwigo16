<?php

namespace Piwigo\plugins\AdminTools;

use Piwigo\inc\functions;
use Piwigo\inc\PluginMaintain;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

class AdminTools_maintain extends PluginMaintain
{
  private $default_conf = array(
    'default_open' => true,
    'closed_position' => 'left',
    'public_quick_edit' => true,
    );

  function install($plugin_version, &$errors=array())
  {
    global $conf;

    if (empty($conf['AdminTools']))
    {
      functions::conf_update_param('AdminTools', $this->default_conf, true);
    }
  }

  function update($old_version, $new_version, &$errors=array())
  {
    $this->install($new_version, $errors);
  }

  function uninstall()
  {
    functions::conf_delete_param('AdminTools');
  }
}
