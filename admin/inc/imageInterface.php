<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

// +-----------------------------------------------------------------------+
// |                           Image Interface                             |
// +-----------------------------------------------------------------------+

// Define all needed methods for image class
interface imageInterface
{
  function get_width();

  function get_height();

  function set_compression_quality($quality);

  function crop($width, $height, $x, $y);

  function strip();

  function rotate($rotation);

  function resize($width, $height);

  function sharpen($amount);

  function compose($overlay, $x, $y, $opacity);

  function write($destination_filepath);
}

?>
