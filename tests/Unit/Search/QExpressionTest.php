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
    if (! $scope instanceof QSearchScope) {
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

/**
 * @return array{0: string, 1: string}
 */
function qexprDateData(QSingleToken $token): array
{
    $data = $token->scope_data;
    if (! is_array($data) || ! array_key_exists(0, $data)) {
        throw new RuntimeException('Expected a date-range scope_data shape, got ' . var_export($data, true));
    }

    return $data;
}

/**
 * Deliberately bypasses QSingleToken::$scope_data's declared type (via
 * Reflection, not a direct property write) to construct malformed/
 * missing-index shapes for testing getSql()'s own defensive fallbacks --
 * these shapes are unreachable through real parsing, only through
 * QSearchScope::parse()'s own writers, which never produce them.
 */
function qexprSetScopeData(QSingleToken $token, mixed $value): void
{
    $property = new ReflectionProperty(QSingleToken::class, 'scope_data');
    $property->setValue($token, $value);
}

/**
 * Piwigo\Search\QExpression/QMultiToken/QSingleToken/QSearchScope/
 * QNumericRangeScope/QDateRangeScope -- the quick-search query-string
 * parser/tokenizer (AND/OR/NOT, quoted phrases, wildcards, `scope:value`
 * prefixes, parenthesized sub-expressions, numeric/date range scopes). Had
 * zero dedicated coverage despite being pure, side-effect-free, deterministic
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

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('hello');
    expect($expr->stokens[1]->term)->toBe('world');
    expect($expr->stoken_modifiers[0] & QSingleToken::QST_OR)->toBe(0);
    expect($expr->stoken_modifiers[1] & QSingleToken::QST_OR)->toBe(0);
});

test('the OR keyword sets QST_OR on the following token', function (): void {
    $expr = new QExpression('hello OR world', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stoken_modifiers[1] & QSingleToken::QST_OR)->toBe(QSingleToken::QST_OR);
});

test('a leading hyphen sets QST_NOT, the AND keyword is dropped entirely', function (): void {
    $expr = new QExpression('-hello AND world', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('hello');
    expect($expr->stoken_modifiers[0] & QSingleToken::QST_NOT)->toBe(QSingleToken::QST_NOT);
    expect($expr->stokens[1]->term)->toBe('world');
});

test('a quoted phrase becomes a single token with QST_QUOTED', function (): void {
    $expr = new QExpression('"hello world"', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('hello world');
    expect($expr->stokens[0]->modifier & QSingleToken::QST_QUOTED)->toBe(QSingleToken::QST_QUOTED);
});

test('a trailing wildcard sets QST_WILDCARD_END and strips the star', function (): void {
    $expr = new QExpression('hel*', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('hel');
    expect($expr->stokens[0]->modifier & QSingleToken::QST_WILDCARD_END)->toBe(QSingleToken::QST_WILDCARD_END);
});

test('a leading wildcard sets QST_WILDCARD_BEGIN', function (): void {
    $expr = new QExpression('*llo', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('llo');
    expect($expr->stokens[0]->modifier & QSingleToken::QST_WILDCARD_BEGIN)->toBe(QSingleToken::QST_WILDCARD_BEGIN);
});

test('a scope prefix resolves the token\'s scope by id', function (): void {
    $expr = new QExpression('tag:sunset', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    $token = $expr->stokens[0];
    expect($token->term)
        ->toBe('sunset');
    expect(qexprScope($token->scope)->id)
        ->toBe('tag');
});

test('a scope alias resolves to the same scope as its canonical id', function (): void {
    $expr = new QExpression('tags:sunset', qexprScopes());

    expect(qexprScope($expr->stokens[0]->scope)->id)->toBe('tag');
});

test('an unrecognized scope name is not consumed as a prefix -- the colon acts as a plain break, like whitespace', function (): void {
    $expr = new QExpression('notascope:value', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    $first = $expr->stokens[0];
    $second = $expr->stokens[1];
    expect($first->term)
        ->toBe('notascope');
    expect($first->scope)
        ->toBeNull();
    expect($second->term)
        ->toBe('value');
    expect($second->scope)
        ->toBeNull();
});

test('a nullable scope with an empty value still parses (author is nullable)', function (): void {
    $expr = new QExpression('author:', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    $token = $expr->stokens[0];
    expect($token->term)
        ->toBe('');
    expect(qexprScope($token->scope)->id)
        ->toBe('author');
});

test('parenthesized groups become a nested sub-expression, not flattened', function (): void {
    $expr = new QExpression('(hello OR world) foo', qexprScopes());

    expect($expr->tokens)
        ->toHaveCount(2);
    expect($expr->tokens[0]->is_single)->toBeFalse();
    expect(qexprMulti($expr->tokens[0])->tokens)->toHaveCount(2);
    expect($expr->tokens[1]->is_single)->toBeTrue();
    expect(qexprSingle($expr->tokens[1])->term)->toBe('foo');
});

test('__toString round-trips a simple OR/NOT expression', function (): void {
    $expr = new QExpression('hello OR -world', qexprScopes());

    expect((string) $expr)
        ->toBe('hello OR NOT world');
});

test('__toString wraps a sub-expression in parentheses', function (): void {
    $expr = new QExpression('(hello world)', qexprScopes());

    expect((string) $expr)
        ->toBe('(hello world)');
});

test('QNumericRangeScope parses an explicit min..max range', function (): void {
    $expr = new QExpression('filesize:100..500', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'])->toBe([100.0, 500.0]);
    // getSql() appends a trailing space per clause before implode(' AND
    // ', ...) adds its own -- the double space between clauses is the
    // real, exact output, not a typo.
    expect(qexprScope($token->scope)->getSql('images.filesize', $token))
        ->toBe('(images.filesize >=100  AND images.filesize <=500 )');
});

test('QNumericRangeScope applies a strict lower bound for a > prefix', function (): void {
    $expr = new QExpression('width:>800', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'][0])->toBe(800.0);
    expect(qexprRangeData($token)['strict'][0])->toBe(1);
    expect(qexprScope($token->scope)->getSql('images.width', $token))
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
    expect(qexprScope($token->scope)->getSql('images.date_available', $token))
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

    // parse() returning false makes QMultiToken::parseExpression() remove
    // the token entirely -- confirmed by an otherwise-untouched sibling
    // word surviving alone.
    $expr2 = new QExpression('created:not-a-date hello', qexprScopes());
    expect($expr2->stokens)
        ->toHaveCount(1);
    expect($expr2->stokens[0]->term)->toBe('hello');
});

test('a plain QSearchScope throws on getSql (only range scopes support it)', function (): void {
    $expr = new QExpression('tag:sunset', qexprScopes());
    $token = $expr->stokens[0];
    $scope = qexprScope($token->scope);

    // exact message text (not just the exception class) pins the
    // static::class . ' does not support getSql()' concatenation order.
    expect(fn (): string => $scope->getSql('images.tag', $token))
        ->toThrow(LogicException::class, 'Piwigo\Search\QSearchScope does not support getSql()');
});

test('operator priority regroups "a OR b c" as "a OR (b c)"', function (): void {
    $expr = new QExpression('a OR b c', qexprScopes());

    // top level: [a, (b c)] -- the trailing AND-priority run gets grouped
    // under the OR so it binds together, not "( a OR b ) c".
    expect($expr->tokens)
        ->toHaveCount(2);
    expect(qexprSingle($expr->tokens[0])->term)->toBe('a');
    expect($expr->tokens[1]->is_single)->toBeFalse();
    $group = qexprMulti($expr->tokens[1]);
    expect($group->tokens)
        ->toHaveCount(2);
    expect(qexprSingle($group->tokens[0])->term)->toBe('b');
    expect(qexprSingle($group->tokens[1])->term)->toBe('c');
});

test('operator priority regroups a 4-term "a OR b c d" so the whole AND-run joins the group', function (): void {
    // Distinct from the 3-term test above: the inner regroup loop
    // (QMultiToken::checkOperatorPriority()'s own `for ($j = $i + 1; ...)`)
    // only actually iterates once there's a 4th term to pull in -- with
    // only 3 terms the loop's own condition is never true.
    $expr = new QExpression('a OR b c d', qexprScopes());

    expect($expr->tokens)
        ->toHaveCount(2);
    expect(qexprSingle($expr->tokens[0])->term)->toBe('a');
    $group = qexprMulti($expr->tokens[1]);
    expect($group->tokens)
        ->toHaveCount(3);
    expect(qexprSingle($group->tokens[0])->term)->toBe('b');
    expect(qexprSingle($group->tokens[1])->term)->toBe('c');
    expect(qexprSingle($group->tokens[2])->term)->toBe('d');
});

test('operator priority stops regrouping at the next OR, using "a OR b c OR d"', function (): void {
    // The inner regroup loop's own break-on-lower-priority branch: 'd'
    // carries its own OR (lower priority than the AND run being
    // collected), so only (b c) get bundled under the leading OR -- 'd'
    // stays a separate top-level term with its OR intact.
    $expr = new QExpression('a OR b c OR d', qexprScopes());

    expect($expr->tokens)
        ->toHaveCount(3);
    expect(qexprSingle($expr->tokens[0])->term)->toBe('a');
    expect($expr->tokens[1]->is_single)->toBeFalse();
    $group = qexprMulti($expr->tokens[1]);
    expect($group->tokens)
        ->toHaveCount(2);
    expect(qexprSingle($group->tokens[0])->term)->toBe('b');
    expect(qexprSingle($group->tokens[1])->term)->toBe('c');
    expect(qexprSingle($expr->tokens[2])->term)->toBe('d');
    expect($expr->tokens[2]->modifier & QSingleToken::QST_OR)->toBe(QSingleToken::QST_OR);
});

test('QSearchScope::parse rejects an empty term when the scope is not nullable', function (): void {
    // Unreachable through QExpression's own tokenizer: QMultiToken::push()
    // already drops an empty-term token before scope->parse() is ever
    // called, unless the scope is nullable -- and a nullable scope's
    // parse() always returns true regardless of term length. Calling
    // parse() directly is the only way to exercise this branch.
    $scope = new QSearchScope('author', [], false);
    $token = new QSingleToken('', 0, $scope);

    expect($scope->parse($token))
        ->toBeFalse();
});

/**
 * QSingleToken::__toString()'s 5 mutation-sweep sites (line 74's `.=` on
 * the scope prefix, and the 4 `(bool)` casts guarding the WILDCARD_BEGIN/
 * QUOTED-open/QUOTED-close/WILDCARD_END appends at lines 76/79/83/86) are
 * all proven equivalent, not merely untested: line 74 is the very first
 * write to `$s` (still '' from line 72) whenever it executes, so `.=` and
 * `=` produce an identical result; the 4 `(bool)` casts are each the sole
 * operand of an `if (...)` condition, and PHP's `if` already performs the
 * exact same truthy conversion a `(bool)` cast would, so removing the cast
 * can never change which branch runs. Verified live: each of the 5 exact
 * mutations was applied to src/Piwigo/Search/QSingleToken.php in turn (incl.
 * the tests below, which exercise every modifier bit alone and combined)
 * and the full suite still passed every time; each mutation was reverted
 * immediately after.
 */
test('QSingleToken::__toString prefixes a leading wildcard alone', function (): void {
    $token = new QSingleToken('llo', QSingleToken::QST_WILDCARD_BEGIN, null);

    expect((string) $token)
        ->toBe('*llo');
});

test('QSingleToken::__toString wraps a quoted term alone in double quotes', function (): void {
    $token = new QSingleToken('hello world', QSingleToken::QST_QUOTED, null);

    expect((string) $token)
        ->toBe('"hello world"');
});

test('QSingleToken::__toString appends a trailing wildcard alone', function (): void {
    $token = new QSingleToken('hel', QSingleToken::QST_WILDCARD_END, null);

    expect((string) $token)
        ->toBe('hel*');
});

test('QSingleToken::__toString round-trips a scoped, quoted, doubly-wildcarded term', function (): void {
    $scope = new QSearchScope('tag', []);
    $modifier = QSingleToken::QST_WILDCARD_BEGIN | QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD_END;
    $token = new QSingleToken('mid', $modifier, $scope);

    expect((string) $token)
        ->toBe('tag:*"mid"*');
});

test('QDateRangeScope applies a strict lower bound for a > prefix, shifting to end-of-period', function (): void {
    $expr = new QExpression('created:>2020', qexprScopes());
    $token = $expr->stokens[0];

    // strict[0]=1 makes the year-only default land on Dec 31st 23:59:59
    // instead of Jan 1st -- "after 2020" means "from 2021 onward".
    expect(qexprDateData($token)[0])->toBe('2020-12-31 23:59:59');
    expect(qexprDateData($token)[1])->toBe('');
});

test('QDateRangeScope applies a strict upper bound for a < prefix', function (): void {
    $expr = new QExpression('created:<2020', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprDateData($token)[0])->toBe('');
    expect(qexprDateData($token)[1])->toBe('2020-1-1');
});

test('QDateRangeScope trailing wildcard sets the upper range only', function (): void {
    $expr = new QExpression('created:2020*', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprDateData($token)[0])->toBe('2020-1-1');
    expect(qexprDateData($token)[1])->toBe('');
});

test('QDateRangeScope getSql returns IS NOT NULL for an empty wildcarded nullable range', function (): void {
    $expr = new QExpression('created:*', qexprScopes());
    $token = $expr->stokens[0];

    // leading-wildcard-with-empty-term also exercises the WILDCARD_BEGIN
    // parse() branch itself (range = ['', $str]) before getSql() collapses
    // both sides to an empty clause list.
    expect(qexprDateData($token))
        ->toBe(['', '']);
    expect(qexprScope($token->scope)->getSql('images.date_creation', $token))
        ->toBe('images.date_creation IS NOT NULL');
});

test('QDateRangeScope getSql returns IS NULL for a plain empty nullable range', function (): void {
    $expr = new QExpression('created:', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprScope($token->scope)->getSql('images.date_creation', $token))
        ->toBe('images.date_creation IS NULL');
});

test('QDateRangeScope::parse rejects a fully-empty range on a non-nullable scope', function (): void {
    // Same unreachable-through-the-tokenizer reasoning as QSearchScope's
    // own direct-call test above -- 'posted' here is deliberately
    // non-nullable (unlike qexprScopes()'s own 'created'), and an empty
    // term never survives QMultiToken::push() for a non-nullable scope.
    $scope = new QDateRangeScope('posted', []);
    $token = new QSingleToken('', 0, $scope);

    expect($scope->parse($token))
        ->toBeFalse();
});

/**
 * QDateRangeScope carries 17 more mutation-sweep sites proven equivalent
 * (verified live, same methodology as above: each exact mutation applied
 * to src/Piwigo/Search/QDateRangeScope.php in turn against the full suite
 * incl. every test in this file, suite still passed every time, mutation
 * reverted immediately after). Grouped by why:
 * - The `(bool)` casts guarding `elseif`/`if` branch conditions (lines 40,
 *   42, 49, 58, 61, 94) and the 2 guarding the ternary conditions that
 *   pick month/day defaults (lines 52, 55) are each the sole operand of
 *   an `if`/`elseif`/ternary condition, which already performs the same
 *   truthy conversion.
 * - Line 31's $strict = [0, 0] initializer, decremented at index 1 to -1,
 *   and line 36's $strict[0] = 1, incremented to 2: every read of $strict
 *   goes through `$i ^ $strict[$i]` wrapped in a `(bool)` cast -- 1 XOR 0
 *   and 1 XOR -1 (in two's-complement) are BOTH nonzero, so any nonzero
 *   $strict value is exactly as truthy as any other; there is no way to
 *   distinguish "1" from "2", or "0" from "-1", through a boolean-only
 *   consumer.
 * - Lines 33/35/38's DecrementInteger mutations shift a substr() offset
 *   by 1 character, always landing exactly on one of the 2 literal '.'/
 *   '>'/'<' characters that immediately precede the intended value; the
 *   date regex is unanchored and always skips a single leading non-digit
 *   character to find the same 4-digit year run, so the parsed result is
 *   identical either way. (Line 33's *left*-side UnwrapSubstr, and a
 *   2-character PlusToMinus shift on the right side, are NOT equivalent
 *   and are covered by real tests below -- the 2-character shift can
 *   drive the substr() offset negative, and the left-side swap can inject
 *   real content into what should be an empty bound.)
 * - Line 55's IncrementInteger ($matches[2] -> $matches[3] as the day
 *   default's assignment target) is equivalent because PHP's implode()
 *   only cares about value order, not key values -- inserting the day
 *   default at a fresh key 3 instead of a fresh key 2 still appends it as
 *   the 3rd element in insertion order, producing the exact same joined
 *   string.
 * - Line 81's RemoveArrayItem (getSql()'s `['', '']` non-array fallback,
 *   shrunk to `['']`) is masked by the very next 2 lines, which read
 *   $date_range[0]/[1] through `?? ''` rather than direct indexing -- a
 *   missing array key is exactly what `??` is for, so the fallback's own
 *   element count never actually matters.
 */
test('QDateRangeScope substrs the upper bound just past the .. separator, not 2 chars before it', function (): void {
    // With an empty left side, mutating the offset from $pos + 2 to
    // $pos - 2 turns a small non-negative offset into a *negative* one --
    // PHP's substr() then counts from the end of the string instead,
    // grabbing unrelated trailing characters instead of the real
    // right-hand date, which then fails to parse and drops the token.
    // (This also incidentally kills the left-side UnwrapSubstr mutant on
    // the same line: with the left substr() unwrapped to the full $str,
    // the empty left bound would instead pick up the embedded date.)
    $expr = new QExpression('created:..2024-06-30', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprDateData($token))
        ->toBe(['', '2024-06-30 23:59:59']);
});

test('QDateRangeScope keeps an explicitly-given month instead of re-defaulting it', function (): void {
    // The month/day isset() checks must each read their own index: pins
    // that the month check reads $matches[1], not $matches[2] (the day's
    // index) -- with only the day missing, an explicitly-given month must
    // survive, not get silently re-defaulted just because the day was.
    $expr = new QExpression('created:2024-06', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprDateData($token)[0])->toBe('2024-06-1');
    expect(qexprDateData($token)[1])->toBe('2024-06-31 23:59:59');
});

test('QDateRangeScope\'s non-nullable empty-range guard reads range[1], not range[0] twice', function (): void {
    // A non-nullable scope going through the real tokenizer with only ONE
    // side of the range empty ('<' prefix leaves range[0] empty by
    // design) must still parse successfully -- only a *fully* empty range
    // should be rejected on a non-nullable scope.
    $scopes = [new QDateRangeScope('posted', [])];
    $expr = new QExpression('posted:<2020', $scopes);

    expect($expr->stokens)
        ->toHaveCount(1);
    expect(qexprDateData($expr->stokens[0]))->toBe(['', '2020-1-1']);
});

test('QDateRangeScope\'s non-nullable empty-range guard reads range[0], not range[1] twice', function (): void {
    // Symmetric case: '>' prefix leaves range[1] empty by design and must
    // still parse successfully on a non-nullable scope.
    $scopes = [new QDateRangeScope('posted', [])];
    $expr = new QExpression('posted:>2020', $scopes);

    expect($expr->stokens)
        ->toHaveCount(1);
    expect(qexprDateData($expr->stokens[0]))->toBe(['2020-12-31 23:59:59', '']);
});

test('QDateRangeScope getSql falls back to a fully-empty date-range shape when scope_data was never set', function (): void {
    // Constructed directly (unreachable through the tokenizer -- parse()
    // always writes a well-formed scope_data before getSql() is ever
    // called against a real token) to pin getSql()'s own defensive
    // ['', ''] fallback: with scope_data left null, both elements must be
    // exactly '' or the clause list stops being empty.
    $scope = new QDateRangeScope('created', []);
    $token = new QSingleToken('junk', 0, $scope);

    expect($scope->getSql('images.date_creation', $token))
        ->toBe('images.date_creation IS NULL');
});

test('QDateRangeScope getSql falls back the lower date bound to empty when scope_data is missing index 0', function (): void {
    // Constructed directly -- a well-formed array missing only index 0
    // (unlike the fully-null case above) exercises the coalesce's own
    // fallback value on line 82 specifically.
    $scope = new QDateRangeScope('created', []);
    $token = new QSingleToken('junk', 0, $scope);
    qexprSetScopeData($token, [
        1 => '2020-06-30 23:59:59',
    ]);

    expect($scope->getSql('images.date_creation', $token))
        ->toBe("(images.date_creation <= '2020-06-30 23:59:59')");
});

test('QDateRangeScope getSql falls back the upper date bound to empty when scope_data is missing index 1', function (): void {
    $scope = new QDateRangeScope('created', []);
    $token = new QSingleToken('junk', 0, $scope);
    qexprSetScopeData($token, [
        0 => '2020-01-01',
    ]);

    expect($scope->getSql('images.date_creation', $token))
        ->toBe("(images.date_creation >= '2020-01-01')");
});

test('QNumericRangeScope applies a strict upper bound for a < prefix', function (): void {
    $expr = new QExpression('width:<800', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'])->toBe(['', 800.0]);
    expect(qexprRangeData($token)['strict'][1])->toBe(1);
    // strict[1]=1 must suppress the '=' in getSql()'s upper-bound clause.
    expect(qexprScope($token->scope)->getSql('images.width', $token))
        ->toBe('(images.width <800 )');
});

test('QNumericRangeScope leading wildcard sets the lower range empty, upper to the value', function (): void {
    $expr = new QExpression('width:*800', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'])->toBe(['', 800.0]);
});

test('QNumericRangeScope trailing wildcard sets the upper range empty, lower to the value', function (): void {
    $expr = new QExpression('width:800*', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'])->toBe([800.0, '']);
});

test('QNumericRangeScope resolves an aperture-style fraction (a/b) for a bare value', function (): void {
    $expr = new QExpression('ratio:1/2', qexprScopes());
    $token = $expr->stokens[0];

    // bare value -> [term, term], epsilon (0.001) subtracted/added on each
    // side -- computed via the exact same floating-point expression the
    // production code performs, not a hand-rounded decimal literal.
    expect(qexprRangeData($token)['range'][0])->toBe(0.5 - 0.001);
    expect(qexprRangeData($token)['range'][1])->toBe(0.5 + 0.001);
});

test('QNumericRangeScope expands an M suffix to millions', function (): void {
    $expr = new QExpression('filesize:2m', qexprScopes());
    $token = $expr->stokens[0];

    // bare value: lower bound is the plain multiplication (no rounding,
    // i=0); upper bound rounds up to the next whole million minus 1,
    // mirroring the class's own "6k -> 6999" example scaled to millions.
    expect(qexprRangeData($token)['range'][0])->toBe(2000000.0);
    expect(qexprRangeData($token)['range'][1])->toBe(2999999.0);
});

test('QNumericRangeScope rounds a decimal k-suffixed value using its requested precision', function (): void {
    $expr = new QExpression('filesize:6.12k', qexprScopes());
    $token = $expr->stokens[0];

    // matches the class's own "6.12k goes only up to 6129" docblock
    // example -- 2 decimal digits of precision -> divisor 100.
    expect(qexprRangeData($token)['range'][0])->toBe(6120.0);
    expect(qexprRangeData($token)['range'][1])->toBe(6129.0);
});

/**
 * QNumericRangeScope carries 27 more mutation-sweep sites proven
 * equivalent (verified live: each exact mutation applied to
 * src/Piwigo/Search/QNumericRangeScope.php in turn against the full suite
 * incl. every test in this file, suite still passed every time, mutation
 * reverted immediately after). Grouped by why:
 * - parse()'s `(bool)` casts guarding every `elseif`/`if` branch condition
 *   (lines 42, 44, 52, 54, 64, 80) and getSql()'s own (line 119) are each
 *   the sole operand of an `if`/`elseif`, which already performs the same
 *   truthy conversion.
 * - The '' literals at lines 37/40/43/45 (the one-sided '>'/'<'/wildcard
 *   range slots) all flow through the very next `foreach` + regex-parsing
 *   loop (lines 51-86): a non-numeric placeholder matches neither regex,
 *   so it gets reset straight back to '' by that loop's own fallback
 *   (line 77) regardless of what non-empty placeholder it started as.
 * - Line 53's 2 `(float)` casts and its $matches[1]->$matches[0] swap: the
 *   aperture regex has no alternative path once matched, and
 *   floatval()/`/` already numeric-coerce plain numeric strings
 *   identically whether pre-cast or not; separately, $matches[0] is
 *   always $matches[1] . '/' . $matches[2], and float parsing stops at
 *   the first '/', so both indices parse to the same leading number.
 * - Line 55's $matches[1]->$matches[0] swap: same reasoning -- $matches[0]
 *   is $matches[1] plus an optional single k/m suffix char, and float
 *   parsing stops at that first non-digit character either way.
 * - Line 57's `$mult = 1` initializer (Decrement and Increment both) is
 *   dead: the very next if/else (lines 58-62) unconditionally overwrites
 *   $mult with 1000 or 1000000 before it's ever read.
 * - Lines 63, 70 (x2), 81, 83's `(float)` casts on arithmetic operands are
 *   redundant -- PHP's `*=`/`/`/`+=`/`-=` already promote an int operand
 *   to float whenever the other side is float, identical result either
 *   way.
 * - Line 66's $matches[1]->$matches[0] swap in the dot-position search:
 *   $matches[2] (the k/m suffix) never contains a '.', so searching
 *   within $matches[0] (= $matches[1] + that suffix) finds the same
 *   position as searching $matches[1] alone.
 * - Line 71's `> 1` boundary: $roundingMult = $mult / $divisor is always
 *   an exact power of 10 ($mult is 1000 or 1000000, $divisor is always
 *   10**n) -- it lands on exactly 1 (where `$val += $roundingMult - 1.0`
 *   adds exactly 0, making the >1-vs->=1 branch choice unobservable), or
 *   >=10, or <=0.1; nothing ever falls in (1, 2] to separate `> 1` from
 *   `>= 1` or `> 2`. (The `> 1` vs `> 0` boundary IS observable at 0.1 and
 *   is covered by a real test below.)
 * - Lines 112/115's `(string)` casts on $range_0/$range_1 are redundant --
 *   both values are always plain scalars (float or already-string) by the
 *   time they reach this concatenation, and PHP's `.` operator
 *   stringifies a scalar operand through the exact same conversion an
 *   explicit `(string)` cast would.
 */
test('QNumericRangeScope does not apply bare-value rounding to an explicit k-suffixed range', function (): void {
    // $range_requested starts true and only the bare-value (else) branch
    // ever flips it to false -- pins that initial true against an explicit
    // '..' range, whose k-suffixed upper bound must NOT get the "round up
    // to the next whole thousand" treatment reserved for bare values.
    $expr = new QExpression('filesize:1k..2k', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'])->toBe([1000.0, 2000.0]);
});

test('QNumericRangeScope resolves an aperture fraction on the lower bound of an explicit range', function (): void {
    // Pins that the '..' branch substr()s the *left* side down to just
    // before the separator -- with the left side left un-substr'd (the
    // full "1/2..3/4" string), the aperture regex's own trailing `$`
    // anchor would fail to match at all (there's a second '/' later in
    // the string), falling through to a completely different value.
    $expr = new QExpression('width:1/2..3/4', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'][0])->toBe(0.5);
    expect(qexprRangeData($token)['range'][1])->toBe(0.75);
});

test('QNumericRangeScope recognizes an uppercase K suffix as thousands', function (): void {
    // The 'k'/'K' check is 2 separate comparisons ($matches[2] against
    // both 'k' and 'K') -- pins that the second comparison also reads
    // $matches[2], not $matches[1] (which holds the digits, never 'K').
    $expr = new QExpression('filesize:6K', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'][0])->toBe(6000.0);
    expect(qexprRangeData($token)['range'][1])->toBe(6999.0);
});

test('QNumericRangeScope resolves a whole-number k-suffixed upper bound without a fractional-precision divisor', function (): void {
    // No decimal point in the term -- pins that the dot-search runs
    // against $matches[1] (the digits) and correctly finds no dot, rather
    // than treating a not-found `false` as if it were a real 0-based dot
    // position (which would fabricate a bogus multi-digit precision and
    // change the rounding divisor for any 2+ digit whole number).
    $expr = new QExpression('filesize:60k', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'][0])->toBe(60000.0);
    expect(qexprRangeData($token)['range'][1])->toBe(60999.0);
});

test('QNumericRangeScope skips upper-bound rounding when the requested precision exceeds the suffix multiplier', function (): void {
    // 4 fractional digits with a 'k' suffix (multiplier 1000 = 10**3)
    // makes the rounding multiplier (mult/divisor) drop below 1 -- there
    // is no "next value" to round up to, so the upper bound must stay
    // exactly the parsed value with no += adjustment at all.
    $expr = new QExpression('filesize:6.5000k', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprRangeData($token)['range'][1])->toBe(6500.0);
});

test('QNumericRangeScope getSql falls back to a fully-empty range/strict shape when scope_data was never set', function (): void {
    // Constructed directly (unreachable through the tokenizer -- parse()
    // always writes a well-formed scope_data before getSql() is ever
    // called against a real token) to pin getSql()'s own defensive
    // ['', ''] / [0, 0] fallback literals: with scope_data left null, both
    // fallback arrays must be exactly right or the clause list stops
    // being empty.
    $scope = new QNumericRangeScope('width', []);
    $token = new QSingleToken('junk', 0, $scope);

    expect($scope->getSql('images.width', $token))
        ->toBe('images.width IS NULL');
});

test('QNumericRangeScope getSql only trusts scope_data[\'range\'] when it is itself an array', function (): void {
    // Constructed directly -- pins is_array($scope_data) && is_array(...)
    // as an AND: a non-array 'range' value must fall back to ['', ''],
    // not be indexed into directly.
    $scope = new QNumericRangeScope('width', []);
    $token = new QSingleToken('junk', 0, $scope);
    qexprSetScopeData($token, [
        'range' => 'not-an-array',
        'strict' => [0, 0],
    ]);

    expect($scope->getSql('images.width', $token))
        ->toBe('images.width IS NULL');
});

test('QNumericRangeScope getSql only trusts scope_data[\'strict\'] when it is itself an array, reading index 0', function (): void {
    // Constructed directly -- a valid 'range' but a non-array 'strict'
    // must fall back to [0, 0], and this also exercises the fallback's
    // own first element (a malformed fallback missing/wrong at index 0
    // would flip the '=' sign on the lower-bound clause here).
    $scope = new QNumericRangeScope('width', []);
    $token = new QSingleToken('junk', 0, $scope);
    qexprSetScopeData($token, [
        'range' => [800.0, ''],
        'strict' => 'oops',
    ]);

    expect($scope->getSql('images.width', $token))
        ->toBe('(images.width >=800 )');
});

test('QNumericRangeScope getSql strict fallback is correct at index 1 too', function (): void {
    // Same as above but exercising the fallback's second element via the
    // upper-bound clause -- a malformed fallback missing/wrong at index 1
    // would flip the '=' sign (or fail outright) here instead.
    $scope = new QNumericRangeScope('width', []);
    $token = new QSingleToken('junk', 0, $scope);
    qexprSetScopeData($token, [
        'range' => ['', 800.0],
        'strict' => null,
    ]);

    expect($scope->getSql('images.width', $token))
        ->toBe('(images.width <=800 )');
});

test('QNumericRangeScope getSql reads strict[1], not strict[0], for the upper-bound clause', function (): void {
    // Constructed directly -- the real tokenizer/parse() never produces an
    // asymmetric strict pair together with both range bounds present at
    // once (an explicit '..' range or a bare value always yields
    // strict = [0, 0]; a strict '>'/'<' prefix always leaves the opposite
    // bound empty), so this is unreachable through QExpression's own
    // tokenizer.
    $scope = new QNumericRangeScope('width', []);
    $token = new QSingleToken('junk', 0, $scope);
    $token->scope_data = [
        'range' => [100.0, 800.0],
        'strict' => [0, 1],
    ];

    expect($scope->getSql('images.width', $token))
        ->toBe('(images.width >=100  AND images.width <800 )');
});

test('QNumericRangeScope rejects a non-numeric value on a non-nullable scope and drops the token', function (): void {
    $expr = new QExpression('width:abc hello', qexprScopes());

    // parse() returns false (both sides collapse to '' with no digits at
    // all to match) -> the token is removed, same removal mechanism as
    // the QDateRangeScope non-date test above.
    expect($expr->stokens)
        ->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('hello');
});

test('QNumericRangeScope getSql returns IS NULL for a plain empty nullable range', function (): void {
    $expr = new QExpression('score:', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprScope($token->scope)->getSql('images.rating_score', $token))
        ->toBe('images.rating_score IS NULL');
});

test('QNumericRangeScope getSql returns IS NOT NULL for an empty wildcarded nullable range', function (): void {
    $expr = new QExpression('score:*', qexprScopes());
    $token = $expr->stokens[0];

    expect(qexprScope($token->scope)->getSql('images.rating_score', $token))
        ->toBe('images.rating_score IS NOT NULL');
});

test('an opening paren pushes pending leading text before recursing', function (): void {
    $expr = new QExpression('hello(world)', qexprScopes());

    expect($expr->tokens)
        ->toHaveCount(2);
    expect(qexprSingle($expr->tokens[0])->term)->toBe('hello');
    expect($expr->tokens[1]->is_single)->toBeFalse();
    $group = qexprMulti($expr->tokens[1]);
    expect($group->tokens)
        ->toHaveCount(1);
    expect(qexprSingle($group->tokens[0])->term)->toBe('world');
});

test('a text scope applied before a parenthesized group scopes every word inside it', function (): void {
    $expr = new QExpression('tag:(sunset OR sunrise)', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect(qexprScope($expr->stokens[0]->scope)->id)->toBe('tag');
    expect(qexprScope($expr->stokens[1]->scope)->id)->toBe('tag');
});

test('a text scope applied before a parenthesized group recurses into a nested sub-group too', function (): void {
    $expr = new QExpression('tag:(sunset (deep))', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect(qexprSingle($expr->stokens[0])->term)->toBe('sunset');
    expect(qexprScope($expr->stokens[0]->scope)->id)->toBe('tag');
    expect(qexprSingle($expr->stokens[1])->term)->toBe('deep');
    expect(qexprScope($expr->stokens[1]->scope)->id)->toBe('tag');
});

test('a quote immediately following pending text pushes that text first', function (): void {
    $expr = new QExpression('hello"world"', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('hello');
    expect($expr->stokens[1]->term)->toBe('world');
    expect($expr->stokens[1]->modifier & QSingleToken::QST_QUOTED)->toBe(QSingleToken::QST_QUOTED);
});

test('a dot between two digits is kept as part of the term, not treated as a separator', function (): void {
    $expr = new QExpression('2.8', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('2.8');
});

test('a wildcard immediately after a closing quote sets QST_WILDCARD_END', function (): void {
    $expr = new QExpression('"hello world"*', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('hello world');
    $modifier = $expr->stokens[0]->modifier;
    expect($modifier & QSingleToken::QST_QUOTED)->toBe(QSingleToken::QST_QUOTED);
    expect($modifier & QSingleToken::QST_WILDCARD_END)->toBe(QSingleToken::QST_WILDCARD_END);
});

test('the NOT keyword sets QST_NOT on the following token and is dropped entirely', function (): void {
    $expr = new QExpression('NOT hello', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('hello');
    expect($expr->stokens[0]->modifier & QSingleToken::QST_NOT)->toBe(QSingleToken::QST_NOT);
});

test('an empty parenthesized group is dropped entirely', function (): void {
    $expr = new QExpression('() hello', qexprScopes());

    expect($expr->tokens)
        ->toHaveCount(1);
    expect(qexprSingle($expr->tokens[0])->term)->toBe('hello');
});

test('a scope alias is stored lowercased regardless of its declared casing', function (): void {
    // QExpression::__construct() indexes $this->scopes by strtolower($alias),
    // but the tokenizer (QMultiToken::parseExpression()'s `case ':'`) also
    // looks up strtolower($crt_token) -- so a non-lowercased stored alias
    // would never match a real query, which always arrives lowercased at
    // the lookup site.
    $scopes = [new QSearchScope('tag', ['Tags'])];
    $expr = new QExpression('tags:sunset', $scopes);

    expect($expr->stokens)
        ->toHaveCount(1);
    expect(qexprScope($expr->stokens[0]->scope)->id)->toBe('tag');
});

test('a stray closing paren at the top level is ignored, parsing continues past it', function (): void {
    // QExpression::__construct() calls parseExpression() with a hardcoded
    // level of 0 -- only level > 0 (i.e. inside a real opened paren) makes
    // ')' stop the parse. At level 0 a ')' is simply consumed and parsing
    // carries on, so both words survive as separate tokens.
    $expr = new QExpression('hello) world', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('hello');
    expect($expr->stokens[1]->term)->toBe('world');
});

/**
 * 2 more of QExpression's mutation-sweep sites are proven equivalent:
 * the literal `0` passed as buildSingleTokens()'s initial $this_is_not
 * (line 48) is immediately XORed then masked with `& QST_NOT` (0x02) at
 * line 55 -- XORing with 1 instead of 0 only ever flips bit 0x01 (QUOTED),
 * which the following `& QST_NOT` mask discards regardless, so the 2
 * literals produce an identical $crt_is_not for every possible modifier.
 * And the `(bool)` cast on line 62's `if ((bool) $crt_is_not)` is, like
 * QSingleToken's own casts above, the sole operand of an `if` condition,
 * so removing it cannot change which branch runs. Verified live: both
 * exact mutations were applied to src/Piwigo/Search/QExpression.php in
 * turn and the full suite (including the tests below) still passed both
 * times; each mutation was reverted immediately after.
 */
test('a plain unnegated word never picks up QST_NOT via buildSingleTokens', function (): void {
    // buildSingleTokens()'s $crt_is_not computation ANDs the XOR result
    // with QST_NOT -- pins that AND (not OR) so an all-zero modifier stays
    // falsy instead of being forced truthy by a stray OR with QST_NOT.
    $expr = new QExpression('hello', qexprScopes());

    expect($expr->stoken_modifiers[0] & QSingleToken::QST_NOT)->toBe(0);
});

test('a negated wildcard-end token keeps both its NOT and WILDCARD_END bits in stoken_modifiers', function (): void {
    // buildSingleTokens()'s truthy-$crt_is_not branch ORs QST_NOT into a
    // copy of the token's full modifier -- pins the OR (not AND), which
    // would otherwise wipe every other bit (here QST_WILDCARD_END) off the
    // stored stoken_modifiers entry.
    $expr = new QExpression('-hel*', qexprScopes());

    $modifier = $expr->stoken_modifiers[0];
    expect($modifier & QSingleToken::QST_NOT)->toBe(QSingleToken::QST_NOT);
    expect($modifier & QSingleToken::QST_WILDCARD_END)->toBe(QSingleToken::QST_WILDCARD_END);
});

/*
 * QMultiToken mutation-sweep coverage (resumes after QSearchScope,
 * QSingleToken, QExpression, QNumericRangeScope, QDateRangeScope,
 * covered above).
 */

test('QMultiToken::__toString concatenates the opening paren onto pending sibling text, not overwrites it', function (): void {
    // Line 44 is `$s .= '(';` -- an accidental `$s = '(';` would silently
    // discard everything __toString had already built for the preceding
    // sibling token. Needs a sub-expression that is NOT the first token so
    // there is real pending text ($s === 'hello ') for the overwrite to
    // destroy.
    $expr = new QExpression('hello (world)', qexprScopes());

    expect((string) $expr)
        ->toBe('hello (world)');
});

test('push() marks a scoped token with QST_BREAK, but leaves a scopeless token without it', function (): void {
    // push()'s `if (isset($scope))` guard (line 61) decides whether the
    // new token is stamped QST_BREAK. A negated guard would flip which of
    // the two tokens below gets the bit.
    $expr = new QExpression('tag:sunset hello', qexprScopes());

    expect($expr->stokens[0]->modifier & QSingleToken::QST_BREAK)->toBe(QSingleToken::QST_BREAK);
    expect($expr->stokens[1]->modifier & QSingleToken::QST_BREAK)->toBe(0);
});

test('closing a parenthesized group resets the pending modifier before parsing continues', function (): void {
    // Line 101's `$crt_modifier = 0;` reset, exercised by what follows a
    // ')': if it were anything but 0 (e.g. carrying a stray QST_QUOTED
    // bit), the very next character would wrongly be parsed in quoted
    // mode, swallowing the rest of the string including the separating
    // space into a single mangled token instead of a clean 'world'.
    $expr = new QExpression('(hello) world', qexprScopes());

    expect($expr->tokens)
        ->toHaveCount(2);
    expect($expr->tokens[1]->is_single)->toBeTrue();
    expect(qexprSingle($expr->tokens[1])->term)->toBe('world');
    expect($expr->tokens[1]->modifier)->toBe(0);
});

test('a scope prefix lookup is case-insensitive regardless of the query text\'s casing', function (): void {
    // Line 110's `strtolower($crt_token)` on the scope lookup key -- every
    // scope id/alias is stored lowercased (see QExpression::__construct),
    // so an unlowercased lookup would never resolve a real query.
    $expr = new QExpression('TAG:sunset', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    expect(qexprScope($expr->stokens[0]->scope)->id)->toBe('tag');
    expect($expr->stokens[0]->term)->toBe('sunset');
});

test('a leading hyphen is appended as a literal character once a scope is already active, even with an empty token', function (): void {
    // Line 125's `strlen($crt_token) || isset($crt_scope)` -- with an
    // active scope and an EMPTY pending token, only the isset($crt_scope)
    // side of the OR is true; an AND would instead treat the hyphen as
    // the NOT operator and drop it from the term entirely.
    $expr = new QExpression('tag:-foo', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('-foo');
    expect($expr->stokens[0]->modifier & QSingleToken::QST_NOT)->toBe(0);
});

test('a mid-word hyphen is appended as a literal character with no active scope, only pending text', function (): void {
    // Symmetric case to the one above: here only strlen($crt_token) is
    // true (no scope at all) -- an AND would again misfire the NOT branch
    // instead of treating the hyphen as part of the word.
    $expr = new QExpression('foo-bar', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('foo-bar');
    expect($expr->stokens[0]->modifier & QSingleToken::QST_NOT)->toBe(0);
});

test('the digit-joining dot check only inspects the very last character of the pending token', function (): void {
    // Line 144's `substr($crt_token, -1)` -- if the check ever looked at
    // the whole token (or any wider trailing window) instead of just its
    // final character, the embedded digit in 'a2b' would be found
    // regardless. The token actually ends in a non-digit ('b'), so the
    // '.' must still act as a plain separator.
    $expr = new QExpression('a2b.5', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('a2b');
    expect($expr->stokens[1]->term)->toBe('5');
});

test('the digit-joining dot check requires every chained condition to hold, not just one of them', function (): void {
    // Line 144's 4-term `&&` chain (pending token non-empty, ends in a
    // digit, a next character exists, that next character is a digit).
    // 'ab' has no digit anywhere so the "ends in a digit" leg is false,
    // but the trailing legs (next char '5' exists and is a digit) are
    // still true -- any single `&&` turned into `||` would wrongly join
    // the dot here.
    $expr = new QExpression('ab.5', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('ab');
    expect($expr->stokens[1]->term)->toBe('5');
});

test('the digit-joining dot lookahead reads the character AFTER the dot, not before it', function (): void {
    // Line 145's `$q[$qi + 1]` -- reading $q[$qi - 1] instead would find
    // the digit '2' that was already consumed into the pending token
    // (always a digit, by construction of this very check), wrongly
    // joining the dot regardless of what actually follows it.
    $expr = new QExpression('2.a', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('2');
    expect($expr->stokens[1]->term)->toBe('a');
});

test('a trailing dot at the very end of the query never looks past the end of the string', function (): void {
    // Line 145's `$qi + 1 < strlen($q)` boundary check -- with '.' as the
    // very last character, $qi + 1 === strlen($q), so this must be false
    // and short-circuit before ever reading $q[$qi + 1]. A weakened
    // boundary (`<=`, or an off-by-one via +0/-1) lets execution reach
    // that read out of bounds.
    $expr = new QExpression('2.', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(1);
    expect($expr->stokens[0]->term)->toBe('2');
});

test('a comma acts as a plain word separator', function (): void {
    // Line 153's in_array() separator list -- one test per remaining
    // untested character (space is already exercised everywhere else in
    // this file; each of these 5 needs its own scenario since removing
    // any single array item is a distinct mutant).
    $expr = new QExpression('a,b', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('a');
    expect($expr->stokens[1]->term)->toBe('b');
});

test('a semicolon acts as a plain word separator', function (): void {
    $expr = new QExpression('a;b', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('a');
    expect($expr->stokens[1]->term)->toBe('b');
});

test('an exclamation mark acts as a plain word separator', function (): void {
    $expr = new QExpression('a!b', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('a');
    expect($expr->stokens[1]->term)->toBe('b');
});

test('a question mark acts as a plain word separator', function (): void {
    $expr = new QExpression('a?b', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('a');
    expect($expr->stokens[1]->term)->toBe('b');
});

test('a dot between two non-digit words acts as a plain word separator', function (): void {
    // Distinct from the digit-joining '.' tests above: neither side of
    // this dot is a digit, so it never reaches the digit-joining branch
    // at all and must fall straight through to this same in_array() list.
    $expr = new QExpression('hello.world', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('hello');
    expect($expr->stokens[1]->term)->toBe('world');
});

test('a closing quote pushes the quoted token immediately so parsing resumes in normal mode', function (): void {
    // Line 167's push() call inside the quoted branch -- without it, the
    // QST_QUOTED bit set when the quote opened is never cleared by a
    // reset, so every character after the closing quote (including the
    // separating space) would keep being consumed as quoted content
    // instead of starting a fresh 'world' token.
    $expr = new QExpression('"hello" world', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('hello');
    expect($expr->stokens[0]->modifier & QSingleToken::QST_QUOTED)->toBe(QSingleToken::QST_QUOTED);
    expect($expr->stokens[1]->term)->toBe('world');
    expect($expr->stokens[1]->modifier & QSingleToken::QST_QUOTED)->toBe(0);
});

test('a scoped token literally named "not" is preserved as a value, not stripped as a keyword', function (): void {
    // Line 186's `!isset($token->scope) && (...) === 0` guard -- an OR
    // would let a *scoped* "not" (whose only other bit is QST_BREAK, not
    // covered by the QUOTED|WILDCARD mask) still be treated as the NOT
    // keyword and stripped/propagated, when it should be left alone as a
    // real scope value.
    $expr = new QExpression('tag:not hello', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('not');
    expect(qexprScope($expr->stokens[0]->scope)->id)->toBe('tag');
    expect($expr->stokens[1]->term)->toBe('hello');
    expect($expr->stokens[1]->modifier & QSingleToken::QST_NOT)->toBe(0);
});

test('a quoted token literally "not" keeps its quoted value instead of being stripped as a keyword', function (): void {
    // Line 187's `(QST_QUOTED | QST_WILDCARD)` mask -- turning that `|`
    // into `&` collapses the mask to 0 (QST_QUOTED & QST_WILDCARD is
    // always 0), so the exclusion never applies to ANY modifier and even
    // a quoted "not" gets wrongly treated as the keyword.
    $expr = new QExpression('"not" hello', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(2);
    expect($expr->stokens[0]->term)->toBe('not');
    expect($expr->stokens[0]->modifier & QSingleToken::QST_QUOTED)->toBe(QSingleToken::QST_QUOTED);
    expect($expr->stokens[1]->term)->toBe('hello');
    expect($expr->stokens[1]->modifier & QSingleToken::QST_NOT)->toBe(0);
});

test('the NOT keyword clears itself even as the very last token, without indexing past the array', function (): void {
    // Line 189's `$i + 1 < count($this->tokens)` guard protects the
    // following `$this->tokens[$i + 1]->modifier` access -- weakening it
    // (+0, -1, or <=) lets that access run when 'NOT' is the very last
    // token, indexing one past the end and crashing on a null property
    // access instead of simply dropping the trailing keyword.
    $expr = new QExpression('hello NOT', qexprScopes());

    expect($expr->tokens)
        ->toHaveCount(1);
    expect(qexprSingle($expr->tokens[0])->term)->toBe('hello');
});

test('the OR keyword clears itself even as the very last token, without indexing past the array', function (): void {
    // Same guard as above, line 195, for the 'or' keyword branch.
    $expr = new QExpression('hello OR', qexprScopes());

    expect($expr->tokens)
        ->toHaveCount(1);
    expect(qexprSingle($expr->tokens[0])->term)->toBe('hello');
});

test('removing a keyword token rechecks the same index instead of skipping the next one', function (): void {
    // Line 222's `$i--;` re-visits the index a just-removed token vacated
    // (the next element shifted into it) -- a stray `$i++` would instead
    // skip straight past that shifted-in element. Two back-to-back
    // removable 'AND' tokens make the skip observable: the second one
    // only ever gets its own removal pass if the index is properly
    // rewound.
    $expr = new QExpression('a AND AND b', qexprScopes());

    expect($expr->tokens)
        ->toHaveCount(2);
    expect(qexprSingle($expr->tokens[0])->term)->toBe('a');
    expect(qexprSingle($expr->tokens[1])->term)->toBe('b');
});

test('the top-level expression never marks its own first token with QST_BREAK', function (): void {
    // Line 226's `$level > 0` guard -- only a genuine nested
    // sub-expression (level >= 1) should get this treatment. A weakened
    // comparison (>=, or an off-by-one via -1) would wrongly stamp the
    // root's own first token too.
    $expr = new QExpression('hello world', qexprScopes());

    expect($expr->tokens[0]->modifier & QSingleToken::QST_BREAK)->toBe(0);
});

test('a parenthesized sub-expression marks its own first token with QST_BREAK', function (): void {
    // Symmetric case: a real level-1 sub-expression must still get the
    // marking -- an inverted or off-by-one comparison (<=, or +1) would
    // wrongly skip it here.
    $expr = new QExpression('(hello world)', qexprScopes());

    $group = qexprMulti($expr->tokens[0]);
    expect($group->tokens[0]->modifier & QSingleToken::QST_BREAK)->toBe(QSingleToken::QST_BREAK);
    expect($group->tokens[1]->modifier & QSingleToken::QST_BREAK)->toBe(0);
});

test('a sub-expression\'s QST_BREAK marking preserves the first token\'s other existing modifier bits', function (): void {
    // Line 227's `|=` -- turning it into `&=` would wipe every bit that
    // isn't QST_BREAK itself off the first token, losing the QST_NOT bit
    // this token already carries from its leading hyphen.
    $expr = new QExpression('(-hello world)', qexprScopes());

    $group = qexprMulti($expr->tokens[0]);
    $first = qexprSingle($group->tokens[0]);
    expect($first->modifier & QSingleToken::QST_NOT)->toBe(QSingleToken::QST_NOT);
    expect($first->modifier & QSingleToken::QST_BREAK)->toBe(QSingleToken::QST_BREAK);
});

test('checkOperatorPriority recurses into an already-nested sub-expression to regroup it too', function (): void {
    // Lines 261-262: without this recursive descent (however it's
    // suppressed -- skipped index, disabled instanceof check, or a
    // removed call), a parenthesized group's OWN internal operator
    // priority never gets resolved, leaving it flat instead of regrouped.
    $expr = new QExpression('(a OR b c) x', qexprScopes());

    $group = qexprMulti($expr->tokens[0]);
    expect($group->tokens)
        ->toHaveCount(2);
    expect(qexprSingle($group->tokens[0])->term)->toBe('a');
    $inner = qexprMulti($group->tokens[1]);
    expect($inner->tokens)
        ->toHaveCount(2);
    expect(qexprSingle($inner->tokens[0])->term)->toBe('b');
    expect(qexprSingle($inner->tokens[1])->term)->toBe('c');
});

test('operator priority regrouping never drops a trailing OR-led term when the AND-run inner scan is reversed', function (): void {
    // Covers 3 different lines that only misbehave together on a chain
    // with 2 separate OR-boundaries and enough terms between them for the
    // inner "how far does this AND-run extend" scan (line 273) to matter:
    // - line 273: scanning the inner run backwards (instead of forwards)
    //   re-visits already-counted terms instead of reaching the next one,
    //   miscounting how many terms belong to the first group.
    // - line 278: `break` turned to `continue` on hitting the next OR
    //   boundary would keep scanning past it instead of stopping there.
    // - line 288: the array_splice() that inserts the freshly-built group
    //   back in must remove nothing (length 0) -- a negative length
    //   deletes real trailing tokens ('d' here) as a side effect of the
    //   insert.
    $expr = new QExpression('a OR b c OR d e', qexprScopes());

    expect($expr->stokens)
        ->toHaveCount(5);
    expect($expr->tokens)
        ->toHaveCount(3);
    expect(qexprSingle($expr->tokens[0])->term)->toBe('a');
    $group1 = qexprMulti($expr->tokens[1]);
    expect($group1->tokens)
        ->toHaveCount(2);
    expect(qexprSingle($group1->tokens[0])->term)->toBe('b');
    expect(qexprSingle($group1->tokens[1])->term)->toBe('c');
    $group2 = qexprMulti($expr->tokens[2]);
    expect($group2->tokens)
        ->toHaveCount(2);
    expect(qexprSingle($group2->tokens[0])->term)->toBe('d');
    expect(qexprSingle($group2->tokens[1])->term)->toBe('e');
});

test('operator priority regrouping never truncates a long AND-run by wrongly clamping it early', function (): void {
    // A longer, unbroken AND-run (line 273's inner scan going all the way
    // to the end of the array, not stopped by any OR) makes a reversed
    // scan direction diverge in element COUNT too, not just which
    // specific elements: 'f' would be left stranded outside the group.
    $expr = new QExpression('a OR b c d e f', qexprScopes());

    expect($expr->tokens)
        ->toHaveCount(2);
    $group = qexprMulti($expr->tokens[1]);
    expect($group->tokens)
        ->toHaveCount(5);
    expect(qexprSingle($group->tokens[4])->term)->toBe('f');
});

test('regrouping into a sub-expression transfers only the OR bit from the first regrouped token', function (): void {
    // Lines 289-290: `$sub->modifier = $sub->tokens[0]->modifier & QST_OR;`
    // then `$sub->tokens[0]->modifier &= ~QST_OR;`. The first regrouped
    // token ('b') carries QST_NOT too (from its leading hyphen) plus
    // QST_BREAK (from the keyword-removal cleanup) -- neither may leak
    // onto the sub-group's own modifier, and clearing QST_OR off 'b' must
    // not touch its other bits, nor accidentally target the wrong token
    // ('c', which never had QST_OR to begin with).
    $expr = new QExpression('a OR -b c', qexprScopes());

    $group = qexprMulti($expr->tokens[1]);
    expect($group->modifier)
        ->toBe(QSingleToken::QST_OR);
    $first = qexprSingle($group->tokens[0]);
    expect($first->modifier & QSingleToken::QST_OR)->toBe(0);
    expect($first->modifier & QSingleToken::QST_NOT)->toBe(QSingleToken::QST_NOT);
});

test('a freshly regrouped sub-expression recurses checkOperatorPriority into an element swept up from a nested group', function (): void {
    // Line 292's `$sub->checkOperatorPriority();` -- the parenthesized
    // group here gets swept into the (b, c, group) run by the OUTER
    // scan's inner loop before the outer loop itself ever visits that
    // index directly, so its own internal 'x OR y z' regrouping ONLY
    // happens if the freshly built sub recurses into its own tokens.
    $expr = new QExpression('a OR b c (x OR y z)', qexprScopes());

    $group = qexprMulti($expr->tokens[1]);
    expect($group->tokens)
        ->toHaveCount(3);
    $nested = qexprMulti($group->tokens[2]);
    expect($nested->tokens)
        ->toHaveCount(2);
    expect(qexprSingle($nested->tokens[0])->term)->toBe('x');
    $innermost = qexprMulti($nested->tokens[1]);
    expect(qexprSingle($innermost->tokens[0])->term)->toBe('y');
    expect(qexprSingle($innermost->tokens[1])->term)->toBe('z');
});

/**
 * QMultiToken carries 25 more mutation-sweep sites proven equivalent
 * (verified live, same methodology as the QSingleToken/QDateRangeScope/
 * QNumericRangeScope blocks above: each exact mutation applied to
 * src/Piwigo/Search/QMultiToken.php in turn against the full suite incl.
 * every test in this file -- suite still passed every time, mutation
 * reverted immediately after). Grouped
 * by why:
 * - The `(bool)` casts at lines 34, 37, 40, 60, 90, 119, 125, 132, 144
 *   (both), 145, 152, 205, 214, 226 and the ternary condition's cast at
 *   line 250 are each either the sole operand of an `if`/`elseif`
 *   condition, or an operand of a `&&`/`||` chain that is itself the
 *   whole `if` condition -- PHP already performs the identical truthy
 *   conversion at the `if`/`&&`/`||` itself, so an explicit `(bool)` cast
 *   anywhere inside that expression can never change which branch runs.
 * - Lines 45 and 48's `(string)` casts on `$this->tokens[$i]` are
 *   redundant: both QSingleToken and QMultiToken implement `Stringable`,
 *   and PHP already auto-invokes `__toString()` on `.=` for any object
 *   that implements it, with or without an explicit cast.
 * - Line 95's `$level + 1` (bumped to `$level + 2`) is unobservable:
 *   `$level` starts at 0 and is only ever incremented on recursion, and
 *   every read of it (line 105's `case ')'` and line 226's own BREAK
 *   guard) only ever tests `> 0`, never a specific value -- any positive
 *   increment preserves "is this a nested call" exactly as well as +1
 *   does.
 * - Line 250's ternary branch values (0 and 1, independently perturbed to
 *   -1 and 2) are never used arithmetically -- priority()'s return value
 *   is only ever compared via `>`/`>=` against another priority() result.
 *   Since an OR-flagged modifier's priority stays strictly less than a
 *   non-OR modifier's priority under both substitutions (-1 < 1, and
 *   0 < 2, same as the real 0 < 1), every comparison in
 *   checkOperatorPriority() resolves identically either way. (The
 *   *other* two possible perturbations of this same ternary -- collapsing
 *   both branches to equal values, which WOULD break that ordering -- are
 *   real bugs already caught by the "regroups" tests above, not among
 *   these 22.)
 * - Line 258's `$crt_prio = 0` initializer (perturbed to -1 or 1) is dead:
 *   the loop's own `if ($i === 1) { $crt_prio = ...; }` unconditionally
 *   overwrites it before the first read at `$i >= 2` (guarded by
 *   `if ($i <= 1) { continue; }`), and when the token list has fewer than
 *   2 entries, $crt_prio is never read at all either way.
 * - Line 267's `$i <= 1` guard (weakened to `$i < 1` or `$i <= 0` --
 *   identical to each other for integer $i) only changes behavior by
 *   letting execution fall through instead of `continue`-ing at exactly
 *   $i === 1. But the immediately preceding line just set
 *   `$crt_prio = priority($token->modifier)` for that SAME token, so
 *   falling through recomputes `$prio = priority($token->modifier)` on
 *   the identical token and compares it to itself -- always equal, so
 *   `$prio > $crt_prio` is always false and execution lands in the
 *   harmless `else { $crt_prio = $prio; }`, a no-op re-assignment to the
 *   value it already holds.
 */
