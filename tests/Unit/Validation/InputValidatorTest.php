<?php

declare(strict_types=1);

use Piwigo\Core\ValidationPattern;
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

test('validate raises a hacking-attempt error for a mandatory missing parameter', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('id', [], false, ValidationPattern::ID, true))
        ->toThrow(RuntimeException::class, '[Hacking attempt] the input parameter "id" is not valid');
});

test('validate raises a hacking-attempt error for a scalar value not matching the pattern', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('id', ['id' => 'not-a-number'], false, ValidationPattern::ID))
        ->toThrow(RuntimeException::class);
});

test('validate raises a hacking-attempt error for a non-scalar value', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('id', ['id' => ['nested' => 'array']], false, ValidationPattern::ID))
        ->toThrow(RuntimeException::class);
});

test('validate accepts every item in an array parameter matching the pattern', function (): void {
    $validator = new InputValidator();

    expect($validator->validate('ids', ['ids' => ['1', '2', '3']], true, ValidationPattern::ID))->toBeNull();
});

test('validate raises a hacking-attempt error when an array item does not match the pattern', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('ids', ['ids' => ['1', 'bad']], true, ValidationPattern::ID))
        ->toThrow(RuntimeException::class);
});

test('validate raises a hacking-attempt error when an array key is not numeric', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('ids', ['ids' => ['abc' => '1']], true, ValidationPattern::ID))
        ->toThrow(RuntimeException::class);
});

test('validate raises a hacking-attempt error when is_array is true but the value is not an array', function (): void {
    $validator = new InputValidator();

    expect(fn (): ?true => $validator->validate('ids', ['ids' => 'not-an-array'], true, ValidationPattern::ID))
        ->toThrow(RuntimeException::class);
});

test('validate accepts a custom regex pattern', function (): void {
    $validator = new InputValidator();

    expect($validator->validate('mode', ['mode' => 'lost'], false, '/^(lost|reset)$/'))->toBeNull();
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
