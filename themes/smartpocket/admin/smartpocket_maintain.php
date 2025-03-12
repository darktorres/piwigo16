<?php

namespace Piwigo\themes\smartpocket\admin;

use Piwigo\inc\functions;
use Piwigo\inc\ThemeMaintain;

class smartpocket_maintain extends ThemeMaintain
{
  private $installed = false;
  
  private $default_conf = array(
    'loop'            => true,//true - false
    'autohide'            => 5000,//5000 - 0
  );
  
  function activate($theme_version, &$errors=array())
  {
    global $conf, $prefixeTable;

    if (empty($conf['smartpocket']))
    {
      functions::conf_update_param('smartpocket', $this->default_conf, true);
    }
    elseif (count(functions::safe_unserialize($conf['smartpocket'])) != 2)
    {
      $conff = functions::safe_unserialize($conf['smartpocket']);
      
      $config = array(
        'loop' => (!empty($conff['loop'])) ? $conff['loop'] :true,
        'autohide' => (!empty($conff['autohide'])) ? $conff['autohide'] :5000,
      );
      
      functions::conf_update_param('smartpocket', $config, true);
    }
    $this->installed = true;
  }

  function deactivate()
  {
  }

  function delete()
  {
    // delete configuration
    functions::conf_delete_param('smartpocket');
  }
}
?>