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
 * Custom DQL function: "DATE_FORMAT_MONTH_DAY" "(" StringPrimary ")" -- a
 * 4-digit `MMDD` grouping key, matching MySQL's own
 * `DATE_FORMAT(date, '%m%d')` shape. Calendar is its only real caller,
 * for its own month+day-within-year navigation grouping key.
 *
 * A dedicated single-purpose function rather than a generic
 * pattern-string function, because a portable-pattern-string
 * `DATE_FORMAT(date, pattern)` isn't safe across platforms (see
 * {@see DateFormatYearMonthFunction}'s own docblock). The MySQL/MariaDB
 * branch is verified against real data via this project's own
 * Integration tests. The PostgreSQL/SQLite branches are unverified
 * against a real installation -- built from each platform's own
 * documented syntax, not empirically confirmed. Any other platform
 * throws `NotSupported` rather than guessing.
 */
final class DateFormatMonthDayFunction extends FunctionNode
{
    private Node $date;

    #[Override]
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

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->date = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
