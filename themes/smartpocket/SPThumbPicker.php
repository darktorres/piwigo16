<?php
/*
Theme Name: Smart Pocket
Version: 14.5.0
Description: Mobile theme.
Theme URI: https://piwigo.org/ext/extension_view.php?eid=599
Author: P@t
Author URI: http://piwigo.org
*/

namespace Piwigo\themes\smartpocket;

use Piwigo\inc\DerivativeImage;
use Piwigo\inc\ImageStdParams;

class SPThumbPicker
{
  var $candidates;
  var $default;
  var $height;
  
  function init($height)
  {
    $this->candidates = array();
    foreach( ImageStdParams::get_defined_type_map() as $params)
    {
      if ($params->max_height() < $height || $params->sizing->max_crop)
        continue;
      if ($params->max_height() > 3*$height)
        break;
      $this->candidates[] = $params;
    }
    $this->default = ImageStdParams::get_custom($height*3, $height, 1, 0, $height );
    $this->height = $height;
  }
  
  function pick($src_image, $height)
  {
    $ok = false;
    foreach($this->candidates as $candidate)
    {
      $deriv = new DerivativeImage($candidate, $src_image);
      $size = $deriv->get_size();
      if ($size[1]>=$height-2)
      {
        $ok = true;
        break;
      }
    }
    if (!$ok)
    {
      $deriv = new DerivativeImage($this->default, $src_image);
    }
    return $deriv;
  }
}

?>
