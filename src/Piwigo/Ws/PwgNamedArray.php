<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

/**
 * Simple wrapper around an array (keys are consecutive integers starting at 0).
 * Provides naming clues for xml output (xml attributes vs. xml child elements?)
 * Usually returned by web service function implementation.
 */
final class PwgNamedArray
{
    /* private */
    /**
     * @var array<string, int>
     */
    public $_xmlAttributes;

    /**
     * Constructs a named array
     * @param array<int, mixed> $_content (keys must be consecutive integers starting at 0)
     * @param string $_itemName xml element name for values of arr (e.g. image)
     * @param string[] $xmlAttributes of sub-item attributes that will be encoded as
     *      xml attributes instead of xml child elements
     */
    public function __construct(
        public array $_content,
        public string $_itemName,
        array $xmlAttributes = []
    ) {
        $this->_xmlAttributes = array_flip($xmlAttributes);
    }
}
