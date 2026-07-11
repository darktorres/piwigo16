<?php

declare(strict_types=1);

use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

test('fromUserArray coerces a real legacy $user row', function (): void {
    $user = User::fromUserArray([
        'id' => '7',
        'username' => 'alice',
        'email' => 'alice@example.com',
        'language' => 'en_UK',
        'theme' => 'modus',
        'status' => 'admin',
        'enabled_high' => '1',
    ]);

    expect($user->id)->toBe(7)
        ->and($user->username)->toBe('alice')
        ->and($user->status)->toBe(UserStatus::Admin)
        ->and($user->enabledHigh)->toBeTrue()
        ->and($user->rawAttributes)->toHaveKey('id');
});

test('fromUserArray degrades safely on a missing/malformed row', function (): void {
    $user = User::fromUserArray([]);

    expect($user->id)->toBe(0)
        ->and($user->username)->toBe('')
        ->and($user->status)->toBe(UserStatus::Guest)
        ->and($user->enabledHigh)->toBeFalse();
});

test('fromUserArray falls back to Guest for an unrecognized status value', function (): void {
    $user = User::fromUserArray(['status' => 'not_a_real_status']);

    expect($user->status)->toBe(UserStatus::Guest);
});

test('withLanguage returns a new immutable instance, original is untouched', function (): void {
    $original = new User(
        id: 1,
        username: 'bob',
        email: '',
        language: 'en_UK',
        theme: 'modus',
        status: UserStatus::Normal,
        enabledHigh: false,
    );

    $updated = $original->withLanguage('fr_FR');

    expect($updated->language)->toBe('fr_FR')
        ->and($original->language)->toBe('en_UK')
        ->and($updated)->not->toBe($original);
});

test('withUsername returns a new immutable instance', function (): void {
    $original = new User(
        id: 1,
        username: 'bob',
        email: '',
        language: 'en_UK',
        theme: 'modus',
        status: UserStatus::Normal,
        enabledHigh: false,
    );

    $updated = $original->withUsername('robert');

    expect($updated->username)->toBe('robert')
        ->and($original->username)->toBe('bob');
});

test('withRawAttribute adds a key without disturbing the rest of the array', function (): void {
    $original = new User(
        id: 1,
        username: 'bob',
        email: '',
        language: 'en_UK',
        theme: 'modus',
        status: UserStatus::Normal,
        enabledHigh: false,
        rawAttributes: ['existing' => 'value'],
    );

    $updated = $original->withRawAttribute('new_key', 'new_value');

    expect($updated->rawAttributes)->toBe(['existing' => 'value', 'new_key' => 'new_value'])
        ->and($original->rawAttributes)->toBe(['existing' => 'value']);
});
