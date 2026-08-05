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
 * Custom DQL function: "MONTH" "(" StringPrimary ")" -- matching MySQL's
 * own `MONTH(date)` shape (formerly {@see \Piwigo\Db\SqlDialect}'s
 * `getMonth()`, removed once Calendar -- its only real caller -- became
 * real DQL). See {@see YearFunction}'s own docblock for why this needed a
 * custom function at all (no built-in DQL `MONTH()` exists in this
 * Doctrine ORM version).
 *
 * MySQL/MariaDB branch verified against real data via this project's own
 * Integration tests. PostgreSQL/SQLite branches are unverified against a
 * real installation (see this plan's own Context section) -- built from
 * each platform's own documented syntax, not empirically confirmed. Any
 * other platform throws `NotSupported` rather than guessing.
 */
final class MonthFunction extends FunctionNode
{
    private Node $date;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $date = $sqlWalker->walkStringPrimary($this->date);

        if ($platform instanceof AbstractMySQLPlatform) {
            return "MONTH({$date})";
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return "EXTRACT(MONTH FROM {$date})";
        }

        if ($platform instanceof SQLitePlatform) {
            return "CAST(strftime('%m', {$date}) AS INTEGER)";
        }

        throw NotSupported::new(self::class . '::getSql() for ' . $platform::class);
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->date = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
