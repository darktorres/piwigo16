<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Implementation of Combinable for JS files.
 */
final class Script extends Combinable
{
  /** @var int 0,1,2 */
  public $load_mode;
  /** @var array */
  public $precedents;
  /** @var array */
  public $extra;

  /**
   * @param int 0,1,2
   * @param string $id
   * @param string $path
   * @param string $version
   * @param array $precedents
   */
  function __construct($load_mode, $id, $path, $version=0, $precedents=array())
  {
    parent::__construct($id, $path, $version);
    $this->load_mode = $load_mode;
    $this->precedents = $precedents;
    $this->extra = array();
  }
}

?>
