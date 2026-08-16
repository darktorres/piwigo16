<?php

declare(strict_types=1);

use Piwigo\Db\DbCredentials;
use Piwigo\Db\SqlDialect;
use Piwigo\Sort\OrderBy;

/**
 * Piwigo\Sort\OrderBy -- the structured photo sort order behind
 * CurrentConfig's own order_by/order_by_inside_category pair.
 *
 * The platform-dependent spellings (`RAND()` vs `random()`, backticked vs
 * double-quoted `rank`) are asserted against the driver this suite actually
 * runs on rather than hardcoded, so the file is meaningful under either.
 */
test('none() and an all-whitespace fragment render as no ordering at all', function (): void {
    expect(OrderBy::none()->isEmpty())
        ->toBeTrue()
        ->and(OrderBy::none()->toSql())
        ->toBe('')
        ->and(OrderBy::fromConfigFragment('   ')->isEmpty())
        ->toBeTrue();
});

test('default() is the shipped order_by seed', function (): void {
    expect(OrderBy::default()->toSql())
        ->toBe('ORDER BY date_available DESC, file ASC, id ASC');
});

test('fromConfigFragment() round-trips a known fragment through the structured path', function (): void {
    $order = OrderBy::fromConfigFragment('ORDER BY name ASC, hit DESC');

    expect($order->isRaw())
        ->toBeFalse()
        ->and($order->toSql())
        ->toBe('ORDER BY name ASC, hit DESC')
        ->and($order->toSqlBody())
        ->toBe('name ASC, hit DESC')
        ->and($order->toSortFieldTokens())
        ->toBe(['name ASC', 'hit DESC']);
});

test('toSql() prefixes real columns with a table alias when one is given', function (): void {
    expect(OrderBy::fromConfigFragment('ORDER BY name ASC, id DESC')->toSql('i'))
        ->toBe('ORDER BY i.name ASC, i.id DESC');
});

test('RAND() stays structured and renders per platform, prefix-free', function (): void {
    // The regex behind parseOrderByFragment() only matches "<field> ASC|DESC",
    // so RAND() needs its own branch -- without it a random order would fall
    // through to raw() and skip the portability rewrite below.
    $order = OrderBy::fromConfigFragment('ORDER BY RAND()');

    expect($order->isRaw())
        ->toBeFalse()
        ->and($order->toSql())
        ->toBe('ORDER BY ' . SqlDialect::randomFunction())
        // A function call takes no table prefix and no direction.
        ->and($order->toSql('i'))
        ->toBe('ORDER BY ' . SqlDialect::randomFunction());
});

test('a raw override still gets the RAND() portability rewrite', function (): void {
    // This is the one place that rewrite lives now; a raw fragment is the
    // sysadmin's own text, so it can name RAND() even though nothing parsed it.
    $order = OrderBy::raw('ORDER BY RAND()');

    expect($order->isRaw())
        ->toBeTrue()
        ->and($order->toSql())
        ->toBe('ORDER BY ' . SqlDialect::randomFunction())
        ->and($order->toSqlBody())
        ->toBe(SqlDialect::randomFunction());
});

test('a raw override missing its own ORDER BY keyword gets one prepended', function (): void {
    // categories.image_order stores a bare field list, unlike
    // CurrentConfig::$orderByCustom's own always-prefixed convention --
    // raw() must not silently drop the keyword for callers that don't
    // already include it.
    $order = OrderBy::raw('file DESC');

    expect($order->toSql())
        ->toBe('ORDER BY file DESC');
});

test('a raw override that already has the ORDER BY keyword is not double-prefixed', function (): void {
    $order = OrderBy::raw('ORDER BY file DESC');

    expect($order->toSql())
        ->toBe('ORDER BY file DESC');
});

test('an empty raw override renders as no ordering, not a bare ORDER BY keyword', function (): void {
    $order = OrderBy::raw('');

    expect($order->toSql())
        ->toBe('');
});

test('unparseable config text is kept verbatim as a raw override', function (): void {
    $order = OrderBy::fromConfigFragment('ORDER BY some_plugin_column ASC NULLS LAST');

    expect($order->isRaw())
        ->toBeTrue()
        ->and($order->toSql())
        ->toBe('ORDER BY some_plugin_column ASC NULLS LAST')
        // The admin form can't represent a raw override either.
        ->and($order->toSortFieldTokens())
        ->toBe([]);
});

test('rank is quoted for the driver this suite runs on', function (): void {
    $quoted = DbCredentials::fromEnv()->driver === 'pgsql' ? '"rank"' : '`rank`';

    expect(OrderBy::fromConfigFragment('ORDER BY `rank` ASC')->toSql())
        ->toBe('ORDER BY ' . $quoted . ' ASC');
});

test('fromWsOrderParam() accepts the WS vocabulary, including its aliases', function (): void {
    // Distinct from the config vocabulary: `rand` and the legacy
    // date_created/date_posted names are only valid here.
    expect(OrderBy::fromWsOrderParam('date_created desc, file')->toSql())
        ->toBe('ORDER BY date_creation DESC, file ASC')
        ->and(OrderBy::fromWsOrderParam('rand')->toSql())
        ->toBe('ORDER BY ' . SqlDialect::randomFunction())
        ->and(OrderBy::fromWsOrderParam('')->isEmpty())
        ->toBeTrue();
});

test('fromWsOrderParam() drops tokens outside the allow-list', function (): void {
    expect(OrderBy::fromWsOrderParam('not_a_column ASC')->isEmpty())
        ->toBeTrue();
});

test('toDql() maps every entry to a property path', function (): void {
    $clauses = OrderBy::fromConfigFragment('ORDER BY name ASC, hit DESC')->toDql('i');

    expect($clauses)
        ->toHaveCount(2);
    assert($clauses !== null);
    expect($clauses[0]->property)
        ->toBe('i.name')
        ->and($clauses[0]->dir)
        ->toBe('ASC')
        ->and($clauses[1]->property)
        ->toBe('i.hit');
});

test('toDql() returns null when the order cannot be expressed as DQL', function (): void {
    // null means "fall back to raw SQL", never "no order". Three ways to get
    // there: a raw override, a function call with no property path, and rank
    // in a query with no image_category alias to hang it on.
    expect(OrderBy::raw('ORDER BY whatever')->toDql('i'))
        ->toBeNull()
        ->and(OrderBy::fromConfigFragment('ORDER BY RAND()')->toDql('i'))
        ->toBeNull()
        ->and(OrderBy::fromConfigFragment('ORDER BY `rank` ASC')->toDql('i'))
        ->toBeNull();

    // ...and rank *is* expressible once that alias exists.
    $withAlias = OrderBy::fromConfigFragment('ORDER BY `rank` ASC')->toDql('i', 'ic');
    expect($withAlias)
        ->toHaveCount(1);
    assert($withAlias !== null);
    expect($withAlias[0]->property)
        ->toBe('ic.rank');
});

test('toDql() returns null for an empty order rather than an empty clause list', function (): void {
    expect(OrderBy::none()->toDql('i'))
        ->toBeNull();
});
