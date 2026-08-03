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

/**
 * Custom DQL function: "DATE_FORMAT_MONTH_DAY" "(" StringPrimary ")" --
 * a 4-digit `MMDD` grouping key, matching MySQL's own
 * `DATE_FORMAT(date, '%m%d')` shape (formerly {@see \Piwigo\Db\SqlDialect}'s
 * `getDateMMDD()`, removed once Calendar -- its only real caller --
 * became real DQL).
 *
 * Further SQL-modernization audit, Item 15G: Calendar's own
 * month+day-within-year navigation grouping key -- same dedicated-
 * single-purpose-function reasoning as {@see DateFormatYearMonthFunction}'s
 * own docblock (a portable-pattern-string `DATE_FORMAT(date, pattern)`
 * isn't safe across platforms). MySQL/MariaDB branch verified against
 * real data via this project's own Integration tests. PostgreSQL/SQLite
 * branches are unverified against a real installation (see this plan's
 * own Context section) -- built from each platform's own documented
 * syntax, not empirically confirmed. Any other platform throws
 * `NotSupported` rather than guessing.
 */
final class DateFormatMonthDayFunction extends FunctionNode
{
    private Node $date;

    #[\Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $date = $sqlWalker->walkStringPrimary($this->date);

        if ($platform instanceof AbstractMySQLPlatform) {
            return "DATE_FORMAT({$date}, '%m%d')";
        }

        if ($platform instanceof SQLitePlatform) {
            return "strftime('%m%d', {$date})";
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return "TO_CHAR({$date}, 'MMDD')";
        }

        throw NotSupported::new(self::class . '::getSql() for ' . $platform::class);
    }

    #[\Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->date = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
