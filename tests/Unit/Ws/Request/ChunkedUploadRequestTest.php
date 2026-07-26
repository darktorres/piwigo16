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
