<?php

declare(strict_types=1);

use Piwigo\Search\QDateRangeScope;
use Piwigo\Search\QExpression;
use Piwigo\Search\QMultiToken;
use Piwigo\Search\QNumericRangeScope;
use Piwigo\Search\QSearchScope;
use Piwigo\Search\QSingleToken;

/** QExpression::$stokens/$tokens (and QMultiToken::$tokens) are untyped
 * arrays of QMultiToken|QSingleToken -- every test below knows from the
 * input string which concrete shape it produced, so these narrow that
 * knowledge into a real runtime check instead of an unchecked property
 * access on the union. */
function qexprSingle(mixed $token): QSingleToken
{
    if (! $token instanceof QSingleToken) {
        throw new RuntimeException('Expected a QSingleToken, got ' . get_debug_type($token));
    }

    return $token;
}

function qexprMulti(mixed $token): QMultiToken
{
    if (! $token instanceof QMultiToken) {
        throw new RuntimeException('Expected a QMultiToken, got ' . get_debug_type($token));
    }

    return $token;
}

function qexprScope(?QSearchScope $scope): QSearchScope
{
    if ($scope === null) {
        throw new RuntimeException('Expected a non-null QSearchScope');
    }

    return $scope;
}

/**
 * QSingleToken::$scope_data is a discriminated union depending on which
 * concrete scope subclass parsed it -- these narrow to the specific shape
 * each test already knows it produced.
 *
 * @return array{range: array{0: int|float|string, 1: int|float|string}, strict: array{0: int, 1: int}}
 */
function qexprRangeData(QSingleToken $token): array
{
    $data = $token->scope_data;
    if (! is_array($data) || ! array_key_exists('range', $data)) {
        throw new RuntimeException('Expected a numeric-range scope_data shape, got ' . var_export($data, true));
    }

    return $data;
}

/** @return array{0: string, 1: string} */
function qexprDateData(QSingleToken $token): array
{
    $data = $token->scope_data;
    if (! is_array($data) || ! array_key_exists(0, $data)) {
        throw new RuntimeException('Expected a date-range scope_data shape, got ' . var_export($data, true));
    }

    return $data;
}

/**
 * Piwigo\Search\QExpression/QMultiToken/QSingleToken/QSearchScope/
 * QNumericRangeScope/QDateRangeScope -- the quick-search query-string
 * parser/tokenizer (AND/OR/NOT, quoted phrases, wildcards, `scope:value`
 * prefixes, parenthesized sub-expressions, numeric/date range scopes). Had
 * zero dedicated coverage (see /home/torres/.claude/plans/piped-enchanting-
 * spark.md, Wave 1) despite being pure, side-effect-free, deterministic
 * logic with no DB/network/filesystem dependency -- the real scope set
 * mirrors SearchService::getValidatedSearchArray()'s own construction.
 *
 * @return QSearchScope[]
 */
function qexprScopes(): array
{
    return [
        new QSearchScope('tag', ['tags']),
        new QSearchScope('author', [], true),
        new QNumericRangeScope('width', []),
        new QNumericRangeScope('ratio', [], false, 0.001),
        new QNumericRangeScope('filesize', []),
        new QNumericRangeScope('score', ['rating'], true),
        new QDateRangeScope('created', ['date-created', 'datecreated'], true),
    ];
}

test('tokenizes 2 plain words as implicit AND', function (): void {
    $expr = new QExpression('hello world', qexprScopes());

    expect($expr->stokens)->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('hello');
    expect($expr->stokens[1]->term)->toBe('world');
    expect($expr->stoken_modifiers[0] & QSingleToken::QST_OR)->toBe(0);
    expect($expr->stoken_modifiers[1] & QSingleToken::QST_OR)->toBe(0);
});

test('the OR keyword sets QST_OR on the following token', function (): void {
    $expr = new QExpression('hello OR world', qexprScopes());

    expect($expr->stokens)->toHaveCount(2);
    expect($expr->stoken_modifiers[1] & QSingleToken::QST_OR)->toBe(QSingleToken::QST_OR);
});

test('a leading hyphen sets QST_NOT, the AND keyword is dropped entirely', function (): void {
    $expr = new QExpression('-hello AND world', qexprScopes());

    expect($expr->stokens)->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('hello');
    expect($expr->stoken_modifiers[0] & QSingleToken::QST_NOT)->toBe(QSingleToken::QST_NOT);
    expect($expr->stokens[1]->term)->toBe('world');
});

test('a quoted phrase becomes a single token with QST_QUOTED', function (): void {
    $expr = new QExpression('"hello world"', qexprScopes());

    expect($expr->stokens)->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('hello world');
    expect($expr->stokens[0]->modifier & QSingleToken::QST_QUOTED)->toBe(QSingleToken::QST_QUOTED);
});

test('a trailing wildcard sets QST_WILDCARD_END and strips the star', function (): void {
    $expr = new QExpression('hel*', qexprScopes());

    expect($expr->stokens)->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('hel');
    expect($expr->stokens[0]->modifier & QSingleToken::QST_WILDCARD_END)->toBe(QSingleToken::QST_WILDCARD_END);
});

test('a leading wildcard sets QST_WILDCARD_BEGIN', function (): void {
    $expr = new QExpression('*llo', qexprScopes());

    expect($expr->stokens)->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('llo');
    expect($expr->stokens[0]->modifier & QSingleToken::QST_WILDCARD_BEGIN)->toBe(QSingleToken::QST_WILDCARD_BEGIN);
});

test('a scope prefix resolves the token\'s scope by id', function (): void {
    $expr = new QExpression('tag:sunset', qexprScopes());

    expect($expr->stokens)->toHaveCount(1);
    $token = $expr->stokens[0];
    expect($token->term)->toBe('sunset');
    expect(qexprScope($token->scope)->id)->toBe('tag');
});

test('a scope alias resolves to the same scope as its canonical id', function (): void {
    $expr = new QExpression('tags:sunset', qexprScopes());

    expect(qexprScope($expr->stokens[0]->scope)->id)->toBe('tag');
});

test('an unrecognized scope name is not consumed as a prefix -- the colon acts as a plain break, like whitespace', function (): void {
    $expr = new QExpression('notascope:value', qexprScopes());

    expect($expr->stokens)->toHaveCount(2);
    $first = $expr->stokens[0];
    $second = $expr->stokens[1];
    expect($first->term)->toBe('notascope');
    expect($first->scope)->toBeNull();
    expect($second->term)->toBe('value');
    expect($second->scope)->toBeNull();
});

test('a nullable scope with an empty value still parses (author is nullable)', function (): void {
    $expr = new QExpression('author:', qexprScopes());

    expect($expr->stokens)->toHaveCount(1);
    $token = $expr->stokens[0];
    expect($token->term)->toBe('');
    expect(qexprScope($token->scope)->id)->toBe('author');
});

test('parenthesized groups become a nested sub-expression, not flattened', function (): void {
    $expr = new QExpression('(hello OR world) foo', qexprScopes());

    expect($expr->tokens)->toHaveCount(2);
    expect($expr->tokens[0]->is_single)->toBeFalse();
    expect(qexprMulti($expr->tokens[0])->tokens)->toHaveCount(2);
    expect($expr->tokens[1]->is_single)->toBeTrue();
    expect(qexprSingle($expr->tokens[1])->term)->toBe('foo');
});

test('__toString round-trips a simple OR/NOT expression', function (): void {
    $expr = new QExpression('hello OR -world', qexprScopes());

    expect((string) $expr)->toBe('hello OR NOT world');
});

test('__toString wraps a sub-expression in parentheses', function (): void {
    $expr = new QExpression('(hello world)', qexprScopes());

    expect((string) $expr)->toBe('(hello world)');
});

test('QNumericRangeScope parses an explicit min..max range', function (): void {
    $expr = new QExpression('filesize:100..500', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'])->toBe([100.0, 500.0]);
    // get_sql() appends a trailing space per clause before implode(' AND
    // ', ...) adds its own -- the double space between clauses is the
    // real, exact output, not a typo.
    expect(qexprScope($token->scope)->get_sql('images.filesize', $token))
        ->toBe('(images.filesize >=100  AND images.filesize <=500 )');
});

test('QNumericRangeScope applies a strict lower bound for a > prefix', function (): void {
    $expr = new QExpression('width:>800', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'][0])->toBe(800.0);
    expect(qexprRangeData($token)['strict'][0])->toBe(1);
    expect(qexprScope($token->scope)->get_sql('images.width', $token))
        ->toBe('(images.width >800 )');
});

test('QNumericRangeScope expands a k/m suffix and applies epsilon for a bare value', function (): void {
    $expr = new QExpression('ratio:1.5', qexprScopes());
    $token = $expr->stokens[0];

    // bare value (not an explicit range) -> [term, term], epsilon (0.001)
    // subtracted from the lower bound and added to the upper bound.
    expect(qexprRangeData($token)['range'][0])->toBe(1.499);
    expect(qexprRangeData($token)['range'][1])->toBeGreaterThan(1.500);
});

test('QNumericRangeScope resolves a k-suffixed value to thousands', function (): void {
    $expr = new QExpression('filesize:6k', qexprScopes());
    $token = $expr->stokens[0];

    // upper bound of a bare (non-explicit-range) value rounds up to the
    // next whole thousand minus 1 (see the class's own "6k -> 6999" note).
    expect(qexprRangeData($token)['range'][1])->toBe(6999.0);
});

test('QDateRangeScope parses a year-only value to a full-year range with time-of-day bounds', function (): void {
    $expr = new QExpression('created:2024', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprDateData($token)[0])->toBe('2024-1-1');
    expect(qexprDateData($token)[1])->toBe('2024-12-31 23:59:59');
    expect(qexprScope($token->scope)->get_sql('images.date_available', $token))
        ->toBe("(images.date_available >= '2024-1-1' AND images.date_available <= '2024-12-31 23:59:59')");
});

test('QDateRangeScope parses an explicit date..date range', function (): void {
    $expr = new QExpression('created:2024-01-01..2024-06-30', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprDateData($token)[0])->toBe('2024-01-01');
    expect(qexprDateData($token)[1])->toBe('2024-06-30 23:59:59');
});

test('QDateRangeScope rejects a non-date value and drops the token', function (): void {
    $expr = new QExpression('created:not-a-date', qexprScopes());

    // parse() returning false makes QMultiToken::parse_expression() remove
    // the token entirely -- confirmed by an otherwise-untouched sibling
    // word surviving alone.
    $expr2 = new QExpression('created:not-a-date hello', qexprScopes());
    expect($expr2->stokens)->toHaveCount(1);
    expect($expr2->stokens[0]->term)->toBe('hello');
});

test('a plain QSearchScope throws on get_sql (only range scopes support it)', function (): void {
    $expr = new QExpression('tag:sunset', qexprScopes());
    $token = $expr->stokens[0];
    $scope = qexprScope($token->scope);

    expect(fn () => $scope->get_sql('images.tag', $token))
        ->toThrow(LogicException::class);
});

test('operator priority regroups "a OR b c" as "a OR (b c)"', function (): void {
    $expr = new QExpression('a OR b c', qexprScopes());

    // top level: [a, (b c)] -- the trailing AND-priority run gets grouped
    // under the OR so it binds together, not "( a OR b ) c".
    expect($expr->tokens)->toHaveCount(2);
    expect(qexprSingle($expr->tokens[0])->term)->toBe('a');
    expect($expr->tokens[1]->is_single)->toBeFalse();
    $group = qexprMulti($expr->tokens[1]);
    expect($group->tokens)->toHaveCount(2);
    expect(qexprSingle($group->tokens[0])->term)->toBe('b');
    expect(qexprSingle($group->tokens[1])->term)->toBe('c');
});
