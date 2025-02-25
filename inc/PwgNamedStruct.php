<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Simple wrapper around a "struct" (php array whose keys are not consecutive
 * integers starting at 0). Provides naming clues for xml output (what is xml
 * attributes and what is element)
 */
class PwgNamedStruct
{
  /*private*/ var $_content;
  /*private*/ var $_xmlAttributes;

  /**
   * Constructs a named struct (usually returned by web service function
   * implementation)
   * @param name string - containing xml element name
   * @param content array - the actual content (php array)
   * @param xmlAttributes array - name of the keys in $content that will be
   *    encoded as xml attributes (if null - automatically prefer xml attributes
   *    whenever possible)
   */
  function __construct($content, $xmlAttributes=null, $xmlElements=null )
  {
    $this->_content = $content;
    if ( isset($xmlAttributes) )
    {
      $this->_xmlAttributes = array_flip($xmlAttributes);
    }
    else
    {
      $this->_xmlAttributes = array();
      foreach ($this->_content as $key=>$value)
      {
        if (!empty($key) and (is_scalar($value) or is_null($value)) )
        {
          if ( empty($xmlElements) or !in_array($key,$xmlElements) )
          {
            $this->_xmlAttributes[$key]=1;
          }
        }
      }
    }
  }
}

?>
