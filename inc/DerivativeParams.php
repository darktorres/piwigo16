<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * All needed parameters to generate a derivative image.
 */
final class DerivativeParams
{
  /** @var SizingParams */
  public $sizing;
  /** @var string among IMG_* */
  public $type = derivative_std_params::IMG_CUSTOM;
  /** @var int used for non-custom images to regenerate the cached files */
  public $last_mod_time = 0;
  /** @var bool */
  public $use_watermark = false;
  /** @var float from 0=no sharpening to 1=max sharpening */
  public $sharpen = 0;

  /**
   * @param SizingParams $sizing
   */
  function __construct($sizing)
  {
    $this->sizing = $sizing;
  }

  /**
   * @return array
   */
  public function __sleep()
  {
    return array('last_mod_time', 'sizing', 'sharpen');
  }

  /**
   * Adds tokens depending on sizing configuration.
   *
   * @param array &$tokens
   */
  function add_url_tokens(&$tokens)
  {
    $this->sizing->add_url_tokens($tokens);
  }

  /**
   * @return int[]
   */
  function compute_final_size($in_size)
  {
    $this->sizing->compute( $in_size, null, $crop_rect, $scale_size );
    return $scale_size != null ? $scale_size : $in_size;
  }

  /**
   * @return int
   */
  function max_width()
  {
    return $this->sizing->ideal_size[0];
  }

  /**
   * @return int
   */
  function max_height()
  {
    return $this->sizing->ideal_size[1];
  }

  /**
   * @todo : description of DerivativeParams::is_identity
   *
   * @return bool
   */
  function is_identity($in_size)
  {
    if ($in_size[0] > $this->sizing->ideal_size[0] or
        $in_size[1] > $this->sizing->ideal_size[1] )
    {
      return false;
    }
    return true;
  }

  /**
   * @return bool
   */
  function will_watermark($out_size)
  {
    if ($this->use_watermark)
    {
      $min_size = ImageStdParams::get_watermark()->min_size;
      return $min_size[0]<=$out_size[0]
        || $min_size[1]<=$out_size[1];
    }
    return false;
  }
}

?>