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
 * Custom DQL function: "DAYOFMONTH" "(" StringPrimary ")" -- day-of-month
 * (1-31), matching MySQL's own `DAYOFMONTH(date)` shape. No native DQL
 * built-in exists for this: Doctrine's own DQL grammar
 * (`Parser::$datetimeFunctions`) only has `CURRENT_DATE`/`CURRENT_TIME`/
 * `CURRENT_TIMESTAMP`/`DATE_ADD`/`DATE_SUB`; `YEAR`/`MONTH`/`DAY` aren't
 * built in either, see {@see YearFunction}/{@see MonthFunction}.
 *
 * The MySQL/MariaDB branch is verified against real data via this
 * project's Integration tests. The PostgreSQL/SQLite branches are built
 * from each platform's documented syntax but are not verified against a
 * real installation. Any other platform throws `NotSupported` rather
 * than guessing.
 */
final class DayOfMonthFunction extends FunctionNode
{
    private Node $date;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $date = $sqlWalker->walkStringPrimary($this->date);

        if ($platform instanceof AbstractMySQLPlatform) {
            return "DAYOFMONTH({$date})";
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return "EXTRACT(DAY FROM {$date})";
        }

        if ($platform instanceof SQLitePlatform) {
            return "CAST(strftime('%d', {$date}) AS INTEGER)";
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
