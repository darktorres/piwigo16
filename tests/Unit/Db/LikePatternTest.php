<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Db;

use Piwigo\Db\LikePattern;

/**
 * Piwigo\Db\LikePattern -- escaping for `LIKE` patterns built from
 * untrusted text.
 *
 * Binding a parameter does not neutralise `%`/`_`: they are pattern
 * metacharacters, not injection vectors, so a bound `'%' . $term . '%'`
 * still lets a search for `100%` match everything.
 */
test('escape leaves text with no metacharacters untouched', function (): void {
    expect(LikePattern::escape('mountain'))
        ->toBe('mountain');
});

test('escape neutralises the percent wildcard', function (): void {
    expect(LikePattern::escape('100%'))
        ->toBe('100\\%');
});

test('escape neutralises the underscore wildcard', function (): void {
    expect(LikePattern::escape('foo_bar'))
        ->toBe('foo\\_bar');
});

/**
 * The escape character must be escaped *before* the wildcards, or a
 * literal backslash in the term would consume the escape that follows it:
 * escaping `%` first turns `\%` into `\\%`, where the doubled backslash is
 * a literal backslash and `%` is a live wildcard again.
 */
test('escape escapes the escape character itself, and does so first', function (): void {
    expect(LikePattern::escape('back\\slash'))
        ->toBe('back\\\\slash')
        ->and(LikePattern::escape('50\\%'))
        ->toBe('50\\\\\\%');
});

test('containing wraps an escaped term in wildcards', function (): void {
    expect(LikePattern::containing('100%'))
        ->toBe('%100\\%%');
});

test('startingWith anchors the term at the start', function (): void {
    expect(LikePattern::startingWith('foo_'))
        ->toBe('foo\\_%');
});

test('an empty term still produces a usable match-all pattern', function (): void {
    expect(LikePattern::containing(''))
        ->toBe('%%')
        ->and(LikePattern::startingWith(''))
        ->toBe('%');
});
