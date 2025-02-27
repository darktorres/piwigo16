<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Simple wrapper around an array (keys are consecutive integers starting at 0).
 * Provides naming clues for xml output (xml attributes vs. xml child elements?)
 * Usually returned by web service function implementation.
 */
class PwgNamedArray
{
  /*private*/ var $_content;
  /*private*/ var $_itemName;
  /*private*/ var $_xmlAttributes;

  /**
   * Constructs a named array
   * @param arr array (keys must be consecutive integers starting at 0)
   * @param itemName string xml element name for values of arr (e.g. image)
   * @param xmlAttributes array of sub-item attributes that will be encoded as
   *      xml attributes instead of xml child elements
   */
  function __construct($arr, $itemName, $xmlAttributes=array() )
  {
    $this->_content = $arr;
    $this->_itemName = $itemName;
    $this->_xmlAttributes = array_flip($xmlAttributes);
  }
}

?>
