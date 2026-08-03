<?php

declare(strict_types=1);

use Piwigo\Db\SqlDialect;

/**
 * Piwigo\Db\SqlDialect -- pure SQL-fragment string builders, no connection
 * dependency. getHour()/dateToTs() had zero coverage and booleanToInt()
 * only its bool branch (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1); every
 * other method here is already indirectly exercised (Calendar/C13yInternal
 * tests), but a full, direct pass is cheap and removes any doubt.
 *
 * Phase 4 Item 16: protectColumnName()/concat()/DB_REGEX_OPERATOR removed
 * -- confirmed-duplicate hand-rolling of
 * `Doctrine\DBAL\Platforms\AbstractMySQLPlatform::quoteSingleIdentifier()`/
 * `getConcatExpression()`/`getRegexpExpression()`. protectColumnName()'s
 * only real callers (Db\BatchWriter) now call the real platform method
 * directly; concat()/DB_REGEX_OPERATOR had zero real callers left --
 * CategoryRepository's own DQL conversion (Item 14/15) and
 * Db\DqlFunction\RegexpFunction had already independently arrived at
 * calling the framework's own equivalents.
 */
test('getBoolean treats the string "false" as false, everything else by normal truthiness', function (): void {
    expect(SqlDialect::getBoolean('false'))->toBeFalse();
    expect(SqlDialect::getBoolean('FALSE'))->toBeFalse();
    expect(SqlDialect::getBoolean('true'))->toBeTrue();
    expect(SqlDialect::getBoolean(1))->toBeTrue();
    expect(SqlDialect::getBoolean(0))->toBeFalse();
    expect(SqlDialect::getBoolean(''))->toBeFalse();
});

test('booleanToString converts a real bool to the literal string, and passes everything else through', function (): void {
    expect(SqlDialect::booleanToString(true))->toBe('true');
    expect(SqlDialect::booleanToString(false))->toBe('false');
    expect(SqlDialect::booleanToString('already-a-string'))->toBe('already-a-string');
    expect(SqlDialect::booleanToString(42))->toBe(42);
});

test('booleanToInt converts a real bool to 1/0, and passes everything else through', function (): void {
    expect(SqlDialect::booleanToInt(true))->toBe(1);
    expect(SqlDialect::booleanToInt(false))->toBe(0);
    expect(SqlDialect::booleanToInt('42'))->toBe('42');
    expect(SqlDialect::booleanToInt(null))->toBeNull();
});

test('getHour wraps a date expression in HOUR()', function (): void {
    expect(SqlDialect::getHour('images.date_available'))->toBe('HOUR(images.date_available)');
});

test('dateToTs wraps a date expression in UNIX_TIMESTAMP()', function (): void {
    expect(SqlDialect::dateToTs('d'))->toBe('UNIX_TIMESTAMP(d)');
});

/**
 * Further SQL-modernization audit, Item 11: a non-default $date is now
 * always a bound-parameter placeholder the caller already declared (see
 * getRecentPeriodExpression()'s own docblock) -- spliced in unquoted,
 * unlike the old quote-wrap-any-literal-value defect this replaced.
 */
test('getRecentPeriodExpression builds a SUBDATE(...) fragment for a caller-supplied bound-parameter placeholder', function (): void {
    expect(SqlDialect::getRecentPeriodExpression(7, ':lastDate'))->toBe('SUBDATE(:lastDate,INTERVAL 7 DAY)');
});
