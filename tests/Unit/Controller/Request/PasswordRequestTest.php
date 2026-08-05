<?php

declare(strict_types=1);

use Piwigo\Controller\Request\PasswordRequest;
use Piwigo\Validation\InputValidator;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = PasswordRequest::fromArrays([], [], new InputValidator());

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
    $request = PasswordRequest::fromArrays(['action' => 'reset'], [], new InputValidator());

    expect($request->action)->toBe('reset');
});

test('fromArrays rejects an unrecognized action', function (): void {
    expect(fn (): PasswordRequest => PasswordRequest::fromArrays(['action' => 'not_a_real_action'], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays reports keyPresent and key together', function (): void {
    $request = PasswordRequest::fromArrays(['key' => 'abc123'], [], new InputValidator());

    expect($request->keyPresent)->toBeTrue()
        ->and($request->key)->toBe('abc123');
});

test('fromArrays reports usernameOrEmailPresent for an intentionally empty string', function (): void {
    $request = PasswordRequest::fromArrays([], ['username_or_email' => ''], new InputValidator());

    expect($request->usernameOrEmailPresent)->toBeTrue()
        ->and($request->usernameOrEmail)->toBe('');
});

test('fromArrays reports usernameOrEmailPresent false for a non-string value', function (): void {
    $request = PasswordRequest::fromArrays([], ['username_or_email' => ['x']], new InputValidator());

    expect($request->usernameOrEmailPresent)->toBeFalse()
        ->and($request->usernameOrEmail)->toBe('');
});

test('fromArrays parses a full reset submission', function (): void {
    $request = PasswordRequest::fromArrays([], [
        'submit' => '1',
        'user_code' => '123456',
        'use_new_pwd' => 'newpass',
        'passwordConf' => 'newpass',
    ], new InputValidator());

    expect($request->isSubmitted)->toBeTrue()
        ->and($request->userCode)->toBe('123456')
        ->and($request->newPassword)->toBe('newpass')
        ->and($request->passwordConf)->toBe('newpass');
});

test('fromArrays falls back to an empty string for non-string user_code/use_new_pwd/passwordConf values', function (): void {
    // The `?? ''` default on each of these 3 fields already covers the
    // *absent* case (already string) -- a genuinely non-string but
    // *present* value (an array) is the only way to reach each field's
    // own is_string() ternary else-branch.
    $request = PasswordRequest::fromArrays([], [
        'user_code' => ['x'],
        'use_new_pwd' => ['y'],
        'passwordConf' => ['z'],
    ], new InputValidator());

    expect($request->userCode)->toBe('')
        ->and($request->newPassword)->toBe('')
        ->and($request->passwordConf)->toBe('');
});
