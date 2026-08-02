<?php

declare(strict_types=1);

namespace Piwigo\Db\DqlFunction;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Custom DQL function: "REGEXP" "(" StringPrimary "," StringPrimary ")",
 * compiling to an infix `left <op> right` regex-match predicate.
 *
 * Further SQL-modernization audit, Item 14 Sub-phase B5 Tier 1: genuinely
 * portable, unlike a hardcoded `REGEXP` splice -- `getSql()` reads the
 * operator via `AbstractPlatform::getRegexpExpression()`, which resolves
 * to `RLIKE` on MySQL/MariaDB ({@see
 * \Doctrine\DBAL\Platforms\AbstractMySQLPlatform}), `REGEXP` on SQLite,
 * and `SIMILAR TO` on PostgreSQL -- the same primitive Item 16's own
 * `SqlDialect::DB_REGEX_OPERATOR` fix targets. Caveat, not yet verified
 * against a real non-MySQL-family database: PostgreSQL's `SIMILAR TO`
 * uses a different pattern-matching dialect than POSIX `REGEXP`
 * (implicit whole-string anchoring, no `^`/`$` needed the same way) --
 * real callers passing a POSIX-style pattern (e.g. `(^|,)123(,|$)`)
 * would need a Postgres-specific pattern rewrite too, not just the
 * operator swap this function provides. That's a real remaining gap
 * for genuine Postgres support, left for whenever a real Postgres
 * environment exists to verify against (no `install/schema/pgsql.sql`
 * exists yet in this repo -- see this plan's own Context section).
 */
final class RegexpFunction extends FunctionNode
{
    private Node $column;

    private Node $pattern;

    #[\Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $operator = $sqlWalker->getConnection()
            ->getDatabasePlatform()
            ->getRegexpExpression();

        return $sqlWalker->walkStringPrimary($this->column) . ' ' . $operator . ' ' . $sqlWalker->walkStringPrimary($this->pattern);
    }

    #[\Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->column = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->pattern = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
