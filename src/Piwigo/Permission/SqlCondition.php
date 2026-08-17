<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\Query\Expr;

/**
 * Bound-parameter carrier for a SQL WHERE-clause fragment --
 * `PermissionService::getSqlConditionFandFAsCondition()`'s own output
 * contract, replacing the raw-string-splicing `getSqlConditionFandF()`
 * that initially shipped alongside it (deleted once every real call
 * site had migrated here).
 *
 * `$expr` is a real `Doctrine\ORM\Query\Expr\Base` tree, not raw text --
 * `combine()` composes fragments via `Expr\Andx`/`Expr\Orx` instead of
 * manual string concatenation, so a compound fragment (one already
 * containing its own top-level `AND`/`OR`) is parenthesized correctly
 * and automatically when nested into another combine() call, the same
 * way a hand-written query would need to be, but construction-enforced
 * rather than left to a call site's own "already parenthesized, don't
 * wrap again" comment convention. Every leaf fragment is still built as
 * plain text via {@see fromRawSql()} -- rewriting the ~10 leaf condition
 * builders (`PermissionCriteria`, `ImageFilterCriteria`,
 * `SearchFilterClause`) to build real `Expr\Comparison`/`Expr\Func`
 * objects instead is a separate, deeper refinement this class's own
 * conversion doesn't require.
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
        public Expr\Base $expr,
        public array $parameters = [],
        public array $types = [],
    ) {}

    /**
     * The migration path for every existing `new SqlCondition($sql, ...)`
     * call site -- wraps $sql as a single-part `Expr\Andx`, which
     * stringifies back to the identical bare text (see `Expr\Base::
     * __toString()`: count() === 1 returns the bare part, no parens
     * added) and reports isEmpty() correctly for `''`.
     *
     * @param array<string, mixed> $parameters
     * @param array<string, ArrayParameterType|ParameterType> $types
     */
    public static function fromRawSql(string $sql, array $parameters = [], array $types = []): self
    {
        return new self($sql === '' ? new Expr\Andx() : new Expr\Andx([$sql]), $parameters, $types);
    }

    public function isEmpty(): bool
    {
        return $this->expr->count() === 0;
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
     *
     * Always stringifies -- DBAL's own `QueryBuilder::andWhere()` is typed
     * `string|CompositeExpression`, not `Stringable`, so a bare `Expr\Base`
     * object fails a strict param-type check there even though ORM's own
     * `QueryBuilder` would accept it directly. Verified safe against the
     * real vendored source, not assumed: `Expr\Composite::
     * processQueryPart()` (the renderer behind `Andx`/`Orx`) wraps a
     * nested part in parens whenever that part is itself a multi-part
     * `Composite`, or whenever its own rendered text contains a bare
     * ` AND `/` OR ` -- which also catches a single-part `Expr\Andx` built
     * by {@see fromRawSql()} from raw text that already has its own
     * internal `AND`/`OR`, since a count-1 `Composite` renders as its bare
     * part before that regex check runs against it. Confirmed live:
     * `(string) new Expr\Andx(['top = :t', new Expr\Orx(['x = :x', 'y =
     * :y'])])` -> `'top = :t AND (x = :x OR y = :y)'`. Stringifying early
     * loses no precedence either direction.
     */
    public function applyTo(QueryBuilder|\Doctrine\ORM\QueryBuilder $qb): void
    {
        if ($this->isEmpty()) {
            return;
        }

        $qb->andWhere((string) $this->expr);
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
        return $this->isEmpty() ? '' : 'WHERE ' . (string) $this->expr;
    }

    /**
     * Merges several fragments into one, glued by $glue (typically
     * 'AND') -- for call sites that build up a $whereClauses-shaped list
     * of several SqlCondition results before finalizing one query. Empty
     * fragments are dropped rather than glued in (an empty fragment
     * glued with 'AND' would otherwise produce e.g. "(x) AND ()").
     *
     * $glue is genuinely dynamic at 2 real call sites
     * (`SearchService::buildFilterClauses()`'s own `combine('OR', ...)`
     * and `combine($allwordsMode, ...)`, a runtime variable), which is
     * why this branches on it rather than always building an `Andx`.
     */
    public static function combine(string $glue, self ...$conditions): self
    {
        $nonEmpty = array_values(array_filter(
            $conditions,
            static fn (self $condition): bool => ! $condition->isEmpty(),
        ));

        if ($nonEmpty === []) {
            return new self(new Expr\Andx());
        }

        if (count($nonEmpty) === 1) {
            return $nonEmpty[0];
        }

        $expr = $glue === 'OR' ? new Expr\Orx() : new Expr\Andx();
        foreach ($nonEmpty as $condition) {
            $expr->add($condition->expr);
        }

        $parameters = [];
        $types = [];
        foreach ($nonEmpty as $condition) {
            $parameters = [...$parameters, ...$condition->parameters];
            $types = [...$types, ...$condition->types];
        }

        return new self($expr, $parameters, $types);
    }
}
