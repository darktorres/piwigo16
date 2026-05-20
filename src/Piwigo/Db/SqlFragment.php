<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * Triplet returned by query-fragment builders such as
 * `PermissionService::getSqlConditionFandF()`: a parameterized SQL
 * fragment with its positional bind parameters and types, lined up by
 * index for `executeQuery()`-style calls.
 *
 * Replaces the prior `array{0: string, 1: list<mixed>, 2: list<...>}`
 * triple-destructuring pattern with named properties; the `0/1/2`
 * indices were position-meaningful but easy to mis-read at call sites.
 */
final readonly class SqlFragment
{
    /**
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     */
    public function __construct(
        public string $where,
        public array  $params = [],
        public array  $types  = [],
    ) {
    }

    /**
     * Prepend a connector ('AND', "\n  AND", 'WHERE', …) when the
     * fragment is non-empty. Returns a new SqlFragment so the original
     * stays usable. Empty fragments are returned unchanged.
     */
    public function prefixWith(string $connector): self
    {
        if ($this->where === '') {
            return $this;
        }
        return new self($connector . ' ' . $this->where, $this->params, $this->types);
    }
}
