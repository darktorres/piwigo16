<?php

declare(strict_types=1);

use Piwigo\Auth\Projection\AuthUser;
use Piwigo\Users\UserStatus;

/**
 * @return array<string, mixed>
 */
function fullAuthUserRow(): array
{
    return [
        'id' => '1',
        'username' => 'fixture_admin',
        'email' => 'fixture_admin@example.test',
        'password' => '$2y$04$hash',
        // A real row's `status` is a UserStatus instance (Phase 5 Item 21:
        // DQL array hydration of an enumType-mapped field), not a raw
        // string -- matches what fromRow()'s real caller actually passes.
        'status' => UserStatus::Webmaster,
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $user = AuthUser::fromRow(fullAuthUserRow());

    expect($user->id)->toBe('1')
        ->and($user->username)->toBe('fixture_admin')
        ->and($user->email)->toBe('fixture_admin@example.test')
        ->and($user->password)->toBe('$2y$04$hash')
        ->and($user->status)->toBe('webmaster');
});

test('fromRow defaults status to normal when absent, matching the original\'s own ??= fallback', function (): void {
    $row = fullAuthUserRow();
    $row['status'] = null;

    $user = AuthUser::fromRow($row);

    expect($user->status)->toBe('normal');
});

test('fromRow defaults every other column to an empty string when absent', function (): void {
    $row = fullAuthUserRow();
    foreach (['id', 'username', 'email', 'password'] as $key) {
        $row[$key] = null;
    }

    $user = AuthUser::fromRow($row);

    expect($user->id)->toBe('')
        ->and($user->username)->toBe('')
        ->and($user->email)->toBe('')
        ->and($user->password)->toBe('');
});

test('fromRow casts a non-string scalar id (e.g. a real DBAL int) to string', function (): void {
    // AuthUser::$id is `string`, and this file has strict_types=1 --
    // without the `(string)` cast on fromRow()'s `id:` line, passing a raw
    // int straight through would TypeError rather than silently coerce.
    // AuthRepository::findByUsernameOrEmail() itself can hand back a
    // native int id depending on the DBAL driver/platform, so this is a
    // real, reachable shape, not just a hypothetical one.
    $row = fullAuthUserRow();
    $row['id'] = 1;

    $user = AuthUser::fromRow($row);

    expect($user->id)->toBe('1');
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = AuthUser::fromRow(fullAuthUserRow())->toArray();

    expect($roundTripped)->toBe([
        'id' => '1',
        'username' => 'fixture_admin',
        'email' => 'fixture_admin@example.test',
        'password' => '$2y$04$hash',
        'status' => 'webmaster',
    ]);
});
