<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Simple wrapper around an array (keys are consecutive integers starting at 0).
 * Provides naming clues for xml output (xml attributes vs. xml child elements?)
 * Usually returned by web service function implementation.
 */
class PwgNamedArray
{
    /*private*/
    /**
     * @var mixed[]
     */
    public $_xmlAttributes;

    /**
     * Constructs a named array
     * @param mixed $_content array (keys must be consecutive integers starting at 0)
     * @param string $_itemName xml element name for values of arr (e.g. image)
     * @param array $xmlAttributes sub-item attributes that will be encoded as xml attributes
     */
    public function __construct(public $_content, public $_itemName, $xmlAttributes = [])
    {
        $this->_xmlAttributes = array_flip($xmlAttributes);
    }
}
