<?php

declare(strict_types=1);

namespace Piwigo\Search;

/** Represents a single word or quoted phrase to be searched.*/
class QSingleToken implements \Stringable
{
    public $is_single = true; /* the actual word/phrase string*/
    public $variants = [];

    public $scope_data;
    public $idx;

    public function __construct(public $term, public $modifier, public $scope)
    {
    }

    public function __toString(): string
    {
        $s = '';
        if (isset($this->scope)) {
            $s .= $this->scope->id .':';
        }
        if ($this->modifier & QST_WILDCARD_BEGIN) {
            $s .= '*';
        }
        if ($this->modifier & QST_QUOTED) {
            $s .= '"';
        }
        $s .= $this->term;
        if ($this->modifier & QST_QUOTED) {
            $s .= '"';
        }
        if ($this->modifier & QST_WILDCARD_END) {
            $s .= '*';
        }
        return $s;
    }
}
