<?php

declare(strict_types=1);

use Piwigo\Ws\Request\ChunkedUploadRequest;

test('fromArray returns defaults for an empty request', function (): void {
    $request = ChunkedUploadRequest::fromArray([]);

    expect($request->requestNamePresent)->toBeFalse()
        ->and($request->requestName)->toBeNull()
        ->and($request->chunk)->toBe(0)
        ->and($request->chunks)->toBe(0);
});

test('fromArray reads a string name', function (): void {
    $request = ChunkedUploadRequest::fromArray(['name' => 'photo.jpg']);

    expect($request->requestNamePresent)->toBeTrue()
        ->and($request->requestName)->toBe('photo.jpg');
});

test('fromArray marks name present even when its value is not a string', function (): void {
    $request = ChunkedUploadRequest::fromArray(['name' => ['nested']]);

    expect($request->requestNamePresent)->toBeTrue()
        ->and($request->requestName)->toBeNull();
});

test('fromArray parses chunk and chunks as ints', function (): void {
    $request = ChunkedUploadRequest::fromArray(['chunk' => '2', 'chunks' => '5']);

    expect($request->chunk)->toBe(2)
        ->and($request->chunks)->toBe(5);
});

test('fromArray falls back to 0 for chunk when its key is set but the value is not scalar', function (): void {
    // isset($request['chunk']) is TRUE here (the key holds a non-null
    // array value) while is_scalar($chunk_raw) is FALSE -- the one case
    // where `&&` and `||` disagree (isset() with a null value is the
    // only other way to make these two operands differ, but isset()
    // itself is false for a null value, so both operators degenerate to
    // the same fallback there). Under the real `&&` guard this must
    // still fall back to 0; under a mutated `||` it would instead reach
    // intval() on a non-empty array, which PHP coerces to 1.
    $request = ChunkedUploadRequest::fromArray(['chunk' => ['unexpected']]);

    expect($request->chunk)->toBe(0);
});

test('fromArray falls back to 0 for chunks when its key is set but the value is not scalar', function (): void {
    $request = ChunkedUploadRequest::fromArray(['chunks' => ['unexpected']]);

    expect($request->chunks)->toBe(0);
});
