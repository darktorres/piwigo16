<?php

declare(strict_types=1);

use Piwigo\Ws\Request\WsRawRequest;

test('fromArrays reads params from POST when the request is a POST', function (): void {
    $request = WsRawRequest::fromArrays([
        'method' => 'pwg.session.login',
        'username' => 'bob',
    ], [], true);

    expect($request->method)
        ->toBe('pwg.session.login')
        ->and($request->params)
        ->toBe([
            'username' => 'bob',
        ]);
});

test('fromArrays reads params from GET when the request is not a POST', function (): void {
    $request = WsRawRequest::fromArrays([], [
        'method' => 'pwg.session.getStatus',
    ], false);

    expect($request->method)
        ->toBe('pwg.session.getStatus')
        ->and($request->params)
        ->toBe([]);
});

test('fromArrays drops the format key', function (): void {
    $request = WsRawRequest::fromArrays([
        'method' => 'pwg.session.login',
        'format' => 'json',
    ], [], true);

    expect($request->params)
        ->toBe([]);
});

test('fromArrays falls back to GET method when POST has none', function (): void {
    $request = WsRawRequest::fromArrays([
        'username' => 'bob',
    ], [
        'method' => 'pwg.session.login',
    ], true);

    expect($request->method)
        ->toBe('pwg.session.login')
        ->and($request->params)
        ->toBe([
            'username' => 'bob',
        ]);
});

test('fromArrays returns a null method when none is given anywhere', function (): void {
    $request = WsRawRequest::fromArrays([], [], true);

    expect($request->method)
        ->toBeNull();
});

test('fromArrays treats an empty string method as missing', function (): void {
    $request = WsRawRequest::fromArrays([
        'method' => '',
    ], [], true);

    expect($request->method)
        ->toBeNull();
});

test('fromArrays treats a "0" method as missing', function (): void {
    $request = WsRawRequest::fromArrays([
        'method' => '0',
    ], [], true);

    expect($request->method)
        ->toBeNull();
});

test('fromArrays coerces non-string parameter keys to string', function (): void {
    $request = WsRawRequest::fromArrays([
        'method' => 'pwg.session.login',
        0 => 'positional',
    ], [], true);

    expect($request->params)
        ->toBe([
            '0' => 'positional',
        ]);
});

/**
 * [Mutation] The `(string) $name` cast this test above targets (Line
 * 57) is confirmed genuinely inert -- not just untested -- via a
 * temporary sed-applied mutation + a full rerun of this file (reverted
 * after): PHP itself already auto-coerces any numeric-string array key
 * back to an int key on assignment, and $name can only ever be int or
 * string (PHP's own array-key type), so casting an already-int key to
 * string just gets silently coerced right back by the SAME assignment
 * -- the resulting $params array's own key set is byte-identical either
 * way. The test above's own `'0' => 'positional'` literal demonstrates
 * this exact coercion: PHP normalizes that string key back to int 0 in
 * the expected array too, so the assertion can't distinguish the cast
 * from its absence.
 */
test('fromArrays continues past the format key instead of stopping the loop', function (): void {
    $request = WsRawRequest::fromArrays([
        'format' => 'json',
        'foo' => 'bar',
        'method' => 'pwg.session.login',
    ], [], true);

    expect($request->method)
        ->toBe('pwg.session.login')
        ->and($request->params)
        ->toBe([
            'foo' => 'bar',
        ]);
});

test('fromArrays keeps a valid POST method even when GET also carries a method key', function (): void {
    $request = WsRawRequest::fromArrays(
        [
            'method' => 'pwg.session.login',
        ],
        [
            'method' => 'pwg.session.getStatus',
        ],
        true,
    );

    expect($request->method)
        ->toBe('pwg.session.login');
});

test('fromArrays falls back to GET method when the POST method is an empty string', function (): void {
    $request = WsRawRequest::fromArrays(
        [
            'method' => '',
        ],
        [
            'method' => 'pwg.session.login',
        ],
        true,
    );

    expect($request->method)
        ->toBe('pwg.session.login');
});

test('fromArrays falls back to GET method when the POST method is "0"', function (): void {
    $request = WsRawRequest::fromArrays(
        [
            'method' => '0',
        ],
        [
            'method' => 'pwg.session.login',
        ],
        true,
    );

    expect($request->method)
        ->toBe('pwg.session.login');
});
