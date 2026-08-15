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
 *
 * $content is genuinely arbitrary by design -- this wraps any WS method's
 * own response content generically for encoding, same rationale as
 * NamedStruct/Encoder\ResponseEncoder. Left fully `public` (not
 * `private(set)` like $xmlAttributes/$itemName below): CategoryTreeBuilder::
 * categoriesFlatlistToTree() genuinely mutates it after construction
 * (`$sub_categories->content[] = &$node;`), building a category tree
 * in a single pass via by-ref array append -- the one real external
 * writer among ~25 real construction sites.
 */
final class NamedArray
{
    /**
     * @var array<string, int>
     */
    public private(set) array $xmlAttributes;

    /**
     * Constructs a named array
     * @param array<int, mixed> $content (keys must be consecutive integers starting at 0)
     * @param string $itemName xml element name for values of arr (e.g. image)
     * @param string[] $xmlAttributes of sub-item attributes that will be encoded as
     *      xml attributes instead of xml child elements
     */
    public function __construct(
        public array $content,
        public private(set) string $itemName,
        array $xmlAttributes = []
    ) {
        $this->xmlAttributes = array_flip($xmlAttributes);
    }
}
