<?php

declare(strict_types=1);

namespace Piwigo\Db\DqlFunction;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * Custom DQL function: "COUNT_OVER" "(" ")" -- emits the literal window
 * function `COUNT(*) OVER()`, computing a paginated query's total row
 * count in the same round trip as the row data instead of a second query.
 * Registered under a synthetic name, deliberately not `COUNT` -- that
 * would override the real built-in aggregate every other `COUNT(...)`/
 * `COUNT(DISTINCT ...)` call site in this codebase relies on.
 *
 * No per-platform branching: MySQL 8.0.2+, PostgreSQL, and SQLite
 * 3.25.0+ (2018) all support `COUNT(*) OVER()` identically -- verified
 * live against a real sqlite3 connection -- matching every existing
 * raw-SQL call site that already uses it unconditionally.
 */
final class CountOverFunction extends FunctionNode
{
    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'COUNT(*) OVER()';
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
