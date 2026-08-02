<?php

declare(strict_types=1);

use Piwigo\Db\SqlDialect;

/**
 * Piwigo\Db\SqlDialect -- pure SQL-fragment string builders, no connection
 * dependency. concat()/getHour()/getFloodPeriodExpression()/dateToTs() had
 * zero coverage and booleanToInt() only its bool branch (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1); every
 * other method here is already indirectly exercised (Calendar/C13yInternal
 * tests), but a full, direct pass is cheap and removes any doubt.
 */
test('protectColumnName backtick-quotes a bare column name, and leaves an already-quoted one alone', function (): void {
    expect(SqlDialect::protectColumnName('username'))->toBe('`username`');
    expect(SqlDialect::protectColumnName('`already_quoted`'))->toBe('`already_quoted`');
});

test('protectColumnName checks the FIRST character, not the last', function (): void {
    // Kills line 41's DecrementInteger ($column_name[-1] instead of
    // [0]). The existing test above always uses inputs where the first
    // and last characters agree (both backtick, or both a regular
    // letter), which can't distinguish which index the real check uses.
    // A string that starts with a backtick but doesn't end with one (or
    // vice versa) can: confirmed live that the two indices produce
    // opposite wrap/no-wrap decisions for these inputs.
    expect(SqlDialect::protectColumnName('`x'))->toBe('`x');
    expect(SqlDialect::protectColumnName('x`'))->toBe('`x``');
});

test('concat wraps a comma-joined column list in CONCAT()', function (): void {
    expect(SqlDialect::concat(['a', 'b', 'c']))->toBe('CONCAT(a,b,c)');
});

test('concatWs wraps a comma-joined column list and separator in CONCAT_WS()', function (): void {
    expect(SqlDialect::concatWs(['a', 'b'], '-'))->toBe("CONCAT_WS('-',a,b)");
});

test('castToText is an identity passthrough', function (): void {
    expect(SqlDialect::castToText('anything'))->toBe('anything');
});

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

test('getFloodPeriodExpression builds a SUBDATE(NOW(), ...) fragment', function (): void {
    expect(SqlDialect::getFloodPeriodExpression(300))->toBe('SUBDATE(NOW(), INTERVAL 300 SECOND)');
});

test('getHour wraps a date expression in HOUR()', function (): void {
    expect(SqlDialect::getHour('images.date_available'))->toBe('HOUR(images.date_available)');
});

test('getDateYYYYMM/getDateMMDD wrap a date expression in DATE_FORMAT()', function (): void {
    expect(SqlDialect::getDateYYYYMM('d'))->toBe("DATE_FORMAT(d, '%Y%m')");
    expect(SqlDialect::getDateMMDD('d'))->toBe("DATE_FORMAT(d, '%m%d')");
});

test('getYear/getMonth/getDayOfMonth/getDayOfWeek/getWeekday wrap a date expression in their SQL function name', function (): void {
    expect(SqlDialect::getYear('d'))->toBe('YEAR(d)');
    expect(SqlDialect::getMonth('d'))->toBe('MONTH(d)');
    expect(SqlDialect::getDayOfMonth('d'))->toBe('DAYOFMONTH(d)');
    expect(SqlDialect::getDayOfWeek('d'))->toBe('DAYOFWEEK(d)');
    expect(SqlDialect::getWeekday('d'))->toBe('WEEKDAY(d)');
});

test('getWeek omits the mode argument when null/falsy, includes it when given', function (): void {
    expect(SqlDialect::getWeek('d'))->toBe('WEEK(d)');
    expect(SqlDialect::getWeek('d', null))->toBe('WEEK(d)');
    expect(SqlDialect::getWeek('d', 0))->toBe('WEEK(d)');
    expect(SqlDialect::getWeek('d', 3))->toBe('WEEK(d, 3)');
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

/**
 * Confirmed-equivalent: line 176's RemoveBooleanCast (dropping `(bool)`
 * around `$mode` in getWeek()'s `if`). An `if` condition already coerces
 * a nullable int to bool on its own -- 0/null are falsy, any other int
 * is truthy either way, so the explicit cast changes nothing about
 * which branch runs. Confirmed live: the full suite in this file passes
 * identically with the cast removed.
 */
