<?php

declare(strict_types=1);

use Piwigo\Auth\Projection\AuthUser;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Users\UserStatus;

/**
 * @return array<string, mixed>
 */
function fullAuthUserRow(): array
{
    return [
        'id' => '1',
        // A real row's `username`/`email` are Username/Email instances
        // (UserEntity::$username/$mailAddress are VO-typed, DQL array
        // hydration applies the custom Type), not raw strings -- same
        // reasoning as `status` below.
        'username' => Username::from('fixture_admin'),
        'email' => Email::from('fixture_admin@example.test'),
        'password' => '$2y$04$hash',
        // A real row's `status` is a UserStatus instance (DQL array
        // hydration of an enumType-mapped field), not a raw string --
        // matches what fromRow()'s real caller actually passes.
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

test('fromRow unwraps a UserId value object id to its string value', function (): void {
    // AuthRepository::findByUsernameOrEmail() hydrates `id` as a real
    // UserId VO under DQL array hydration (not a raw scalar) -- the
    // `instanceof UserId` branch of the match() must be taken explicitly,
    // not fall through to the generic is_scalar() check (a UserId object
    // is not scalar, so it would otherwise silently default to '').
    // UserId::$value is `int`, so the `(string)` cast is also load-bearing:
    // AuthUser::$id is `string` under strict_types=1, and passing a bare
    // int through would TypeError instead of coercing.
    $row = fullAuthUserRow();
    $row['id'] = UserId::from(42);

    $user = AuthUser::fromRow($row);

    expect($user->id)->toBe('42');
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
