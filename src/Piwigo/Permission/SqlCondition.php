<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Bound-parameter carrier for a SQL WHERE-clause fragment --
 * `PermissionService::getSqlConditionFandFAsCondition()`'s own output
 * contract, replacing the raw-string-splicing `getSqlConditionFandF()`
 * that initially shipped alongside it (deleted once every real call
 * site had migrated here).
 *
 * Apply via {@see applyTo()} against a DBAL or ORM query builder, or splice
 * {@see toWhereClause()} into hand-built SQL and pass `->parameters`/
 * `->types` as the 2nd/3rd args to `Connection::fetchOne()`/
 * `fetchAllAssociative()`. Both treat an empty fragment as "no filter"
 * rather than requiring callers to substitute a `1=1` tautology.
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
     * Applies this fragment to a DBAL or ORM query builder, binding every
     * parameter it carries. A no-op when empty, which is what lets callers
     * drop the `1=1` placeholder they would otherwise need to keep a
     * `WHERE` clause syntactically valid.
     *
     * Both builders expose the same andWhere()/setParameter() surface here;
     * `Doctrine\ORM\QueryBuilder` is named in full because DBAL's is the
     * one imported above.
     */
    public function applyTo(QueryBuilder|\Doctrine\ORM\QueryBuilder $qb): void
    {
        if ($this->isEmpty()) {
            return;
        }

        $qb->andWhere($this->sql);
        foreach ($this->parameters as $name => $value) {
            $qb->setParameter($name, $value, $this->types[$name] ?? ParameterType::STRING);
        }
    }

    /**
     * This fragment as a complete `WHERE` clause for splicing into raw SQL,
     * or an empty string when there is nothing to filter on -- the raw-SQL
     * counterpart of {@see applyTo()}, and the reason a caller building its
     * query as text no longer has to seed the fragment list with `1=1` just
     * to avoid emitting a bare `WHERE`.
     */
    public function toWhereClause(): string
    {
        return $this->isEmpty() ? '' : 'WHERE ' . $this->sql;
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
