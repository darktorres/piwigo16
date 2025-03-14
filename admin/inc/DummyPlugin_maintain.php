<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use Piwigo\inc\PluginMaintain;

include_once(PHPWG_ROOT_PATH.'inc/PluginMaintain.php');

/**
 * class DummyPlugin_maintain
 * used when a plugin uses the old procedural declaration of maintenance methods
 */
class DummyPlugin_maintain extends PluginMaintain
{
  function install($plugin_version, &$errors=array())
  {
    if (is_callable('plugin_install'))
    {
      return plugin_install($this->plugin_id, $plugin_version, $errors);
    }
  }
  function activate($plugin_version, &$errors=array())
  {
    if (is_callable('plugin_activate'))
    {
      return plugin_activate($this->plugin_id, $plugin_version, $errors);
    }
  }
  function deactivate()
  {
    if (is_callable('plugin_deactivate'))
    {
      return plugin_deactivate($this->plugin_id);
    }
  }
  function uninstall()
  {
    if (is_callable('plugin_uninstall'))
    {
      return plugin_uninstall($this->plugin_id);
    }
  }
  function update($old_version, $new_version, &$errors=array()) {}
}
?>
