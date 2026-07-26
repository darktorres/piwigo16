<?php

declare(strict_types=1);

use Piwigo\Ws\Request\WsFormatRequest;

test('fromArray defaults to rest when format is absent', function (): void {
    $request = WsFormatRequest::fromArray([]);

    expect($request->responseFormat)->toBe('rest');
});

test('fromArray reads a given format', function (): void {
    $request = WsFormatRequest::fromArray(['format' => 'json']);

    expect($request->responseFormat)->toBe('json');
});

test('fromArray coerces a non-string format to an empty string', function (): void {
    $request = WsFormatRequest::fromArray(['format' => ['x']]);

    expect($request->responseFormat)->toBe('');
});
