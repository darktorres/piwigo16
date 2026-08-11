<?php

declare(strict_types=1);

use Piwigo\Core\VersionHelper;

/**
 * Piwigo\Core\VersionHelper -- had zero dedicated coverage.
 */
test('safeVersionCompare returns the raw -1/0/1 result when no operator is given', function (): void {
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.1'))->toBe(-1);
    expect(VersionHelper::safeVersionCompare('1.0.1', '1.0.0'))->toBe(1);
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.0'))->toBe(0);
});

test('safeVersionCompare returns a bool when a known operator is given', function (): void {
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.1', '<'))->toBeTrue();
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.1', '>'))->toBeFalse();
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.0', '=='))->toBeTrue();
});

test('safeVersionCompare falls back to the raw -1/0/1 result for an unrecognized operator', function (): void {
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.1', 'not-a-real-operator'))->toBe(-1);
});

test('safeVersionCompare recognizes every one of its 14 documented operator aliases', function (): void {
    // Kills the remaining RemoveArrayItem mutations on $known_operators
    // this file's own 3-operator spot-check ('<', '>', '==') doesn't
    // reach: dropping any ONE alias makes that specific $op value fall
    // through to the raw -1/0/1 comparison instead of the intended
    // boolean -- confirmed live for 'lt' specifically (returns int(-1)
    // instead of bool(true) once removed from the list) -- the type
    // itself (int vs bool), not just the value, is what a dropped
    // alias breaks.
    $expected = [
        '<' => true,
        'lt' => true,
        '<=' => true,
        'le' => true,
        '>' => false,
        'gt' => false,
        '>=' => false,
        'ge' => false,
        '==' => false,
        '=' => false,
        'eq' => false,
        '!=' => true,
        '<>' => true,
        'ne' => true,
    ];

    foreach ($expected as $op => $expectedResult) {
        expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.1', $op))->toBe($expectedResult, "operator '{$op}'");
    }
});

test('safeVersionCompare inserts a dot before a trailing letter group, matching version_compare()\'s own convention', function (): void {
    expect(VersionHelper::safeVersionCompare('11.0.0a', '11.0.0b'))->toBe(-1);
});

test('safeVersionCompare converts a single trailing letter to its ordinal value', function (): void {
    expect(VersionHelper::safeVersionCompare('1.0.a', '1.0.b'))->toBe(-1);
});

test('safeVersionCompare lowercases a single trailing letter before taking its ordinal value', function (): void {
    // Kills line 20's UnwrapStrtolower: without it, 'A' (ord 65) and
    // 'a' (ord 97) compare as different values instead of equal ones.
    expect(VersionHelper::safeVersionCompare('1.0.A', '1.0.a'))->toBe(0);
});

/**
 * Confirmed-equivalent: line 20's 3 DecrementInteger mutations (using
 * $m[0] instead of $m[1] in the is_string() check, using $m[0] instead
 * of $m[1] as the value to lowercase, and indexing [-1] instead of [0]
 * after lowercasing) and its EmptyStringToNotEmpty (the '' fallback
 * when $m[1] isn't a string). The callback's own regex,
 * '#\b([a-z]{1})\b#i', captures the ENTIRE match as group 1 -- there's
 * nothing else in the pattern -- so $m[0] (the whole match) and $m[1]
 * (the captured group) are always identical strings here, and that
 * string is always exactly 1 character, so [0] and [-1] always refer
 * to the same character. The is_string()-false fallback is
 * unreachable for the same reason: preg_replace_callback() always
 * populates a matched group with a real string, never leaves it unset.
 * Confirmed live: every test in this file passes identically with
 * each of these 4 mutations applied individually.
 *
 * Also confirmed-equivalent: lines 27/28/32/34's RemoveStringCast
 * mutations (dropping `(string)` around $a/$b at each use site). $a/$b
 * are always genuine strings by construction (preg_replace() on a
 * string subject with these fixed, non-`u`-modifier patterns never
 * realistically returns null) -- the same defensive
 * PHPStan-satisfying-cast-with-no-reachable-null-case pattern already
 * established elsewhere in this test suite. Confirmed live: every test in
 * this file passes identically with each cast removed.
 *
 * Also confirmed-equivalent: line 31's BooleanOrToBooleanAnd (`$op ===
 * null && $op === ''` instead of `||`) and EmptyStringToNotEmpty (a
 * placeholder instead of `''`). Neither `null` nor `''` can ever be
 * literally present in $known_operators (a list of non-empty
 * operator strings), so `! in_array($op, $known_operators, true)` --
 * the chain's own THIRD disjunct -- already independently evaluates to
 * true for every null/empty/unrecognized $op, on its own, regardless
 * of what the first two disjuncts contribute. The first two are
 * genuinely redundant with the third for every possible input, not
 * just the ones this file's tests happen to exercise -- confirmed
 * live with $op = '' explicitly.
 */
test('getBranchFromVersion takes only the first dot-separated segment', function (): void {
    expect(VersionHelper::getBranchFromVersion('11.1.2'))->toBe('11');
    expect(VersionHelper::getBranchFromVersion('17.0'))->toBe('17');
    expect(VersionHelper::getBranchFromVersion('17'))->toBe('17');
});
