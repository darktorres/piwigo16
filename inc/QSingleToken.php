<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/** Represents a single word or quoted phrase to be searched.*/
final class QSingleToken implements \Stringable
{
    public bool $is_single = true; /* the actual word/phrase string*/

    public array $variants = [];

    public array $scope_data;

    public int $idx;

    public function __construct(
        public string $term,
        public int $modifier,
        public QSearchScope $scope
    ) {}

    public function __toString(): string
    {
        $s = '';

        if (isset($this->scope)) {
            $s .= $this->scope->id . ':';
        }

        if (($this->modifier & functions_search::QST_WILDCARD_BEGIN) !== 0) {
            $s .= '*';
        }

        if (($this->modifier & functions_search::QST_QUOTED) !== 0) {
            $s .= '"';
        }

        $s .= $this->term;

        if (($this->modifier & functions_search::QST_QUOTED) !== 0) {
            $s .= '"';
        }

        if (($this->modifier & functions_search::QST_WILDCARD_END) !== 0) {
            $s .= '*';
        }

        return $s;
    }
}
