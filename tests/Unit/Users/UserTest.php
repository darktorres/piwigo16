<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\UserId;
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
        'forbidden_categories' => '3,8,12',
        'level' => '4',
        'preferences' => ['show_tags' => 'yes', 0 => 'dropped'],
    ]);

    expect($user->id->value)->toBe(7)
        ->and($user->username)->toBe('alice')
        ->and($user->email)->toBe('alice@example.com')
        ->and($user->language)->toBe('en_UK')
        ->and($user->theme)->toBe('modus')
        ->and($user->status)->toBe(UserStatus::Admin)
        ->and($user->enabledHigh)->toBeTrue()
        ->and($user->forbiddenCategories)->toBe('3,8,12')
        ->and($user->level)->toBe(4)
        ->and($user->preferences)->toBe(['show_tags' => 'yes'])
        ->and($user->rawAttributes)->toHaveKey('id');
});

test('fromUserArray throws on a missing/malformed id', function (): void {
    User::fromUserArray([]);
})->throws(InvalidArgumentException::class);

test('fromUserArray degrades safely on a missing/malformed non-id field', function (): void {
    $user = User::fromUserArray(['id' => 7]);

    expect($user->id->value)->toBe(7)
        ->and($user->username)->toBe('')
        ->and($user->email)->toBe('')
        ->and($user->language)->toBe('')
        ->and($user->theme)->toBe('')
        ->and($user->status)->toBe(UserStatus::Guest)
        ->and($user->enabledHigh)->toBeFalse()
        ->and($user->forbiddenCategories)->toBe('')
        ->and($user->level)->toBe(0)
        ->and($user->preferences)->toBe([]);
});

test('fromUserArray falls back to Guest for an unrecognized status value', function (): void {
    $user = User::fromUserArray(['id' => 1, 'status' => 'not_a_real_status']);

    expect($user->status)->toBe(UserStatus::Guest);
});

test('withLanguage returns a new immutable instance, original is untouched', function (): void {
    $original = new User(
        id: UserId::from(1),
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
        id: UserId::from(1),
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

test('withLevel returns a new immutable instance and syncs rawAttributes', function (): void {
    $original = new User(
        id: UserId::from(1),
        username: 'bob',
        email: '',
        language: 'en_UK',
        theme: 'modus',
        status: UserStatus::Normal,
        enabledHigh: false,
        level: 4,
        rawAttributes: ['level' => 4],
    );

    $updated = $original->withLevel(8);

    expect($updated->level)->toBe(8)
        ->and($updated->rawAttributes)->toBe(['level' => 8])
        ->and($original->level)->toBe(4)
        ->and($original->rawAttributes)->toBe(['level' => 4])
        ->and($updated)->not->toBe($original);
});

test('withEnabledHigh returns a new immutable instance and syncs rawAttributes', function (): void {
    $original = new User(
        id: UserId::from(1),
        username: 'bob',
        email: '',
        language: 'en_UK',
        theme: 'modus',
        status: UserStatus::Normal,
        enabledHigh: false,
        rawAttributes: ['enabled_high' => false],
    );

    $updated = $original->withEnabledHigh(true);

    expect($updated->enabledHigh)->toBeTrue()
        ->and($updated->rawAttributes)->toBe(['enabled_high' => true])
        ->and($original->enabledHigh)->toBeFalse()
        ->and($original->rawAttributes)->toBe(['enabled_high' => false]);
});

test('withRawAttribute adds a key without disturbing the rest of the array', function (): void {
    $original = new User(
        id: UserId::from(1),
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
