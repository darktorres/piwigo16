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
 * shape. The optional 2nd `mode` argument follows the same optional-argument
 * idiom as `Doctrine\ORM\Query\AST\Functions\LocateFunction`'s own optional
 * 3rd argument (`$parser->getLexer()->isNextToken(TokenType::T_COMMA)`).
 *
 * MySQL's `mode` argument (0-7, controlling first-day-of-week/range
 * convention) is genuinely MySQL-specific -- {@see \Piwigo\Calendar\CalendarWeekly}'s
 * own comment on its one real `mode: 5` call site ("Week 1=the first week
 * with a Monday in this year") documents this as MySQL-version-specific
 * behavior.
 *
 * Only mode 5 is implemented (the only mode any real call site in this
 * codebase uses); any other mode value throws `NotSupported` rather than
 * guessing at unverified semantics. Mode 5 ("first day of week: Monday,
 * range: 0-53, week 1 = the first week containing a Monday in this year")
 * is computed as `date - firstMondayOfYear`, floor-divided by 7 (a negative
 * numerator for the handful of pre-first-Monday January days floor-divides
 * below zero, so +1 corrects it back to 0), where firstMondayOfYear is Jan
 * 1 advanced to the next Monday via its ISO day-of-week. Verified against
 * real MySQL 9.7 for every possible Jan-1 weekday and across a leap year,
 * comparing `WEEK(date, 5)` day-by-day for a whole year against this exact
 * expression, with zero mismatches. The no-mode call shape's translation
 * is built from documented syntax, not empirically confirmed.
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
