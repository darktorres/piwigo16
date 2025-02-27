<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                   Class for Imagick extension                         |
// +-----------------------------------------------------------------------+

include_once(PHPWG_ROOT_PATH.'admin/inc/imageInterface.php');

class image_imagick implements imageInterface
{
  var $image;

  function __construct($source_filepath)
  {
    // A bug cause that Imagick class can not be extended
    $this->image = new Imagick($source_filepath);
  }

  function get_width()
  {
    return $this->image->getImageWidth();
  }

  function get_height()
  {
    return $this->image->getImageHeight();
  }

  function set_compression_quality($quality)
  {
    return $this->image->setImageCompressionQuality($quality);
  }

  function crop($width, $height, $x, $y)
  {
    return $this->image->cropImage($width, $height, $x, $y);
  }

  function strip()
  {
    return $this->image->stripImage();
  }

  function rotate($rotation)
  {
    $this->image->rotateImage(new ImagickPixel(), -$rotation);
    $this->image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    return true;
  }

  function resize($width, $height)
  {
    $this->image->setInterlaceScheme(Imagick::INTERLACE_LINE);

    // TODO need to explain this condition
    if ($this->get_width()%2 == 0
        && $this->get_height()%2 == 0
        && $this->get_width() > 3*$width)
    {
      $this->image->scaleImage($this->get_width()/2, $this->get_height()/2);
    }

    return $this->image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 0.9);
  }

  function sharpen($amount)
  {
    $m = pwg_image::get_sharpen_matrix($amount);
    return  $this->image->convolveImage($m);
  }

  function compose($overlay, $x, $y, $opacity)
  {
    $ioverlay = $overlay->image->image;
    /*if ($ioverlay->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_OPAQUE)
    {
      // Force the image to have an alpha channel
      $ioverlay->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
    }*/

    global $dirty_trick_xrepeat;
    if ( !isset($dirty_trick_xrepeat) && $opacity < 100)
    {// NOTE: Using setImageOpacity will destroy current alpha channels!
      $ioverlay->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity / 100, Imagick::CHANNEL_ALPHA);
      $dirty_trick_xrepeat = true;
    }

    return $this->image->compositeImage($ioverlay, Imagick::COMPOSITE_DISSOLVE, $x, $y);
  }

  function write($destination_filepath)
  {
    // use 4:2:2 chroma subsampling (reduce file size by 20-30% with "almost" no human perception)
    $this->image->setSamplingFactors( array(2,1) );
    return $this->image->writeImage($destination_filepath);
  }
}

?>
