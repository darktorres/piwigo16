<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * Bound-parameter carrier for a SQL WHERE-clause fragment --
 * `PermissionService::getSqlConditionFandFAsCondition()`'s own output
 * contract, replacing the raw-string-splicing `getSqlConditionFandF()`
 * that initially shipped alongside it (deleted once every real call
 * site had migrated here).
 *
 * Apply via `QueryBuilder::andWhere($condition->sql)->setParameters([
 * ...$condition->parameters])` (with `$condition->types` passed as the
 * matching `setParameter()` type per key, or via `setParameters()`'s own
 * `$types` argument), or as the 2nd/3rd args to `Connection::fetchOne()`/
 * `fetchAllAssociative()` for raw-`Connection`-based callers.
 */
final readonly class SqlCondition
{
    /**
     * @param array<string, mixed> $parameters
     * @param array<string, ArrayParameterType|ParameterType> $types
     */
    public function __construct(
        public string $sql,
        public array $parameters = [],
        public array $types = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->sql === '';
    }

    /**
     * Merges several fragments into one, glued by $glue (typically
     * 'AND') -- for call sites that build up a $whereClauses-shaped list
     * of several SqlCondition results before finalizing one query. Empty
     * fragments are dropped rather than glued in (an empty fragment
     * glued with 'AND' would otherwise produce e.g. "(x) AND ()").
     */
    public static function combine(string $glue, self ...$conditions): self
    {
        $nonEmpty = array_values(array_filter(
            $conditions,
            static fn (self $condition): bool => ! $condition->isEmpty(),
        ));

        if ($nonEmpty === []) {
            return new self('');
        }

        if (count($nonEmpty) === 1) {
            return $nonEmpty[0];
        }

        $parameters = [];
        $types = [];
        foreach ($nonEmpty as $condition) {
            $parameters = [...$parameters, ...$condition->parameters];
            $types = [...$types, ...$condition->types];
        }

        return new self(
            implode(' ' . $glue . ' ', array_map(
                static fn (self $condition): string => $condition->sql,
                $nonEmpty,
            )),
            $parameters,
            $types,
        );
    }
}
