<?php

declare(strict_types=1);

namespace Piwigo\Db\DqlFunction;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * Custom DQL function: "ANY_VALUE" "(" ArithmeticPrimary ")" -- a real
 * aggregate on both MySQL 8.0.13+ and PostgreSQL 16+, used to pick one
 * arbitrary (but, at every real call site in this codebase, provably
 * single-valued per group) value for a column that isn't itself part of
 * `GROUP BY` and isn't transitively functionally dependent on it under
 * PostgreSQL's stricter (non-transitive-through-a-join) rule -- see
 * {@see \Piwigo\Comment\CommentRepository::findAllWithConditions()}/
 * {@see \Piwigo\Users\UserRepository::findList()}'s own docblocks.
 *
 * No branching between MySQL/Postgres, matching this codebase's own
 * existing raw-SQL `ANY_VALUE()` usage, which already assumes MySQL
 * 8.0.13+/PostgreSQL 16+ unconditionally with no fallback for an older
 * server. SQLite has no `ANY_VALUE()` function at all, but needs no
 * wrapper of any kind to get the identical real behavior: its own
 * `GROUP BY` is already lenient about an unaggregated, non-grouped
 * column in `SELECT` (verified live: picks an arbitrary row's value with
 * no error), exactly `ANY_VALUE()`'s own semantics -- a bare passthrough
 * of the argument is the real, not approximate, SQLite equivalent.
 *
 * `parse()` accepts `ArithmeticPrimary()`, not just a bare state field --
 * `ArithmeticPrimary()` also dispatches a nested function call (e.g.
 * `IDENTITY(ic.category)`) to `FunctionDeclaration()`, so
 * `ANY_VALUE(IDENTITY(...))` parses and composes correctly:
 * {@see \Doctrine\ORM\Query\AST\Functions\IdentityFunction::getSql()}
 * returns a bare `alias.column` string with no wrapping of its own.
 */
final class AnyValueFunction extends FunctionNode
{
    private Node $expr;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $sql = $this->expr->dispatch($sqlWalker);

        if ($sqlWalker->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            return $sql;
        }

        return 'ANY_VALUE(' . $sql . ')';
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $expr = $parser->ArithmeticPrimary();
        if (is_string($expr)) {
            throw QueryException::semanticalError(
                "ANY_VALUE() doesn't support a result-variable reference as its argument."
            );
        }

        $this->expr = $expr;

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
