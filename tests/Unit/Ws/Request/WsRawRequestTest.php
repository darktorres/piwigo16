<?php

declare(strict_types=1);

use Piwigo\Ws\Request\WsRawRequest;

test('fromArrays reads params from POST when the request is a POST', function (): void {
    $request = WsRawRequest::fromArrays(['method' => 'pwg.session.login', 'username' => 'bob'], [], true);

    expect($request->method)->toBe('pwg.session.login')
        ->and($request->params)->toBe(['username' => 'bob']);
});

test('fromArrays reads params from GET when the request is not a POST', function (): void {
    $request = WsRawRequest::fromArrays([], ['method' => 'pwg.session.getStatus'], false);

    expect($request->method)->toBe('pwg.session.getStatus')
        ->and($request->params)->toBe([]);
});

test('fromArrays drops the format key', function (): void {
    $request = WsRawRequest::fromArrays(['method' => 'pwg.session.login', 'format' => 'json'], [], true);

    expect($request->params)->toBe([]);
});

test('fromArrays falls back to GET method when POST has none', function (): void {
    $request = WsRawRequest::fromArrays(['username' => 'bob'], ['method' => 'pwg.session.login'], true);

    expect($request->method)->toBe('pwg.session.login')
        ->and($request->params)->toBe(['username' => 'bob']);
});

test('fromArrays returns a null method when none is given anywhere', function (): void {
    $request = WsRawRequest::fromArrays([], [], true);

    expect($request->method)->toBeNull();
});

test('fromArrays treats an empty string method as missing', function (): void {
    $request = WsRawRequest::fromArrays(['method' => ''], [], true);

    expect($request->method)->toBeNull();
});

test('fromArrays treats a "0" method as missing', function (): void {
    $request = WsRawRequest::fromArrays(['method' => '0'], [], true);

    expect($request->method)->toBeNull();
});

test('fromArrays coerces non-string parameter keys to string', function (): void {
    $request = WsRawRequest::fromArrays(['method' => 'pwg.session.login', 0 => 'positional'], [], true);

    expect($request->params)->toBe(['0' => 'positional']);
});
