<?php

declare(strict_types=1);

use Piwigo\Core\ArrayHelper;

/**
 * Piwigo\Core\ArrayHelper -- had zero dedicated coverage despite
 * safeJsonDecode() being used constantly throughout the codebase (every
 * real caller passes a string, so the array-passthrough branch is never
 * hit; prependAppendArrayItems() is genuinely unused in production
 * today, tested anyway for completeness). safeUnserialize() itself was
 * deleted (P44-K) -- zero real call sites remained once
 * PiwigoInfosSender.php/CoreUpdateService.php's own unserialize() calls
 * moved to `['allowed_classes' => false]` directly.
 */
test('safeJsonDecode decodes a real JSON object string', function (): void {
    expect(ArrayHelper::safeJsonDecode('{"a":1}'))->toBe([
        'a' => 1,
    ]);
});

test('safeJsonDecode passes a non-string array through unchanged', function (): void {
    expect(ArrayHelper::safeJsonDecode([
        'a' => 1,
    ]))->toBe([
        'a' => 1,
    ]);
});

test('safeJsonDecode returns an empty array for malformed JSON or a JSON scalar', function (): void {
    expect(ArrayHelper::safeJsonDecode('not valid json'))->toBe([]);
    expect(ArrayHelper::safeJsonDecode('42'))->toBe([]);
});

/**
 * A mutation-testing sweep found the explicit `(string) $value` cast on
 * the scalar branch is a confirmed-equivalent mutant: removing it leaves
 * `$value` to be implicitly stringified by the surrounding `.` operator
 * itself, which performs the exact same coercion for every scalar type
 * (int, float, bool) that an explicit (string) cast would -- verified
 * live via a temporary sed-applied mutation across a wide range of
 * tricky values (large floats, scientific notation, -0.0, both bools),
 * all producing byte-identical output either way.
 */
test('prependAppendArrayItems wraps every scalar value, preserving keys', function (): void {
    expect(ArrayHelper::prependAppendArrayItems(['a', 'b', 3], '[', ']'))->toBe(['[a]', '[b]', '[3]']);
    expect(ArrayHelper::prependAppendArrayItems([
        'x' => 'y',
    ], '<', '>'))->toBe([
        'x' => '<y>',
    ]);
});

test('prependAppendArrayItems reduces a non-scalar value to just the prepend/append strings', function (): void {
    expect(ArrayHelper::prependAppendArrayItems([
        'x' => [1, 2],
    ], '[', ']'))->toBe([
        'x' => '[]',
    ]);
});

test('prependAppendArrayItems returns an empty array unchanged', function (): void {
    expect(ArrayHelper::prependAppendArrayItems([], '[', ']'))->toBe([]);
});
