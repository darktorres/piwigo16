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
 * Custom DQL function: "WEEKDAY" "(" StringPrimary ")" -- day-of-week,
 * 0=Monday..6=Sunday, matching MySQL's own `WEEKDAY(date)` shape. MySQL's
 * native 0-indexed, Monday-first convention is the complement to
 * {@see DayOfWeekFunction}'s Sunday-first one -- both real MySQL functions
 * with genuinely different conventions.
 *
 * PostgreSQL's `EXTRACT(ISODOW FROM date)` is 1-indexed, Monday-first
 * (1=Monday..7=Sunday) -- subtracting 1 matches MySQL's 0-indexed
 * convention. SQLite's `strftime('%w', date)` is 0-indexed, Sunday-first
 * (0=Sunday..6=Saturday) -- `(%w + 6) % 7` remaps Sunday(0)->6,
 * Monday(1)->0, ..., Saturday(6)->5, matching MySQL's convention.
 * MySQL/MariaDB is verified against real data via this project's own
 * Integration tests; PostgreSQL/SQLite are built from each platform's
 * documented syntax, not empirically confirmed. Any other platform throws
 * `NotSupported` rather than guessing.
 */
final class WeekdayFunction extends FunctionNode
{
    private Node $date;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $date = $sqlWalker->walkStringPrimary($this->date);

        if ($platform instanceof AbstractMySQLPlatform) {
            return "WEEKDAY({$date})";
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return "(EXTRACT(ISODOW FROM {$date}) - 1)";
        }

        if ($platform instanceof SQLitePlatform) {
            return "((CAST(strftime('%w', {$date}) AS INTEGER) + 6) % 7)";
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
