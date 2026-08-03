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
 * behavior, matching {@see \Piwigo\Db\SqlDialect}'s own class docblock
 * ("MySQL-specific today... no install/schema/pgsql.sql exists... a real
 * multi-dialect split is out of scope, left as a follow-up"). Reproducing
 * MySQL's exact per-mode semantics on PostgreSQL/SQLite would need real
 * per-mode logic this project has no way to verify without a real
 * installation of either -- so the mode-arg call shape throws
 * `NotSupported` on non-MySQL platforms rather than guessing at
 * unverified semantics; only the no-mode call shape gets a best-effort
 * (documented-unverified, same as every other function in this
 * directory's own non-MySQL branches) translation.
 */
final class WeekFunction extends FunctionNode
{
    private Node $date;

    private Node|string|null $mode = null;

    #[\Override]
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

    #[\Override]
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
