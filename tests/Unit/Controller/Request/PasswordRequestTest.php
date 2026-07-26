<?php

declare(strict_types=1);

use Piwigo\Controller\Request\PasswordRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = PasswordRequest::fromArrays([], []);

    expect($request->action)->toBeNull()
        ->and($request->isSubmitted)->toBeFalse()
        ->and($request->keyPresent)->toBeFalse()
        ->and($request->key)->toBeNull()
        ->and($request->usernameOrEmailPresent)->toBeFalse()
        ->and($request->usernameOrEmail)->toBe('')
        ->and($request->userCode)->toBe('')
        ->and($request->newPassword)->toBe('')
        ->and($request->passwordConf)->toBe('');
});

test('fromArrays parses a recognized action', function (): void {
    $request = PasswordRequest::fromArrays(['action' => 'reset'], []);

    expect($request->action)->toBe('reset');
});

test('fromArrays rejects an unrecognized action', function (): void {
    expect(fn (): PasswordRequest => PasswordRequest::fromArrays(['action' => 'not_a_real_action'], []))
        ->toThrow(RuntimeException::class);
});

test('fromArrays reports keyPresent and key together', function (): void {
    $request = PasswordRequest::fromArrays(['key' => 'abc123'], []);

    expect($request->keyPresent)->toBeTrue()
        ->and($request->key)->toBe('abc123');
});

test('fromArrays reports usernameOrEmailPresent for an intentionally empty string', function (): void {
    $request = PasswordRequest::fromArrays([], ['username_or_email' => '']);

    expect($request->usernameOrEmailPresent)->toBeTrue()
        ->and($request->usernameOrEmail)->toBe('');
});

test('fromArrays reports usernameOrEmailPresent false for a non-string value', function (): void {
    $request = PasswordRequest::fromArrays([], ['username_or_email' => ['x']]);

    expect($request->usernameOrEmailPresent)->toBeFalse()
        ->and($request->usernameOrEmail)->toBe('');
});

test('fromArrays parses a full reset submission', function (): void {
    $request = PasswordRequest::fromArrays([], [
        'submit' => '1',
        'user_code' => '123456',
        'use_new_pwd' => 'newpass',
        'passwordConf' => 'newpass',
    ]);

    expect($request->isSubmitted)->toBeTrue()
        ->and($request->userCode)->toBe('123456')
        ->and($request->newPassword)->toBe('newpass')
        ->and($request->passwordConf)->toBe('newpass');
});
