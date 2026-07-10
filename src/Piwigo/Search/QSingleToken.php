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
 */
class QSingleToken implements \Stringable
{
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
        if ((bool) ($this->modifier & QST_WILDCARD_BEGIN)) {
            $s .= '*';
        }
        if ((bool) ($this->modifier & QST_QUOTED)) {
            $s .= '"';
        }
        $s .= $this->term;
        if ((bool) ($this->modifier & QST_QUOTED)) {
            $s .= '"';
        }
        if ((bool) ($this->modifier & QST_WILDCARD_END)) {
            $s .= '*';
        }
        return $s;
    }
}
