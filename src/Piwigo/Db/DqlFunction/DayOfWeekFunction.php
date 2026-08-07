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
 * Custom DQL function: "DAYOFWEEK" "(" StringPrimary ")" -- day-of-week,
 * 1=Sunday..7=Saturday, matching MySQL's own `DAYOFWEEK(date)` shape.
 * MySQL's native convention is 1-indexed, Sunday-first.
 *
 * PostgreSQL's `EXTRACT(DOW FROM date)` and SQLite's `strftime('%w',
 * date)` are both natively 0-indexed, Sunday-first (0=Sunday..6=Saturday)
 * -- both branches below add 1 to match MySQL's convention, since every
 * real caller ({@see \Piwigo\Calendar\CalendarWeekly}) depends on the
 * exact 1=Sunday..7=Saturday numbering.
 *
 * The MySQL/MariaDB branch is verified against real data via this
 * project's Integration tests. The PostgreSQL/SQLite branches are built
 * from each platform's documented syntax but are not verified against a
 * real installation. Any other platform throws `NotSupported` rather
 * than guessing.
 */
final class DayOfWeekFunction extends FunctionNode
{
    private Node $date;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $date = $sqlWalker->walkStringPrimary($this->date);

        if ($platform instanceof AbstractMySQLPlatform) {
            return "DAYOFWEEK({$date})";
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return "(EXTRACT(DOW FROM {$date}) + 1)";
        }

        if ($platform instanceof SQLitePlatform) {
            return "(CAST(strftime('%w', {$date}) AS INTEGER) + 1)";
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
