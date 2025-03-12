<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * This class contains basic functions that can be called directly from the
 * templates in the form $pwg->l10n('edit')
 */
class PwgTemplateAdapter
{
  /**
   * @deprecated use "translate" modifier
   */
  function l10n($text)
  {
    return functions::l10n($text);
  }

  /**
   * @deprecated use "translate_dec" modifier
   */
  function l10n_dec($s, $p, $v)
  {
    return functions::l10n_dec($s, $p, $v);
  }

  /**
   * @deprecated use "translate" or "sprintf" modifier
   */
  function sprintf()
  {
    $args = func_get_args();
    return call_user_func_array(sprintf(...),  $args );
  }

  /**
   * @param string $type
   * @param array $img
   * @return DerivativeImage
   */
  function derivative($type, $img)
  {
    return new DerivativeImage($type, $img);
  }

  /**
   * @param string $type
   * @param array $img
   * @return string
   */
  function derivative_url($type, $img)
  {
    return DerivativeImage::url($type, $img);
  }
}

?>
