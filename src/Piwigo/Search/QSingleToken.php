<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Search;

use Override;
use Stringable;

/**
 * Represents a single word or quoted phrase to be searched.
 *
 * Hosts the QST_ quick-search token modifier bitmask flags as class
 * constants because they describe exactly its own $modifier property;
 * every other reader (QMultiToken/QExpression/SearchService/
 * QDateRangeScope/QNumericRangeScope, all in this same namespace)
 * references them as QSingleToken::QST_*.
 */
final class QSingleToken implements Stringable
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
     * Set by QSearchScope::parse() (or a subclass override) -- exactly one
     * of the 2 concrete subclasses in this namespace. Each getSql() still
     * discriminates with an is_array()/isset() runtime check before
     * reading a key, but the declared type itself can be a real union of
     * both real writers' shapes; null until a real scope parses this
     * token.
     *
     * @var array{range: array{0: int|float|string, 1: int|float|string}, strict: array{0: int, 1: int}}|array{0: string, 1: string}|null
     */
    public $scope_data;

    public ?int $idx = null;

    public function __construct(
        public string $term,
        public int $modifier,
        public ?QSearchScope $scope
    ) {}

    #[Override]
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
