<?php

declare(strict_types=1);

use Piwigo\Auth\Projection\AuthKeyDetails;

/**
 * @return array<string, mixed>
 */
function fullAuthKeyDetailsRow(): array
{
    return [
        'auth_key_id' => '5',
        'user_id' => '1',
        'auth_key' => str_repeat('a', 30),
        'expired_on' => '2026-08-01 00:00:00',
        'revoked_on' => null,
        'last_used_on' => '2026-07-20 12:00:00',
        'last_notified_on' => null,
        'apikey_secret' => null,
        'status' => 'normal',
        'username' => 'fixture_admin',
        'email' => 'fixture_admin@example.test',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $key = AuthKeyDetails::fromRow(fullAuthKeyDetailsRow());

    expect($key->authKeyId)->toBe('5')
        ->and($key->userId)->toBe('1')
        ->and($key->authKey)->toBe(str_repeat('a', 30))
        ->and($key->expiredOn)->toBe('2026-08-01 00:00:00')
        ->and($key->revokedOn)->toBeNull()
        ->and($key->lastUsedOn)->toBe('2026-07-20 12:00:00')
        ->and($key->lastNotifiedOn)->toBeNull()
        ->and($key->apikeySecret)->toBeNull()
        ->and($key->status)->toBe('normal')
        ->and($key->username)->toBe('fixture_admin')
        ->and($key->email)->toBe('fixture_admin@example.test');
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
        'revoked_on' => null,
        'last_used_on' => '2026-07-20 12:00:00',
        'last_notified_on' => null,
        'apikey_secret' => null,
        'status' => 'normal',
        'username' => 'fixture_admin',
        'email' => 'fixture_admin@example.test',
    ]);
});
