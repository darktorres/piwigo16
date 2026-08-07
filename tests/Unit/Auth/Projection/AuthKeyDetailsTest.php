<?php

declare(strict_types=1);

use Piwigo\Auth\Projection\AuthKeyDetails;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Users\UserStatus;

/**
 * @return array<string, mixed>
 */
function fullAuthKeyDetailsRow(): array
{
    return [
        'auth_key_id' => '5',
        // A real row's `user_id` is a UserId instance (UserAuthKeyEntity::$userId
        // is UserId-typed, DQL array hydration applies the custom Type), not a raw
        // scalar -- matches what fromRow()'s real caller actually passes, same reasoning
        // as `status` below.
        'user_id' => UserId::from(1),
        'auth_key' => str_repeat('a', 30),
        // A real row's `expired_on` is a SqlDateTime instance
        // (UserAuthKeyEntity::$expiredOn is SqlDateTime-typed, DQL array
        // hydration applies the custom Type), not a raw string -- same
        // reasoning as `user_id`/`status` above.
        'expired_on' => SqlDateTime::from('2026-08-01 00:00:00'),
        // Real, non-null values (a genuine revoked/notified/apikey row
        // shape) -- all null would leave every `?? null` coalesce on
        // these 3 columns unable to tell a real value apart from one
        // mutated to a bare `null` literal (CoalesceRemoveLeft): either
        // way is_scalar(null) is false and the column defaults to null.
        'revoked_on' => '2026-07-25 08:00:00',
        'last_used_on' => '2026-07-20 12:00:00',
        'last_notified_on' => '2026-07-22 09:00:00',
        'apikey_secret' => 'fixture-secret',
        // A real row's `status` is a UserStatus instance (DQL array
        // hydration of an enumType-mapped field), not a raw string --
        // matches what fromRow()'s real caller actually passes.
        'status' => UserStatus::Normal,
        // A real row's `username`/`email` are Username/Email instances
        // (UserEntity::$username/$mailAddress are VO-typed, DQL array
        // hydration applies the custom Type), not raw strings -- same
        // reasoning as `user_id`/`status` above.
        'username' => Username::from('fixture_admin'),
        'email' => Email::from('fixture_admin@example.test'),
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $key = AuthKeyDetails::fromRow(fullAuthKeyDetailsRow());

    expect($key->authKeyId)->toBe('5')
        ->and($key->userId)->toBe('1')
        ->and($key->authKey)->toBe(str_repeat('a', 30))
        ->and($key->expiredOn)->toBe('2026-08-01 00:00:00')
        ->and($key->revokedOn)->toBe('2026-07-25 08:00:00')
        ->and($key->lastUsedOn)->toBe('2026-07-20 12:00:00')
        ->and($key->lastNotifiedOn)->toBe('2026-07-22 09:00:00')
        ->and($key->apikeySecret)->toBe('fixture-secret')
        ->and($key->status)->toBe('normal')
        ->and($key->username)->toBe('fixture_admin')
        ->and($key->email)->toBe('fixture_admin@example.test');
});

test('fromRow casts a non-string scalar (e.g. a real DBAL int/float) to string for every string-typed column', function (): void {
    // Every column here is_scalar()-guarded, not is_string()-guarded --
    // fullAuthKeyDetailsRow()'s own values are already strings, which
    // can't tell the (string) cast apart from its own removal (strings
    // pass through a `(string)` cast unchanged). A real int/float scalar
    // (as a DBAL driver can hand back for a numeric column) exercises
    // the cast for real, since AuthKeyDetails's own properties are
    // `string`/`?string` and this file has strict_types=1.
    //
    // user_id is deliberately left untouched -- it's instanceof-guarded,
    // not scalar-guarded (see fullAuthKeyDetailsRow()'s own comment), so
    // it isn't part of this scalar-cast group.
    $row = fullAuthKeyDetailsRow();
    $row['auth_key_id'] = 5;
    $row['auth_key'] = 999888777;
    $row['expired_on'] = 20260801;
    $row['revoked_on'] = 20260701;
    $row['last_used_on'] = 3.5;
    $row['last_notified_on'] = 42;
    $row['apikey_secret'] = 7;

    $key = AuthKeyDetails::fromRow($row);

    expect($key->authKeyId)->toBe('5')
        ->and($key->userId)->toBe('1')
        ->and($key->authKey)->toBe('999888777')
        ->and($key->expiredOn)->toBe('20260801')
        ->and($key->revokedOn)->toBe('20260701')
        ->and($key->lastUsedOn)->toBe('3.5')
        ->and($key->lastNotifiedOn)->toBe('42')
        ->and($key->apikeySecret)->toBe('7');
});

test('fromRow casts a raw expired_on string when hydration did not apply the custom Type', function (): void {
    $row = fullAuthKeyDetailsRow();
    $row['expired_on'] = '2026-09-01 00:00:00';

    expect(AuthKeyDetails::fromRow($row)->expiredOn)->toBe('2026-09-01 00:00:00');
});

test('fromRow defaults every NOT NULL column to an empty string when absent', function (): void {
    // fullAuthKeyDetailsRow()-derived tests never exercise these 7
    // columns' own is_scalar()/is_string() failure branch, since the
    // fixture always supplies a real string for them -- only the
    // nullable columns' absence is covered below.
    $key = AuthKeyDetails::fromRow([]);

    expect($key->authKeyId)->toBe('')
        ->and($key->userId)->toBe('')
        ->and($key->authKey)->toBe('')
        ->and($key->expiredOn)->toBe('')
        ->and($key->status)->toBe('')
        ->and($key->username)->toBe('')
        ->and($key->email)->toBe('');
});

test('fromRow defaults every nullable column to null when absent', function (): void {
    $row = fullAuthKeyDetailsRow();
    foreach (['revoked_on', 'last_used_on', 'last_notified_on', 'apikey_secret'] as $key) {
        $row[$key] = null;
    }

    $key = AuthKeyDetails::fromRow($row);

    expect($key->revokedOn)->toBeNull()
        ->and($key->lastUsedOn)->toBeNull()
        ->and($key->lastNotifiedOn)->toBeNull()
        ->and($key->apikeySecret)->toBeNull();
    // The NOT NULL columns (auth_key_id/user_id/auth_key/expired_on/status/
    // username/email) fall back to their type's zero value instead --
    // never actually null for a real fetched row.
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = AuthKeyDetails::fromRow(fullAuthKeyDetailsRow())->toArray();

    expect($roundTripped)->toBe([
        'auth_key_id' => '5',
        'user_id' => '1',
        'auth_key' => str_repeat('a', 30),
        'expired_on' => '2026-08-01 00:00:00',
        'revoked_on' => '2026-07-25 08:00:00',
        'last_used_on' => '2026-07-20 12:00:00',
        'last_notified_on' => '2026-07-22 09:00:00',
        'apikey_secret' => 'fixture-secret',
        'status' => 'normal',
        'username' => 'fixture_admin',
        'email' => 'fixture_admin@example.test',
    ]);
});
