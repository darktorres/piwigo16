<?php

declare(strict_types=1);

use Piwigo\Http\ResponseFactory;

test('json encodes the payload with a JSON content type', function (): void {
    $response = ResponseFactory::json([
        'ok' => true,
    ], 201);

    expect($response->getStatusCode())
        ->toBe(201);
    expect($response->getHeaderLine('Content-Type'))
        ->toBe('application/json');
    expect((string) $response->getBody())
        ->toBe('{"ok":true}');
});

test('text sets a plain text content type', function (): void {
    $response = ResponseFactory::text('hello', 404);

    expect($response->getStatusCode())
        ->toBe(404);
    expect($response->getHeaderLine('Content-Type'))
        ->toBe('text/plain');
    expect((string) $response->getBody())
        ->toBe('hello');
});

test('html sets an html content type with utf-8 charset', function (): void {
    $response = ResponseFactory::html('<p>hi</p>');

    expect($response->getStatusCode())
        ->toBe(200);
    expect($response->getHeaderLine('Content-Type'))
        ->toBe('text/html; charset=utf-8');
    expect((string) $response->getBody())
        ->toBe('<p>hi</p>');
});

test('html accepts a custom status code', function (): void {
    $response = ResponseFactory::html('not found', 404);

    expect($response->getStatusCode())
        ->toBe(404);
});

test('raw sends the body through with exactly the given headers', function (): void {
    $response = ResponseFactory::raw('binary-ish content', [
        'Content-Type' => 'application/rss+xml; charset=utf-8; filename=feed.xml',
        'Content-Disposition' => 'inline; filename=feed.xml',
    ]);

    expect($response->getStatusCode())
        ->toBe(200);
    expect($response->getHeaderLine('Content-Type'))
        ->toBe('application/rss+xml; charset=utf-8; filename=feed.xml');
    expect($response->getHeaderLine('Content-Disposition'))
        ->toBe('inline; filename=feed.xml');
    expect((string) $response->getBody())
        ->toBe('binary-ish content');
});

test('raw accepts a custom status code', function (): void {
    $response = ResponseFactory::raw('forbidden', [
        'Content-Type' => 'text/plain',
    ], 403);

    expect($response->getStatusCode())
        ->toBe(403);
});

test('problem builds an RFC 9457 body with the application/problem+json content type', function (): void {
    $response = ResponseFactory::problem('Not Found', 404, 'No resource exists at this path.');

    expect($response->getStatusCode())
        ->toBe(404);
    expect($response->getHeaderLine('Content-Type'))
        ->toBe('application/problem+json');
    expect(json_decode((string) $response->getBody(), true))
        ->toBe([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'No resource exists at this path.',
        ]);
});

test('problem omits detail entirely when not given, rather than a null value', function (): void {
    $response = ResponseFactory::problem('Internal Server Error', 500);

    expect(json_decode((string) $response->getBody(), true))
        ->toBe([
            'type' => 'about:blank',
            'title' => 'Internal Server Error',
            'status' => 500,
        ]);
});

test('problem accepts a custom type URI', function (): void {
    $response = ResponseFactory::problem('Rate limited', 429, type: 'https://piwigo.example/problems/rate-limit');

    expect(json_decode((string) $response->getBody(), true))
        ->toBe([
            'type' => 'https://piwigo.example/problems/rate-limit',
            'title' => 'Rate limited',
            'status' => 429,
        ]);
});
