<?php

declare(strict_types=1);

use Piwigo\Http\ResponseFactory;

test('json encodes the payload with a JSON content type', function (): void {
    $response = ResponseFactory::json(['ok' => true], 201);

    expect($response->getStatusCode())->toBe(201);
    expect($response->getHeaderLine('Content-Type'))->toBe('application/json');
    expect((string) $response->getBody())->toBe('{"ok":true}');
});

test('text sets a plain text content type', function (): void {
    $response = ResponseFactory::text('hello', 404);

    expect($response->getStatusCode())->toBe(404);
    expect($response->getHeaderLine('Content-Type'))->toBe('text/plain');
    expect((string) $response->getBody())->toBe('hello');
});
