<?php

declare(strict_types=1);

namespace Piwigo\Db\DqlFunction;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * Custom DQL function: "GROUP_CONCAT" "(" SimpleArithmeticExpression ")",
 * always comma-separated (MySQL's own GROUP_CONCAT default separator,
 * made explicit here rather than relied upon).
 *
 * No `AbstractPlatform` primitive exists for this -- MySQL/MariaDB's
 * `GROUP_CONCAT(expr SEPARATOR ',')`, PostgreSQL's `STRING_AGG(expr,
 * ',')` (needs an explicit `::text`/`CAST` since `string_agg()` requires
 * a text argument, unlike MySQL's GROUP_CONCAT which accepts any type),
 * and SQLite's own `GROUP_CONCAT(expr, ',')` (positional separator, no
 * `SEPARATOR` keyword) are different enough that a single portable SQL
 * string doesn't exist -- this hand-rolls the same
 * per-`instanceof`-branch dispatch shape
 * `AbstractPlatform::getRegexpExpression()` itself uses internally, since
 * DBAL doesn't ship the primitive for us.
 *
 * MySQL/MariaDB and PostgreSQL branches are both verified against real
 * data via this project's own Integration tests. SQLite is unverified
 * against a real installation (no SQLite install/schema exists in this
 * repo) -- built from its own documented GROUP_CONCAT syntax, not
 * empirically confirmed. Any other platform (SQL Server, Oracle, DB2)
 * throws `NotSupported` rather than guessing at an unverified syntax for
 * a platform the user never named as a target.
 */
final class GroupConcatFunction extends FunctionNode
{
    private Node|string $expression;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $column = $sqlWalker->walkSimpleArithmeticExpression($this->expression);

        if ($platform instanceof AbstractMySQLPlatform) {
            return "GROUP_CONCAT({$column} SEPARATOR ',')";
        }

        if ($platform instanceof SQLitePlatform) {
            return "GROUP_CONCAT({$column}, ',')";
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return "STRING_AGG(CAST({$column} AS TEXT), ',')";
        }

        throw NotSupported::new(self::class . '::getSql() for ' . $platform::class);
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->expression = $parser->SimpleArithmeticExpression();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
