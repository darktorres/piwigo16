<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Controller\Request\ActionRequest;
use Piwigo\Validation\InputValidator;

test('fromArray returns defaults for an empty GET', function (): void {
    $request = ActionRequest::fromArray([], true, new InputValidator());

    expect($request->id)->toBeNull()
        ->and($request->part)->toBeNull()
        ->and($request->formatRequested)->toBeFalse()
        ->and($request->formatId)->toBeNull()
        ->and($request->pwgToken)->toBeNull()
        ->and($request->downloadPresent)->toBeFalse();
});

test('fromArray parses id/part for a valid direct request', function (): void {
    $request = ActionRequest::fromArray(['id' => '42', 'part' => 'e'], true, new InputValidator());

    expect($request->id)->toEqual(ImageId::from(42))
        ->and($request->part)->toBe('e');
});

test('fromArray nulls part for an unrecognized value', function (): void {
    $request = ActionRequest::fromArray(['id' => '42', 'part' => 'x'], true, new InputValidator());

    expect($request->part)->toBeNull();
});

test('fromArray accepts the "r" and "f" part values, not just "e"', function (): void {
    // Kills 2 RemoveArrayItem mutants on the ['e', 'r', 'f'] allow-list
    // (one drops 'r', the other drops 'f') -- the existing tests above
    // only ever exercise 'e' (accepted) and 'x' (rejected), neither of
    // which distinguishes those from a 2-element list missing 'r' or 'f'.
    $r = ActionRequest::fromArray(['part' => 'r'], true, new InputValidator());
    expect($r->part)->toBe('r');

    $f = ActionRequest::fromArray(['part' => 'f'], true, new InputValidator());
    expect($f->part)->toBe('f');
});

test('fromArray nulls id for a non-numeric value', function (): void {
    $request = ActionRequest::fromArray(['id' => 'abc', 'part' => 'e'], true, new InputValidator());

    expect($request->id)->toBeNull();
});

test('fromArray reports formatRequested and formatId when enabled and present', function (): void {
    $request = ActionRequest::fromArray(['format' => '7'], true, new InputValidator());

    expect($request->formatRequested)->toBeTrue()
        ->and($request->formatId)->toBe(7);
});

test('fromArray reports formatRequested false when formats are disabled even if format is present', function (): void {
    $request = ActionRequest::fromArray(['format' => '7'], false, new InputValidator());

    expect($request->formatRequested)->toBeFalse()
        ->and($request->formatId)->toBeNull();
});

test('fromArray rejects a malformed format when formats are enabled', function (): void {
    expect(fn (): ActionRequest => ActionRequest::fromArray(['format' => '1; DROP TABLE'], true, new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray skips format validation when formats are disabled', function (): void {
    $request = ActionRequest::fromArray(['format' => '1; DROP TABLE'], false, new InputValidator());

    expect($request->formatRequested)->toBeFalse();
});

test('fromArray normalizes a non-string pwg_token to null', function (): void {
    $request = ActionRequest::fromArray(['pwg_token' => ['x']], true, new InputValidator());

    expect($request->pwgToken)->toBeNull();
});

test('fromArray passes pwg_token through as a raw string', function (): void {
    $request = ActionRequest::fromArray(['pwg_token' => 'abc123'], true, new InputValidator());

    expect($request->pwgToken)->toBe('abc123');
});

test('fromArray reports downloadPresent when present', function (): void {
    $request = ActionRequest::fromArray(['download' => '1'], true, new InputValidator());

    expect($request->downloadPresent)->toBeTrue();
});
