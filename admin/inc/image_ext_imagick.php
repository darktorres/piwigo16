<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

// +-----------------------------------------------------------------------+
// |            Class for ImageMagick external installation                |
// +-----------------------------------------------------------------------+

class image_ext_imagick implements imageInterface
{
  var $imagickdir = '';
  var $source_filepath = '';
  var $width = '';
  var $height = '';
  var $is_animated_webp = false;
  var $commands = array();

  function __construct($source_filepath)
  {
    global $conf;
    $this->source_filepath = $source_filepath;
    $this->imagickdir = $conf['ext_imagick_dir'];

    if (strpos(@$_SERVER['SCRIPT_FILENAME'], '/kunden/') === 0)  // 1and1
    {
      @putenv('MAGICK_THREAD_LIMIT=1');
    }

    if ('webp' == strtolower(get_extension($source_filepath)))
    {
      $webp_info = pwg_image::webp_info($source_filepath);

      if ($webp_info['has-animation'])
      {
        $this->is_animated_webp = true;

        // ImageMagick "identify" returns the list of width x height for each
        // frame, such as "400x300400x300400x300" (3 frames of 400x300), as a big
        // string, impossible to parse :-/ So let's use the PHP embedded function
        // getimagesize here.
        list($this->width, $this->height) = getimagesize($source_filepath);
        return;
      }
    }

    $command = $this->imagickdir.'identify -format "%wx%h" "'.realpath($source_filepath).'"';
    @exec($command, $returnarray);
    if(!is_array($returnarray) or empty($returnarray[0]) or !preg_match('/^(\d+)x(\d+)$/', $returnarray[0], $match))
    {
      die("[External ImageMagick] Corrupt image\n" . var_export($returnarray, true));
    }

    $this->width = $match[1];
    $this->height = $match[2];
  }

  function add_command($command, $params=null)
  {
    $this->commands[$command] = $params;
  }

  function get_width()
  {
    return $this->width;
  }

  function get_height()
  {
    return $this->height;
  }

  function crop($width, $height, $x, $y)
  {
    $this->width = $width;
    $this->height = $height;

    // the final "!" is added to crop the canva too, for animated picture (with WebP in mind)
    $this->add_command('crop', $width.'x'.$height.'+'.$x.'+'.$y.'!');
    return true;
  }

  function strip()
  {
    $this->add_command('strip');
    return true;
  }

  function rotate($rotation)
  {
    if (empty($rotation))
    {
      return true;
    }

    if ($rotation==90 || $rotation==270)
    {
      $tmp = $this->width;
      $this->width = $this->height;
      $this->height = $tmp;
    }
    $this->add_command('rotate', -$rotation);
    $this->add_command('orient', 'top-left');
    return true;
  }

  function set_compression_quality($quality)
  {
    global $conf;

    if ($this->is_animated_webp)
    {
      // in cas of animated WebP, we need to maximize quality to 70 to avoid
      // heavy thumbnails (or square or whatever is displayed on the thumbnails
      // page)
      $quality = min($quality, $conf['animated_webp_compression_quality']);
    }

    $this->add_command('quality', $quality);
    return true;
  }

  function resize($width, $height)
  {
    $this->width = $width;
    $this->height = $height;

    $this->add_command('filter', 'Lanczos');
    $this->add_command('resize', $width.'x'.$height.'!');
    return true;
  }

  function sharpen($amount)
  {
    $m = pwg_image::get_sharpen_matrix($amount);

    $param ='convolve "'.count($m).':';
    foreach ($m as $line)
    {
      $param .= ' ';
      $param .= implode(',', $line);
    }
    $param .= '"';
    $this->add_command('morphology', $param);
    return true;
  }

  function compose($overlay, $x, $y, $opacity)
  {
    $param = 'compose dissolve -define compose:args='.$opacity;
    $param .= ' '.escapeshellarg(realpath($overlay->image->source_filepath));
    $param .= ' -gravity NorthWest -geometry +'.$x.'+'.$y;
    $param .= ' -composite';
    $this->add_command($param);
    return true;
  }

  function write($destination_filepath)
  {
    global $logger;

    $this->add_command('interlace', 'line'); // progressive rendering
    // use 4:2:2 chroma subsampling (reduce file size by 20-30% with "almost" no human perception)
    //
    // option deactivated for Piwigo 2.4.1, it doesn't work fo old versions
    // of ImageMagick, see bug:2672. To reactivate once we have a better way
    // to detect IM version and when we know which version supports this
    // option
    //
    if (version_compare(pwg_image::$ext_imagick_version, '6.6') > 0)
    {
      $this->add_command('sampling-factor', '4:2:2' );
    }

    $exec = $this->imagickdir.'convert';
    $exec .= ' "'.realpath($this->source_filepath).'"';

    foreach ($this->commands as $command => $params)
    {
      $exec .= ' -'.$command;
      if (!empty($params))
      {
        $exec .= ' '.$params;
      }
    }

    $dest = pathinfo($destination_filepath);
    $exec .= ' "'.realpath($dest['dirname']).'/'.$dest['basename'].'" 2>&1';
    $logger->debug($exec);
    @exec($exec, $returnarray);

    if (is_array($returnarray) && (count($returnarray)>0) )
    {
      $logger->error('', $returnarray);
      foreach ($returnarray as $line)
        trigger_error($line, E_USER_WARNING);
    }
    return is_array($returnarray);
  }
}

?>
