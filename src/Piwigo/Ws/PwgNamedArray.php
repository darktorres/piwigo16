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
    /** @var array<mixed> */
    public array $_xmlAttributes = [];

    /**
     * Constructs a named array
     * @param mixed $_content array (keys must be consecutive integers starting at 0)
     * @param string $_itemName xml element name for values of arr (e.g. image)
     * @param array $xmlAttributes sub-item attributes that will be encoded as xml attributes
     */
    /** @param string[] $xmlAttributes */
    public function __construct(private mixed $_content, public string $_itemName, array $xmlAttributes = [])
    {
        $this->_xmlAttributes = array_flip($xmlAttributes);
    }

    public function getContent(): mixed
    {
        return $this->_content;
    }
}
