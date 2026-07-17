<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Search;

/**
 * Represents a single word or quoted phrase to be searched.
 *
 * P23 batch 8f-4: the QST_ quick-search token modifier bitmask flags moved
 * here as class constants (formerly top-level define()s in the deleted
 * include/functions.inc.php, relocated there from
 * include/functions_search.inc.php in batch 8c). This class hosts them
 * because they describe exactly its own $modifier property; every other
 * reader (QMultiToken/QExpression/SearchService/QDateRangeScope/
 * QNumericRangeScope, all in this same namespace) references them as
 * QSingleToken::QST_*.
 */
class QSingleToken implements \Stringable
{
    public const int QST_QUOTED = 0x01;

    public const int QST_NOT = 0x02;

    public const int QST_OR = 0x04;

    public const int QST_WILDCARD_BEGIN = 0x08;

    public const int QST_WILDCARD_END = 0x10;

    public const int QST_WILDCARD = self::QST_WILDCARD_BEGIN | self::QST_WILDCARD_END;

    public const int QST_BREAK = 0x20;

    public bool $is_single = true; /* the actual word/phrase string */

    /**
     * @var string[]
     */
    public $variants = [];

    /**
     * @var mixed set by QSearchScope::parse() (or a subclass override);
     *   QNumericRangeScope stores array{range: array, strict: array},
     *   QDateRangeScope stores a plain 2-element string[] range — the shape
     *   depends on which scope subclass parsed this token
     */
    public $scope_data;

    public ?int $idx = null;

    public function __construct(
        public string $term,
        public int $modifier,
        public ?QSearchScope $scope
    ) {}

    #[\Override]
    public function __toString(): string
    {
        $s = '';
        if (isset($this->scope)) {
            $s .= $this->scope->id . ':';
        }
        if ((bool) ($this->modifier & self::QST_WILDCARD_BEGIN)) {
            $s .= '*';
        }
        if ((bool) ($this->modifier & self::QST_QUOTED)) {
            $s .= '"';
        }
        $s .= $this->term;
        if ((bool) ($this->modifier & self::QST_QUOTED)) {
            $s .= '"';
        }
        if ((bool) ($this->modifier & self::QST_WILDCARD_END)) {
            $s .= '*';
        }
        return $s;
    }
}
