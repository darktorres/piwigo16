<?php

declare(strict_types=1);

use Piwigo\Notification\Projection\UserMailNotification;
use Piwigo\Users\UserStatus;

/**
 * @return array<string, mixed>
 */
function fullUserMailNotificationRow(): array
{
    return [
        'user_id' => '1',
        'check_key' => 'abcdef1234567890',
        'username' => 'fixture_admin',
        'mail_address' => 'fixture_admin@example.test',
        'enabled' => '1',
        'last_send' => '2026-07-01 00:00:00',
        // A real row's `status` is a UserStatus instance (DQL array
        // hydration of an enumType-mapped field), not a raw string --
        // matches what fromRow()'s real caller actually passes.
        'status' => UserStatus::Webmaster,
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $notification = UserMailNotification::fromRow(fullUserMailNotificationRow());

    expect($notification->userId)->toBe(1)
        ->and($notification->checkKey)->toBe('abcdef1234567890')
        ->and($notification->username)->toBe('fixture_admin')
        ->and($notification->mailAddress)->toBe('fixture_admin@example.test')
        ->and($notification->enabled)->toBe('1')
        ->and($notification->lastSend)->toBe('2026-07-01 00:00:00')
        ->and($notification->status)->toBe('webmaster');
});

test('fromRow defaults every nullable column to null when absent', function (): void {
    $row = fullUserMailNotificationRow();
    foreach (['enabled', 'last_send', 'status'] as $key) {
        $row[$key] = null;
    }

    $notification = UserMailNotification::fromRow($row);

    expect($notification->enabled)->toBeNull()
        ->and($notification->lastSend)->toBeNull()
        ->and($notification->status)->toBeNull();
    // The NOT NULL columns (user_id/check_key/username/mail_address) fall
    // back to their type's zero value instead -- never actually null for a
    // real fetched row. See the dedicated test below for that fallback's
    // exact value.
});

test('fromRow defaults the NOT NULL columns to their type\'s zero value when absent', function (): void {
    $row = fullUserMailNotificationRow();
    foreach (['user_id', 'check_key', 'username', 'mail_address'] as $key) {
        $row[$key] = null;
    }

    $notification = UserMailNotification::fromRow($row);

    expect($notification->userId)->toBe(0)
        ->and($notification->checkKey)->toBe('')
        ->and($notification->username)->toBe('')
        ->and($notification->mailAddress)->toBe('');
});

test('fromRow string-casts a native scalar enabled column, matching mysqli-native-types results', function (): void {
    // The class docblock's "real bug found live" section: a real tinyint(1)
    // `enabled` column comes back as a native int under this project's
    // MYSQLI_OPT_INT_AND_FLOAT_NATIVE setting, not a string -- fromRow()
    // must (string)-cast it, not pass the native scalar straight through
    // (the constructor's `?string $enabled` would TypeError on a bare int
    // under this file's own strict_types=1 otherwise).
    $row = fullUserMailNotificationRow();
    $row['enabled'] = 1;

    $notification = UserMailNotification::fromRow($row);

    expect($notification->enabled)->toBe('1');
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = UserMailNotification::fromRow(fullUserMailNotificationRow())->toArray();

    expect($roundTripped)->toBe([
        'user_id' => 1,
        'check_key' => 'abcdef1234567890',
        'username' => 'fixture_admin',
        'mail_address' => 'fixture_admin@example.test',
        'enabled' => '1',
        'last_send' => '2026-07-01 00:00:00',
        'status' => 'webmaster',
    ]);
});
