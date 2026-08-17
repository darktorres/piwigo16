<?php

declare(strict_types=1);

use Piwigo\Bootstrap\Request\UserBootstrapRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = UserBootstrapRequest::fromArrays([], []);

    expect($request->logoutRequested)
        ->toBeFalse()
        ->and($request->authKeyPresent)
        ->toBeFalse()
        ->and($request->authKey)
        ->toBeNull()
        ->and($request->username)
        ->toBeNull()
        ->and($request->password)
        ->toBeNull();
});

test('fromArrays reports logoutRequested only for the exact act value', function (): void {
    $logout = UserBootstrapRequest::fromArrays([
        'act' => 'logout',
    ], []);
    $other = UserBootstrapRequest::fromArrays([
        'act' => 'something',
    ], []);

    expect($logout->logoutRequested)
        ->toBeTrue()
        ->and($other->logoutRequested)
        ->toBeFalse();
});

test('fromArrays marks authKeyPresent even for a non-string auth value', function (): void {
    $request = UserBootstrapRequest::fromArrays([
        'auth' => ['nested'],
    ], []);

    expect($request->authKeyPresent)
        ->toBeTrue()
        ->and($request->authKey)
        ->toBeNull();
});

test('fromArrays reads a string auth key', function (): void {
    $request = UserBootstrapRequest::fromArrays([
        'auth' => 'abc123',
    ], []);

    expect($request->authKeyPresent)
        ->toBeTrue()
        ->and($request->authKey)
        ->toBe('abc123');
});

test('fromArrays reads username/password from POST', function (): void {
    $request = UserBootstrapRequest::fromArrays([], [
        'username' => 'alice',
        'password' => 'secret',
    ]);

    expect($request->username)
        ->toBe('alice')
        ->and($request->password)
        ->toBe('secret');
});
