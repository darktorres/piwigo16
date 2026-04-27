<?php

declare(strict_types=1);

namespace Piwigo\Search;

/**
 * A search scope applies to a single token and restricts the search to a subset of searchable fields.
 */
class QSearchScope
{
    public function __construct(public $id, public $aliases, public $nullable = false, public $is_text = true)
    {
    }

    public function parse($token): bool
    {
        if (!$this->nullable && 0 == strlen((string) $token->term)) {
            return false;
        }
        return true;
    }

    public function process_char(&$ch, &$crt_token): bool
    {
        return false;
    }
}
