<?php

declare(strict_types=1);

use Piwigo\Template\Request\TemplateExtentsRequest;

/**
 * A mutation-testing sweep found fromArray()'s own
 * `array_map(strval(...), array_keys($get))` is a confirmed-equivalent
 * mutant against UnwrapArrayMap (which drops the array_map(), leaving
 * bare `array_keys($get)`): PHP array keys can only ever be int or
 * string, and implode() already stringifies non-string elements using
 * the exact same conversion rules as strval() -- so for every possible
 * $get, `implode('', array_keys($get))` and
 * `implode('', array_map(strval(...), array_keys($get)))` produce an
 * identical string. The array_map(strval(...), ...) call exists only to
 * satisfy static analysis (implode()'s stricter stub signature), not to
 * change runtime behavior; live-mutating this line and rerunning this
 * file confirmed all 3 tests below still pass unmutated.
 */
test('fromArray returns empty string for an empty GET', function (): void {
    $request = TemplateExtentsRequest::fromArray([]);

    expect($request->keysConcatenated)->toBe('');
});

test('fromArray concatenates every GET key', function (): void {
    $request = TemplateExtentsRequest::fromArray(['admin' => '1', 'foo' => '2']);

    expect($request->keysConcatenated)->toBe('adminfoo');
});

test('fromArray stringifies a purely-numeric key', function (): void {
    $request = TemplateExtentsRequest::fromArray(['1' => 'value']);

    expect($request->keysConcatenated)->toBe('1');
});
