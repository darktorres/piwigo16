<?php

declare(strict_types=1);

namespace Piwigo\Db\DqlFunction;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Literal;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Custom DQL function: "DATE_SUB" "(" ArithmeticPrimary "," ArithmeticPrimary
 * "," StringPrimary ")" -- the exact same grammar as Doctrine ORM's own
 * built-in {@see \Doctrine\ORM\Query\AST\Functions\DateSubFunction} (parse()
 * below is a byte-for-byte copy of it, so every real call site keeps
 * working unchanged), but registered here to override it
 * ({@see \Piwigo\Db\EntityManagerFactory}, custom function lookup runs
 * before the built-in one in Doctrine's own parser) with a genuinely
 * portable getSql().
 *
 * pgsql support pass: real bug found live -- the built-in resolves to
 * `Doctrine\DBAL\Platforms\PostgreSQLPlatform::getDateArithmeticIntervalExpression()`'s
 * `(date_expr - interval_expr)`, with no explicit cast on $date_expr.
 * Against a real column reference that's fine (already typed by the
 * column itself), but against a bare bound parameter -- or any other
 * expression Postgres can't type from context -- the subtraction itself
 * resolves to `interval`, not `timestamp` ("operator does not exist:
 * timestamp without time zone >= interval"), confirmed live via
 * ImageRepository::findIdsAddedSameDayAsLatest()'s own
 * `DATE_SUB(:lastDate, 1, 'day')` failing exactly this way -- and
 * confirmed an explicit ORM-level parameter Type doesn't help either:
 * DBAL's own PgSQL driver Statement::bindValue() only accepts the
 * low-level ParameterType enum, so nothing sends the driver a real
 * timestamp OID over the wire regardless of which ORM Type converts the
 * bound value. An explicit `::timestamp` cast on the date expression --
 * the same fix {@see \Piwigo\Db\SqlDialect::getRecentPeriodExpression()}
 * already established for its own raw-SQL `SUBDATE()` equivalent --
 * resolves it unconditionally, regardless of what kind of expression the
 * date argument is. Postgres's `make_interval()` takes the unit as a
 * named parameter (`days => n`, `secs => n`, ...), not an embedded
 * string, so the unit needs mapping to make_interval()'s own vocabulary.
 */
final class DateSubFunction extends FunctionNode
{
    private const array UNIT_TO_MAKE_INTERVAL_PARAM = [
        'second' => 'secs',
        'minute' => 'mins',
        'hour' => 'hours',
        'day' => 'days',
        'week' => 'weeks',
        'month' => 'months',
        'year' => 'years',
    ];

    public Node $firstDateExpression;

    public Node $intervalExpression;

    private string $unit;

    #[\Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $unit = $this->unit;
        $dateSql = $this->firstDateExpression->dispatch($sqlWalker);
        $intervalSql = $this->intervalExpression->dispatch($sqlWalker);
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $makeIntervalParam = self::UNIT_TO_MAKE_INTERVAL_PARAM[$unit]
                ?? throw QueryException::semanticalError(
                    'DATE_SUB() only supports units of type second, minute, hour, day, week, month and year.'
                );

            return "(({$dateSql})::timestamp - make_interval({$makeIntervalParam} => {$intervalSql}))";
        }

        return match ($unit) {
            'second' => $platform->getDateSubSecondsExpression($dateSql, $intervalSql),
            'minute' => $platform->getDateSubMinutesExpression($dateSql, $intervalSql),
            'hour' => $platform->getDateSubHourExpression($dateSql, $intervalSql),
            'day' => $platform->getDateSubDaysExpression($dateSql, $intervalSql),
            'week' => $platform->getDateSubWeeksExpression($dateSql, $intervalSql),
            'month' => $platform->getDateSubMonthExpression($dateSql, $intervalSql),
            'year' => $platform->getDateSubYearsExpression($dateSql, $intervalSql),
            default => throw QueryException::semanticalError(
                'DATE_SUB() only supports units of type second, minute, hour, day, week, month and year.'
            ),
        };
    }

    #[\Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->firstDateExpression = $this->requireNode($parser->ArithmeticPrimary());
        $parser->match(TokenType::T_COMMA);
        $this->intervalExpression = $this->requireNode($parser->ArithmeticPrimary());
        $parser->match(TokenType::T_COMMA);

        $unit = $parser->StringPrimary();
        if (! $unit instanceof Literal || ! is_string($unit->value)) {
            throw QueryException::semanticalError(
                "DATE_SUB()'s unit argument must be a literal string."
            );
        }

        $this->unit = strtolower($unit->value);

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    /**
     * `Parser::ArithmeticPrimary()` is declared `Node|string` -- the
     * `string` case is a DQL "result variable" reference (an alias from a
     * `WITH`/subselect scope), which Doctrine's own built-in
     * {@see \Doctrine\ORM\Query\AST\Functions\DateAddFunction}/
     * {@see \Doctrine\ORM\Query\AST\Functions\DateSubFunction} don't
     * actually support either -- their own `getSql()` unconditionally
     * calls `->dispatch()` on this same property, which would fatal-error
     * with "Call to a member function dispatch() on string" if a
     * resultVariable ever reached it. Converts that same real (if
     * upstream-uncaught) misuse into a clean semantic error instead of a
     * crash, without changing behavior for any real call site -- none of
     * this codebase's DATE_SUB() usages pass a resultVariable.
     */
    private function requireNode(Node|string $expr): Node
    {
        if (is_string($expr)) {
            throw QueryException::semanticalError(
                "DATE_SUB() doesn't support a result-variable reference as a date/interval argument."
            );
        }

        return $expr;
    }
}
