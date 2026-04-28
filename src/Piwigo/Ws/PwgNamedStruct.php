<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Simple wrapper around a "struct" (php array whose keys are not consecutive
 * integers starting at 0). Provides naming clues for xml output (what is xml
 * attributes and what is element)
 */
class PwgNamedStruct
{
    /*private*/
    /** @var array<mixed> */
    public array $_xmlAttributes = [];

    /**
     * Constructs a named struct (usually returned by web service function
     * implementation)
     * @param mixed $_content the actual content (php array)
     * @param array|null $xmlAttributes name of the keys to encode as xml attributes
     * @param array|null $xmlElements name of the keys to encode as xml elements
     */
    /**
     * @param string[]|null $xmlAttributes
     * @param string[]|null $xmlElements
     */
    public function __construct(public mixed $_content, ?array $xmlAttributes = null, ?array $xmlElements = null)
    {
        if (isset($xmlAttributes)) {
            $this->_xmlAttributes = array_flip($xmlAttributes);
        } else {
            $this->_xmlAttributes = [];
            foreach ($this->_content as $key => $value) {
                if (!empty($key) and (is_scalar($value) or is_null($value))) {
                    if (empty($xmlElements) or !in_array($key, $xmlElements)) {
                        $this->_xmlAttributes[$key] = 1;
                    }
                }
            }
        }
    }
}
