<?php

declare(strict_types=1);

use Piwigo\Core\ValidationPattern;
use Piwigo\Tests\Unit\Validation\InputValidatorTestFakeHtmlRenderer;
use Piwigo\Validation\InputValidator;

test('validate accepts a scalar value matching the pattern', function (): void {
    $validator = new InputValidator();

    expect($validator->validate('id', ['id' => '42'], false, ValidationPattern::ID))->toBeNull();
});

test('validate returns true for a missing optional parameter', function (): void {
    $validator = new InputValidator();

    expect($validator->validate('id', [], false, ValidationPattern::ID))->toBeTrue();
});

test('validate returns true for an empty-string parameter treated as absent', function (): void {
    $validator = new InputValidator();

    expect($validator->validate('id', ['id' => ''], false, ValidationPattern::ID))->toBeTrue();
});

test('validate returns true for a literal "0" parameter treated as absent', function (): void {
    // matches PHP's empty() semantics exactly -- the string "0" is empty
    // just like ''.
    $validator = new InputValidator();

    expect($validator->validate('id', ['id' => '0'], false, ValidationPattern::ID))->toBeTrue();
});

test('validate returns true for an integer 0 parameter treated as absent', function (): void {
    // emptyValue()'s `$value === 0` clause is a strict, same-type check --
    // distinct from the string "0" case above (different literal/branch),
    // and this also proves the check still targets exactly 0 (not -1 or 1,
    // which a corrupted boundary would let slip through as "not empty",
    // reaching the pattern match below and returning null instead of true).
    $validator = new InputValidator();

    expect($validator->validate('id', ['id' => 0], false, ValidationPattern::ID))->toBeTrue();
});

test('validate returns true for a float 0.0 parameter treated as absent', function (): void {
    // Same as the integer-0 case above, but for emptyValue()'s separate
    // `$value === 0.0` clause -- a distinct literal/branch that a strict,
    // same-type check does not share with the integer 0 clause.
    $validator = new InputValidator();

    expect($validator->validate('id', ['id' => 0.0], false, ValidationPattern::ID))->toBeTrue();
});

test('validate raises a hacking-attempt error for a mandatory missing parameter', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('id', [], false, ValidationPattern::ID, true))
        ->toThrow(RuntimeException::class, '[Hacking attempt] the input parameter "id" is not valid');
});

test('validate raises a hacking-attempt error for a scalar value not matching the pattern', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('id', ['id' => 'not-a-number'], false, ValidationPattern::ID))
        ->toThrow(RuntimeException::class, '[Hacking attempt] the input parameter "id" is not valid');
});

test('validate raises a hacking-attempt error for a non-scalar value', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('id', ['id' => ['nested' => 'array']], false, ValidationPattern::ID))
        ->toThrow(RuntimeException::class, '[Hacking attempt] the input parameter "id" is not valid');
});

test('validate accepts every item in an array parameter matching the pattern', function (): void {
    $validator = new InputValidator();

    expect($validator->validate('ids', ['ids' => ['1', '2', '3']], true, ValidationPattern::ID))->toBeNull();
});

test('validate casts a non-string scalar array item to string before matching the pattern', function (): void {
    // $itemToCheck is only guaranteed scalar (line 87's is_scalar() guard),
    // not string -- an int item like 42 reaches preg_match() only because
    // of the (string) cast on line 91. Under strict_types=1, preg_match()
    // rejects a non-string $subject with a TypeError, so if that cast were
    // ever removed, this would throw instead of returning null.
    $validator = new InputValidator();

    expect($validator->validate('ids', ['ids' => [42]], true, ValidationPattern::ID))->toBeNull();
});

test('validate raises a hacking-attempt error when an array item does not match the pattern', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('ids', ['ids' => ['1', 'bad']], true, ValidationPattern::ID))
        ->toThrow(RuntimeException::class, '[Hacking attempt] an item is not valid in input parameter "ids"');
});

test('validate raises a hacking-attempt error when an array key is not numeric', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('ids', ['ids' => ['abc' => '1']], true, ValidationPattern::ID))
        ->toThrow(RuntimeException::class);
});

test('validate raises a hacking-attempt error when is_array is true but the value is not an array', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('ids', ['ids' => 'not-an-array'], true, ValidationPattern::ID))
        ->toThrow(RuntimeException::class, '[Hacking attempt] the input parameter "ids" should be an array');
});

test('validate accepts a custom regex pattern', function (): void {
    $validator = new InputValidator();

    expect($validator->validate('mode', ['mode' => 'lost'], false, '/^(lost|reset)$/'))->toBeNull();
});

test('validate raises a hacking-attempt error for a scalar value when the pattern is the empty string', function (): void {
    // The `$pattern === ''` short-circuit on line 100 must fire on its own
    // -- it stops the empty pattern from ever reaching preg_match(), which
    // would otherwise raise a PHP warning (an empty regex has no valid
    // delimiters; confirmed empirically). The RuntimeException/message
    // this test asserts on end up identical either way, but this
    // project's phpunit.xml.dist (failOnWarning="true") marks the whole
    // test run as failed whenever that warning fires -- confirmed live by
    // sed-mutating this `''` and re-running: still asserts a matching
    // RuntimeException, yet the run exits non-zero on the warning alone.
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('mode', ['mode' => 'anything'], false, ''))
        ->toThrow(RuntimeException::class, '[Hacking attempt] the input parameter "mode" is not valid');
});

test('validate raises a hacking-attempt error for an array item when the pattern is the empty string', function (): void {
    // Same reasoning as the scalar case above, for line 91's own
    // `$pattern === ''` short-circuit. The key ('0', from int key 0) must
    // itself pass ValidationPattern::ID so evaluation actually reaches the
    // `$pattern === ''`-guarded clause instead of failing earlier on the
    // key check.
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('modes', ['modes' => ['a']], true, ''))
        ->toThrow(RuntimeException::class, '[Hacking attempt] an item is not valid in input parameter "modes"');
});

test('validate raises a hacking-attempt error when an array item is itself non-scalar', function (): void {
    // Distinct from "value is not an array" above -- here the outer value
    // IS an array, but one of ITS OWN items is a non-scalar (e.g. a
    // nested array), which has no sane string form to run $pattern
    // against.
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('ids', ['ids' => [['nested' => 'array']]], true, ValidationPattern::ID))
        ->toThrow(RuntimeException::class, '[Hacking attempt] an item is not valid in input parameter "ids"');
});

test('checkUrlFormat rejects a url missing the http(s) protocol prefix', function (): void {
    expect(InputValidator::checkUrlFormat('example.com/no-protocol'))->toBeFalse();
});

test('checkUrlFormat accepts a well-formed http url', function (): void {
    expect(InputValidator::checkUrlFormat('http://example.com/path?query=1'))->toBeTrue();
});

test('checkUrlFormat accepts a well-formed https url', function (): void {
    expect(InputValidator::checkUrlFormat('https://example.com'))->toBeTrue();
});

test('checkUrlFormat rejects a url with a valid protocol prefix but no real host', function (): void {
    // Reaches filter_var(FILTER_VALIDATE_URL) itself (distinct from the
    // protocol-prefix check above, which this string already passes) --
    // confirmed empirically that a bare "http://" fails FILTER_VALIDATE_URL.
    expect(InputValidator::checkUrlFormat('http://'))->toBeFalse();
});

test('checkUrlFormat rejects a non-http(s) url that filter_var itself would otherwise accept', function (): void {
    // 'example.com/no-protocol' above also fails FILTER_VALIDATE_URL on its
    // own (no scheme at all), so it can't tell apart the real
    // protocol-prefix rejection (line 128's `return false`) from merely
    // falling through to line 131's filter_var() call. 'ftp://example.com'
    // closes that gap: it has a well-formed scheme filter_var accepts
    // (confirmed empirically), so only the protocol-prefix check's own
    // early return -- not a fall-through -- can be rejecting it here.
    expect(InputValidator::checkUrlFormat('ftp://example.com'))->toBeFalse();
});

test('fatalError calls the installed HtmlRenderingInterface before throwing, when one is configured', function (): void {
    // $htmlRenderer is a real constructor-injected instance property now
    // (singleton/service-locator elimination campaign, Phase 3) -- every
    // other test above passes none at all, relying on the same default
    // (null) `new InputValidator()` already had before this conversion,
    // reaching the plain `throw new \RuntimeException($msg)` fallback.
    $renderer = new InputValidatorTestFakeHtmlRenderer();
    $validator = new InputValidator($renderer);

    expect(fn (): ?true => $validator->validate('id', [], false, ValidationPattern::ID, true))
        ->toThrow(RuntimeException::class, '[Hacking attempt] the input parameter "id" is not valid');

    // The RuntimeException above is thrown either way (by the fake
    // itself when the renderer IS called, or by InputValidator's own
    // fallback throw when it is NOT) -- only this flag distinguishes
    // whether the `instanceof HtmlRenderingInterface` guard actually
    // ran true and delegated to the renderer first.
    expect($renderer->fatalErrorWasCalled)->toBeTrue()
        ->and($renderer->lastMessage)->toBe('[Hacking attempt] the input parameter "id" is not valid');
});
