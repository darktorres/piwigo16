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
 *
 * $content is genuinely arbitrary by design -- this wraps any WS method's
 * own response content generically for encoding, same rationale as
 * PwgNamedArray/Encoder\PwgResponseEncoder.
 */
final class PwgNamedStruct
{
    /**
     * @var array<array-key, int>
     */
    public $xmlAttributes;

    /**
     * Constructs a named struct (usually returned by web service function
     * implementation)
     * @param array<array-key, mixed> $content the actual content (php array) --
     *    a "struct" is defined by non-consecutive keys, which includes
     *    genuine int keys (e.g. a numeric key mixed with string keys, or a
     *    non-zero-starting int-keyed array), not just string keys
     * @param string[]|null $xmlAttributes name of the keys in $content that will be
     *    encoded as xml attributes (if null - automatically prefer xml attributes
     *    whenever possible)
     * @param string[]|null $xmlElements keys in $content to always treat as xml elements
     */
    public function __construct(
        public array $content,
        ?array $xmlAttributes = null,
        ?array $xmlElements = null
    ) {
        if (isset($xmlAttributes)) {
            $this->xmlAttributes = array_flip($xmlAttributes);
        } else {
            $this->xmlAttributes = [];
            foreach ($this->content as $key => $value) {
                if (! in_array($key, ['', 0, '0'], true) and (is_scalar($value) or $value === null)) {
                    if ($xmlElements === null || $xmlElements === [] or ! in_array($key, $xmlElements, true)) {
                        $this->xmlAttributes[$key] = 1;
                    }
                }
            }
        }
    }
}
