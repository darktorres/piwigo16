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
 * Simple wrapper around a "struct" (php array whose keys are not consecutive
 * integers starting at 0). Provides naming clues for xml output (what is xml
 * attributes and what is element)
 */
class PwgNamedStruct
{
    /* private */
    /**
     * @var array<string, int>
     */
    public $_xmlAttributes;

    /**
     * Constructs a named struct (usually returned by web service function
     * implementation)
     * @param array<string, mixed> $_content the actual content (php array)
     * @param string[]|null $xmlAttributes name of the keys in $content that will be
     *    encoded as xml attributes (if null - automatically prefer xml attributes
     *    whenever possible)
     * @param string[]|null $xmlElements keys in $content to always treat as xml elements
     */
    public function __construct(
        public array $_content,
        ?array $xmlAttributes = null,
        ?array $xmlElements = null
    ) {
        if (isset($xmlAttributes)) {
            $this->_xmlAttributes = array_flip($xmlAttributes);
        } else {
            $this->_xmlAttributes = [];
            foreach ($this->_content as $key => $value) {
                if (! in_array($key, ['', 0, '0'], true) and (is_scalar($value) or $value === null)) {
                    if ($xmlElements === null || $xmlElements === [] or ! in_array((string) $key, $xmlElements, true)) {
                        $this->_xmlAttributes[$key] = 1;
                    }
                }
            }
        }
    }
}
