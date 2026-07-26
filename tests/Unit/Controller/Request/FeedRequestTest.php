<?php

declare(strict_types=1);

use Piwigo\Controller\Request\FeedRequest;

test('fromArray accepts a well-formed 50-char feed token', function (): void {
    $token = str_repeat('a1', 25);
    $request = FeedRequest::fromArray(['feed' => $token]);

    expect($request->feedId)->toBe($token);
});

test('fromArray defaults to an empty feed id when absent', function (): void {
    $request = FeedRequest::fromArray([]);

    expect($request->feedId)->toBe('')
        ->and($request->imageOnly)->toBeFalse();
});

test('fromArray rejects a malformed feed token', function (): void {
    expect(fn (): FeedRequest => FeedRequest::fromArray(['feed' => 'too-short']))
        ->toThrow(RuntimeException::class);
});

test('fromArray recognizes the image_only presence flag', function (): void {
    $request = FeedRequest::fromArray(['image_only' => '']);

    expect($request->imageOnly)->toBeTrue();
});
