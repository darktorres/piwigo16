<?php

declare(strict_types=1);

use Piwigo\Admin\Request\AlbumsRequest;
use Piwigo\Validation\InputValidator;

test('fromArrays defaults parentId to -1 when absent', function (): void {
    $request = AlbumsRequest::fromArrays([], [], new InputValidator());

    expect($request->parentId)->toBe(-1)
        ->and($request->simpleAutoOrder)->toBeFalse()
        ->and($request->recursiveAutoOrder)->toBeFalse();
});

test('fromArrays passes parentId through when present', function (): void {
    $request = AlbumsRequest::fromArrays(['parent_id' => '42'], [], new InputValidator());

    expect($request->parentId)->toBe('42');
});

test('fromArrays rejects a non-digit parent_id', function (): void {
    expect(fn (): AlbumsRequest => AlbumsRequest::fromArrays(['parent_id' => '1; DROP TABLE'], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays detects simpleAutoOrder/recursiveAutoOrder and parses id/order', function (): void {
    $request = AlbumsRequest::fromArrays([], [
        'simpleAutoOrder' => '1',
        'order' => 'name ASC',
        'id' => '-1',
    ], new InputValidator());

    expect($request->simpleAutoOrder)->toBeTrue()
        ->and($request->recursiveAutoOrder)->toBeFalse()
        ->and($request->order)->toBe('name ASC')
        ->and($request->id)->toBe('-1')
        ->and($request->rawId)->toBe('-1');
});

test('fromArrays does not validate id when neither auto-order flag is set', function (): void {
    $request = AlbumsRequest::fromArrays([], ['id' => 'not-a-number-but-unvalidated'], new InputValidator());

    expect($request->id)->toBe('')
        ->and($request->rawId)->toBeNull();
});

test('fromArrays rejects a malformed id when an auto-order flag is set', function (): void {
    expect(fn (): AlbumsRequest => AlbumsRequest::fromArrays([], [
        'recursiveAutoOrder' => '1',
        'id' => 'abc',
    ], new InputValidator()))->toThrow(RuntimeException::class);
});

test('fromArrays defaults id to an empty string when present but not a string', function (): void {
    // A non-string id that's still scalar (so validate() passes it as-is
    // against the digit pattern) must fall back to '', not some other
    // placeholder -- rawId keeps the original non-string value.
    $request = AlbumsRequest::fromArrays([], [
        'simpleAutoOrder' => '1',
        'id' => -1,
    ], new InputValidator());

    expect($request->id)->toBe('')
        ->and($request->rawId)->toBe(-1);
});
