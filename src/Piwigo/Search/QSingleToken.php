<?php

declare(strict_types=1);

namespace Piwigo\Search;

/** Represents a single word or quoted phrase to be searched.*/
final class QSingleToken implements \Stringable
{
    public bool $is_single = true; /* the actual word/phrase string*/
    /** @var string[] */
    public array $variants = [];

    public mixed $scope_data = null;
    public int $idx = 0;

    public function __construct(public string $term, public int $modifier, public ?QSearchScope $scope)
    {
    }

    #[\Override]
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
