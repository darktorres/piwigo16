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
    public $is_single = true;

    public $modifier;

    public $term; /* the actual word/phrase string*/

    public $variants = [];

    public $scope;

    public $scope_data;

    public $idx;

    public function __construct($term, $modifier, $scope)
    {
        $this->term = $term;
        $this->modifier = $modifier;
        $this->scope = $scope;
    }

    public function __toString()
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
