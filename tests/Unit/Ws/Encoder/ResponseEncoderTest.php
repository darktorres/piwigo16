<?php

declare(strict_types=1);

use Piwigo\Ws\Encoder\ResponseEncoder;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;

/**
 * ResponseEncoder is abstract, but isStruct() and flattenResponse()
 * are both real `public static` methods callable without subclassing --
 * this file drives them directly, same convention as the sibling
 * RestEncoderTest/SerialPhpEncoderTest files in ../Protocol/ but
 * without needing a concrete encodeResponse()/getContentType()
 * implementation at all.
 *
 * tests/Unit/Ws/Protocol/SerialPhpEncoderTest.php already exercises
 * flatten()'s NamedArray/NamedStruct unwrap and the ATTRIBUTES_KEY
 * merge indirectly (through SerialPhpEncoder::encodeResponse()), but
 * that file's own docblock explicitly declines to chase the
 * is_array($value) re-assertion guard right below the isStruct() call
 * (lines 74-84), and neither that file nor RestEncoderTest exercises
 * isStruct()'s own boundary conditions or a *nested* recursive flatten()
 * call directly. This file closes those gaps.
 */
test('isStruct returns false for a real sequential array with values that differ from their own index', function (): void {
    // Values are deliberately NOT equal to their own index (10 !== 0, 20 !==
    // 1, 30 !== 2): if `array_keys($data)` on line 50 were ever unwrapped to
    // just `$data` (dropping the array_keys() call), the comparison would
    // instead run against these raw values and wrongly report a struct. Real
    // index-matching values (e.g. [0, 1, 2]) would hide that mutation, so
    // this fixture is chosen specifically to avoid that blind spot. This
    // same fixture also pins every arithmetic mutation of the `0` and `1`
    // literals in `range(0, count($data) - 1)`: shifting either bound by +-1
    // (Increment/DecrementInteger) or flipping `- 1` to `+ 1` (MinusToPlus)
    // all change range()'s output length/start for this 3-element array,
    // which then mismatches array_keys($data) === [0, 1, 2] and wrongly
    // flips the result to true.
    $data = [10, 20, 30];

    expect(ResponseEncoder::isStruct($data))->toBeFalse();
});

test('isStruct returns true for an associative array with string keys', function (): void {
    $data = [
        'id' => 7,
        'name' => 'Alps',
    ];

    expect(ResponseEncoder::isStruct($data))->toBeTrue();
});

test('isStruct returns true for integer keys that do not start at zero', function (): void {
    $data = [
        1 => 'a',
        2 => 'b',
    ];

    expect(ResponseEncoder::isStruct($data))->toBeTrue();
});

test('isStruct returns true for integer keys with a gap', function (): void {
    $data = [
        0 => 'a',
        2 => 'b',
    ];

    expect(ResponseEncoder::isStruct($data))->toBeTrue();
});

test('isStruct returns true for an empty array, because PHP range(0, -1) is not empty', function (): void {
    // A naive reading of `range(0, count($data) - 1) !== array_keys($data)`
    // suggests an empty array should be "not a struct" (both sides empty).
    // That is NOT what PHP actually does: when start > end, range() counts
    // *down* from start to end by default instead of returning an empty
    // array -- range(0, -1) is the 2-element [0, -1], not [].
    //   php -r 'var_dump(range(0, -1));'   // [0, -1], NOT []
    // So for $data = [], count($data) - 1 is -1, range(0, -1) = [0, -1],
    // array_keys([]) = [], and [0, -1] !== [] is true: isStruct([])
    // returns true. This is a genuine surprising corner of the production
    // code (see the "productionBugFound" note in this task's report), but
    // it is provably inert in this codebase: isStruct() has exactly one
    // caller (flatten(), below), and flatten() only ever *acts* on
    // $is_struct via the ATTRIBUTES_KEY isset() check, which is always
    // false for an empty array regardless of $is_struct's value. This test
    // pins the real, current return value either way.
    $data = [];

    expect(ResponseEncoder::isStruct($data))->toBeTrue();
});

test('isStruct returns false for non-array scalar values', function (): void {
    $int = 42;
    $string = 'hello';
    $null = null;

    expect(ResponseEncoder::isStruct($int))->toBeFalse()
        ->and(ResponseEncoder::isStruct($string))->toBeFalse()
        ->and(ResponseEncoder::isStruct($null))->toBeFalse();
});

test('flattenResponse leaves a non-array, non-wrapper scalar value completely unchanged', function (): void {
    // Exercises flatten()'s `if (! is_array($value)) { return; }` early
    // return (line 75) for a value that is neither a NamedArray nor a
    // NamedStruct, so it reaches that guard directly.
    //
    // A RemoveEarlyReturn mutation on that line is a genuine EQUIVALENT
    // MUTANT, confirmed live (sed the `return;` out of the if-block, rerun
    // this whole file, restore): the identical guard a few lines below
    // (`$is_struct = self::isStruct($value); if (! is_array($value)) {
    // return; }`, line 82) always catches the exact same non-array $value
    // right after, because isStruct() only *reads* its by-ref $data
    // parameter (`if (is_array($data)) {...} return false;` -- no
    // assignment anywhere in its body) -- it never converts $value into an
    // array or vice versa. So removing the line-75 return cannot be
    // observed by any test, including this one; it is kept here anyway to
    // pin flattenResponse()'s documented no-op contract for plain scalars.
    $value = 42;

    ResponseEncoder::flattenResponse($value);

    expect($value)
        ->toBe(42);
});

test('flattenResponse unwraps a NamedArray to its raw list content', function (): void {
    $value = new NamedArray([10, 20, 30], 'item');

    ResponseEncoder::flattenResponse($value);

    expect($value)
        ->toBe([10, 20, 30]);
});

test('flattenResponse unwraps a NamedStruct, merging its attributes_xml_ marker key into the result', function (): void {
    $value = new NamedStruct(
        [
            'id' => 7,
            'name' => 'Alps',
            ResponseEncoder::ATTRIBUTES_KEY => [
                'visible' => 1,
            ],
        ],
        []
    );

    ResponseEncoder::flattenResponse($value);

    expect($value)
        ->toBe([
            'id' => 7,
            'name' => 'Alps',
            'visible' => 1,
        ]);
});

test('flattenResponse recursively flattens a nested NamedStruct inside a plain array', function (): void {
    // The outer value is already a plain array (not itself a wrapper), so
    // it reaches the `foreach ($value as $key => &$v) { self::flatten($v); }`
    // loop (lines 93-95) with one element whose value is still a wrapped
    // NamedStruct. If that foreach never actually iterated
    // (ForeachEmptyIterable on line 93) or iterated but never called
    // flatten() on each element (RemoveMethodCall on line 94), the nested
    // value would survive as the raw NamedStruct object instead of being
    // unwrapped+merged -- so asserting on the fully-flattened nested shape
    // proves both the loop itself ran and its body's flatten() call fired.
    $value = [
        'nested' => new NamedStruct(
            [
                'a' => 1,
                ResponseEncoder::ATTRIBUTES_KEY => [
                    'x' => 2,
                ],
            ],
            []
        ),
    ];

    ResponseEncoder::flattenResponse($value);

    expect($value)
        ->toBe([
            'nested' => [
                'a' => 1,
                'x' => 2,
            ],
        ]);
});

test('flattenResponse recursively flattens a nested NamedArray inside a plain list', function (): void {
    // Same recursive-foreach proof as above, but for a *list*-shaped outer
    // array (sequential int keys) containing a nested NamedArray, so the
    // outer isStruct() takes the `false` branch (skips the ATTRIBUTES_KEY
    // merge block entirely) while the foreach at lines 93-95 still has to
    // run and recurse into the single element to unwrap it.
    $value = [
        new NamedArray([1, 2, 3], 'item'),
    ];

    ResponseEncoder::flattenResponse($value);

    expect($value)
        ->toBe([[1, 2, 3]]);
});
