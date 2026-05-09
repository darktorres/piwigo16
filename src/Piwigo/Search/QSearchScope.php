<?php

declare(strict_types=1);

namespace Piwigo\Search;

/**
 * A search scope applies to a single token and restricts the search to a subset of searchable fields.
 */
class QSearchScope
{
    /**
     * @param string[] $aliases
     */
    public function __construct(public readonly string $id, public readonly array $aliases, public readonly bool $nullable = false, public readonly bool $is_text = true)
    {
    }

    public function parse(QSingleToken $token): bool
    {
        if (!$this->nullable && 0 == strlen($token->term)) {
            return false;
        }
        return true;
    }

    public function processChar(string &$ch, string &$crt_token): bool
    {
        return false;
    }

    public function getSql(string $field, QSingleToken $token): string
    {
        return '';
    }
}
