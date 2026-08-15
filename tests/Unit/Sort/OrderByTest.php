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

    expect($order->toSql())
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
    // so RAND() needs its own branch in the parser to stay structured.
    $order = OrderBy::fromConfigFragment('ORDER BY RAND()');

    expect($order->toSql())
        ->toBe('ORDER BY ' . SqlDialect::randomFunction())
        // A function call takes no table prefix and no direction.
        ->and($order->toSql('i'))
        ->toBe('ORDER BY ' . SqlDialect::randomFunction())
        ->and($order->toSqlBody())
        ->toBe(SqlDialect::randomFunction());
});

test('a raw override still gets the RAND() portability rewrite', function (): void {
    // This is the one place that rewrite lives now; a raw fragment is the
    // caller's own text, so it can name RAND() even though nothing parsed it.
    $order = OrderBy::raw('ORDER BY RAND()');

    expect($order->isRaw())
        ->toBeTrue()
        ->and($order->toSql())
        ->toBe('ORDER BY ' . SqlDialect::randomFunction())
        ->and($order->toSqlBody())
        ->toBe(SqlDialect::randomFunction());
});

test('a raw override missing its own ORDER BY keyword gets one prepended', function (): void {
    // categories.image_order stores a bare field list, unlike a
    // pre-`ORDER BY`-prefixed raw() caller's own convention -- raw() must
    // not silently drop the keyword for callers that don't already
    // include it.
    $order = OrderBy::raw('file DESC');

    expect($order->isRaw())
        ->toBeTrue()
        ->and($order->toSql())
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

test('text outside the vocabulary falls back to the default order', function (): void {
    // fromConfigFragment() has no raw escape hatch of its own: the admin
    // form is the only writer of order_by/order_by_inside_category and it
    // validates against $sort_fields, so unparseable text there is a
    // corrupt config row, and substituting the default is what an absent
    // row does. raw() itself (tested above) is unrelated -- that's a
    // caller-supplied override (categories.image_order), never something
    // fromConfigFragment() produces on its own.
    $order = OrderBy::fromConfigFragment('ORDER BY some_plugin_column ASC NULLS LAST');

    expect($order->isRaw())
        ->toBeFalse()
        ->and($order->toSql())
        ->toBe(OrderBy::default()->toSql())
        // ...and it stays representable in the admin form, unlike the raw
        // override this replaced, which rendered as an empty selection.
        ->and($order->toSortFieldTokens())
        ->toBe(['date_available DESC', 'file ASC', 'id ASC']);
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

test('RAND() is expressible as DQL via the registered custom function', function (): void {
    // Doctrine's grammar accepts a FunctionDeclaration as an ORDER BY item,
    // and RAND is registered in EntityManagerFactory -- so a random order no
    // longer forces its caller onto raw DBAL.
    $clauses = OrderBy::fromConfigFragment('ORDER BY RAND()')->toDql('i');

    expect($clauses)
        ->toHaveCount(1);
    assert($clauses !== null);
    expect($clauses[0]->property)
        ->toBe('RAND()');
});

test('toDql() returns null only for rank with no image_category alias', function (): void {
    // null means "fall back to raw SQL", never "no order". This is now the
    // one remaining way to get there.
    expect(OrderBy::fromConfigFragment('ORDER BY `rank` ASC')->toDql('i'))
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
