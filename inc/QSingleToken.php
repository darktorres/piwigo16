<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/** Represents a single word or quoted phrase to be searched.*/
class QSingleToken
{
    public bool $is_single = true;

    public int $modifier;

    public string $term; /* the actual word/phrase string*/

    public array $variants = [];

    public QSearchScope $scope;

    public array $scope_data;

    public int $idx;

    public function __construct(
        string $term,
        int $modifier,
        QSearchScope $scope
    ) {
        $this->term = $term;
        $this->modifier = $modifier;
        $this->scope = $scope;
    }

    public function __toString(): string
    {
        $s = '';

        if (isset($this->scope)) {
            $s .= $this->scope->id . ':';
        }

        if ($this->modifier & functions_search::QST_WILDCARD_BEGIN) {
            $s .= '*';
        }

        if ($this->modifier & functions_search::QST_QUOTED) {
            $s .= '"';
        }

        $s .= $this->term;

        if ($this->modifier & functions_search::QST_QUOTED) {
            $s .= '"';
        }

        if ($this->modifier & functions_search::QST_WILDCARD_END) {
            $s .= '*';
        }

        return $s;
    }
}
