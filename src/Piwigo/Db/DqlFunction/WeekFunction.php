<?php

declare(strict_types=1);

namespace Piwigo\Db\DqlFunction;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Literal;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * Custom DQL function: "WEEK" "(" StringPrimary ["," SimpleArithmeticExpression]
 * ")" -- week-of-year, matching MySQL's own `WEEK(date)`/`WEEK(date, mode)`
 * shape (formerly {@see \Piwigo\Db\SqlDialect}'s `getWeek()`, removed once
 * Calendar -- its only real caller -- became real DQL). The optional 2nd
 * `mode` argument is a real, precedented Doctrine idiom -- mirrors
 * `Doctrine\ORM\Query\AST\Functions\LocateFunction`'s own optional 3rd
 * argument (`$parser->getLexer()->isNextToken(TokenType::T_COMMA)`); no
 * other function in this codebase needed one before this.
 *
 * Further SQL-modernization audit, Item 15G: MySQL's `mode` argument
 * (0-7, controlling first-day-of-week/range convention) is genuinely
 * MySQL-specific -- {@see \Piwigo\Calendar\CalendarWeekly}'s own comment
 * on its one real `mode: 5` call site ("Week 1=the first week with a
 * Monday in this year") already documents this as MySQL-version-specific
 * behavior. This item's own original note punted on porting the mode-arg
 * shape at all ("this project has no way to verify without a real
 * installation of either") -- no longer true once a real pgsql support
 * pass gave this project a real, live PostgreSQL instance to verify
 * against.
 *
 * pgsql support pass: mode 5 ("first day of week: Monday, range: 0-53,
 * week 1 = the first week containing a Monday in this year") implemented
 * for real -- `date - firstMondayOfYear`, floor-divided by 7 (naturally
 * yields 0 for the handful of pre-first-Monday January days, since a
 * negative numerator floor-divides below zero, +1 corrects it back to
 * 0), where firstMondayOfYear is Jan 1 advanced to the next Monday via
 * its ISO day-of-week. Empirically verified against real MySQL 9.7 --
 * not just derived -- for every possible Jan-1 weekday (all 7 cases) and
 * across a leap year, comparing WEEK(date, 5) day-by-day for a whole
 * year against this exact expression: zero mismatches. Only mode 5 is
 * implemented (the only mode any real call site in this codebase ever
 * uses); any other mode value still throws `NotSupported` rather than
 * guessing at unverified semantics, same as before. The no-mode call
 * shape's existing best-effort (documented-unverified) translation is
 * unchanged.
 */
final class WeekFunction extends FunctionNode
{
    private Node $date;

    private Node|string|null $mode = null;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $date = $sqlWalker->walkStringPrimary($this->date);

        if ($platform instanceof AbstractMySQLPlatform) {
            if ($this->mode !== null) {
                return "WEEK({$date}, " . $sqlWalker->walkSimpleArithmeticExpression($this->mode) . ')';
            }

            return "WEEK({$date})";
        }

        if ($platform instanceof PostgreSQLPlatform && $this->mode instanceof Literal && is_numeric($this->mode->value) && (int) $this->mode->value === 5) {
            return "(FLOOR((({$date})::date - (date_trunc('year', ({$date})::date)::date + ((8 - EXTRACT(ISODOW FROM date_trunc('year', ({$date})::date))::int) % 7))) / 7.0)::int + 1)";
        }

        if ($this->mode !== null) {
            throw NotSupported::new(self::class . '::getSql() with a mode argument for ' . $platform::class);
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return "EXTRACT(WEEK FROM {$date})";
        }

        if ($platform instanceof SQLitePlatform) {
            return "strftime('%W', {$date})";
        }

        throw NotSupported::new(self::class . '::getSql() for ' . $platform::class);
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->date = $parser->StringPrimary();

        $lexer = $parser->getLexer();
        if ($lexer->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);

            $this->mode = $parser->SimpleArithmeticExpression();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
