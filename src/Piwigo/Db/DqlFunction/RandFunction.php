<?php

declare(strict_types=1);

namespace Piwigo\Db\DqlFunction;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * Custom DQL function: "RAND" "(" ")" -- a random value per row, meant for
 * `ORDER BY RAND()`-style "pick one random row" queries, matching
 * {@see \Piwigo\Db\SqlDialect}'s own `DB_RANDOM_FUNCTION` constant.
 *
 * No `AbstractPlatform` primitive exists for a portable "random value"
 * expression. MySQL/MariaDB's `RAND()` and PostgreSQL/SQLite's
 * `RANDOM()` are both real, so this hand-rolls the same
 * per-`instanceof`-branch dispatch shape {@see GroupConcatFunction}'s
 * own docblock explains. MySQL/MariaDB and PostgreSQL branches are both
 * verified against real data via this project's own Integration tests;
 * SQLite is unverified against a real installation (no SQLite
 * install/schema exists in this repo). Any other platform (SQL Server's
 * `NEWID()`, Oracle, DB2) throws `NotSupported` rather than guessing at
 * an unverified syntax for a platform the user never named as a target.
 */
final class RandFunction extends FunctionNode
{
    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            return 'RAND()';
        }

        if ($platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform) {
            return 'RANDOM()';
        }

        throw NotSupported::new(self::class . '::getSql() for ' . $platform::class);
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
