<?php

namespace Piwigo\plugins\GDThumb;

use Piwigo\inc\functions;
use Piwigo\inc\PluginMaintain;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

class GDThumb_maintain extends PluginMaintain {
  private $installed = false;
  
  function install($plugin_version, &$errors=array()) {
    include(dirname(__FILE__).'/config_default.php');
    global $conf;
    if (empty($conf['gdThumb'])):
      functions::conf_update_param('gdThumb', $config_default, true);
    endif;

    $this->installed = true;
  }

  function update($old_version, $new_version, &$errors=array()) {
    $this->install($new_version, $errors);
  }

  function activate($plugin_version, &$errors=array()) {
    if (!$this->installed):
      $this->install($plugin_version, $errors);
      $this->cleanUp();
    endif;
  }

  function uninstall() {
    $this->cleanUp();
    functions::conf_delete_param('gdThumb');
  }

  private function cleanUp() {
    if (is_dir(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'GDThumb')):
      $this->gtdeltree(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'GDThumb');
    endif;
  }

  private function gtdeltree($path) {
    if (is_dir($path)):
      $fh = opendir($path);
      while ($file = readdir($fh)) {
        if ($file != '.' and $file != '..'):
          $pathfile = $path . '/' . $file;
          if (is_dir($pathfile)):
            self::gtdeltree($pathfile);
          else:
            @unlink($pathfile);
          endif;
        endif;
      }
      closedir($fh);
      return @rmdir($path);
    endif;
  }

}

?>