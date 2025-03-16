<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Simple wrapper around an array (keys are consecutive integers starting at 0).
 * Provides naming clues for xml output (xml attributes vs. xml child elements?)
 * Usually returned by web service function implementation.
 */
class PwgNamedArray
{
    public $_content;

    public $_itemName;

    public $_xmlAttributes;

    /**
     * Constructs a named array
     * @param array $arr (keys must be consecutive integers starting at 0)
     * @param string $itemName xml element name for values of arr (e.g. image)
     * @param array $xmlAttributes of sub-item attributes that will be encoded as
     *      xml attributes instead of xml child elements
     */
    public function __construct($arr, $itemName, $xmlAttributes = [])
    {
        $this->_content = $arr;
        $this->_itemName = $itemName;
        $this->_xmlAttributes = array_flip($xmlAttributes);
    }
}
