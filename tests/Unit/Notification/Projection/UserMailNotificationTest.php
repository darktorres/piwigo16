<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\UserId;
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

    expect($notification->userId)
        ->toEqual(UserId::from(1))
        ->and($notification->checkKey)
        ->toBe('abcdef1234567890')
        ->and($notification->username)
        ->toBe('fixture_admin')
        ->and($notification->mailAddress)
        ->toBe('fixture_admin@example.test')
        ->and($notification->enabled)
        ->toBeTrue()
        ->and($notification->lastSend)
        ->toBe('2026-07-01 00:00:00')
        ->and($notification->status)
        ->toBe('webmaster');
});

test('fromRow keeps an already-hydrated UserId instance as-is', function (): void {
    // Covers the getArrayResult() Gotcha #1 shape: a real Doctrine array
    // hydration would already have converted user_id via UserIdType.
    $row = fullUserMailNotificationRow();
    $row['user_id'] = UserId::from(4);

    expect(UserMailNotification::fromRow($row)->userId)->toEqual(UserId::from(4));
});

test('fromRow defaults every nullable column to null when absent', function (): void {
    $row = fullUserMailNotificationRow();
    foreach (['last_send', 'status'] as $key) {
        $row[$key] = null;
    }

    $notification = UserMailNotification::fromRow($row);

    expect($notification->lastSend)
        ->toBeNull()
        ->and($notification->status)
        ->toBeNull();
    // The NOT NULL columns (user_id/check_key/username/mail_address) fall
    // back to their type's zero value instead -- never actually null for a
    // real fetched row. See the dedicated tests below for those fallbacks'
    // exact values.
});

test('fromRow defaults the NOT NULL string columns to their type\'s zero value when absent', function (): void {
    $row = fullUserMailNotificationRow();
    foreach (['check_key', 'username', 'mail_address'] as $key) {
        $row[$key] = null;
    }

    $notification = UserMailNotification::fromRow($row);

    expect($notification->checkKey)
        ->toBe('')
        ->and($notification->username)
        ->toBe('')
        ->and($notification->mailAddress)
        ->toBe('');
});

test('fromRow defaults enabled to false when absent', function (): void {
    $row = fullUserMailNotificationRow();
    $row['enabled'] = null;

    expect(UserMailNotification::fromRow($row)->enabled)->toBeFalse();
});

test('fromRow throws when user_id is missing', function (): void {
    $row = fullUserMailNotificationRow();
    $row['user_id'] = null;

    expect(fn () => UserMailNotification::fromRow($row))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive user id, got null');
});

test('fromRow throws with the real debug type of a non-null but invalid user_id', function (): void {
    $row = fullUserMailNotificationRow();
    $row['user_id'] = 'not-a-number';

    expect(fn () => UserMailNotification::fromRow($row))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive user id, got string');
});

test('fromRow casts a native scalar enabled column, matching mysqli-native-types results', function (): void {
    // A real tinyint(1) `enabled` column comes back as a native int under
    // this project's MYSQLI_OPT_INT_AND_FLOAT_NATIVE setting, not a string
    // -- fromRow() must (bool)-cast it correctly either way.
    $row = fullUserMailNotificationRow();
    $row['enabled'] = 1;

    expect(UserMailNotification::fromRow($row)->enabled)->toBeTrue();

    $row['enabled'] = 0;
    expect(UserMailNotification::fromRow($row)->enabled)->toBeFalse();
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = UserMailNotification::fromRow(fullUserMailNotificationRow())->toArray();

    expect($roundTripped)
        ->toBe([
            'user_id' => 1,
            'check_key' => 'abcdef1234567890',
            'username' => 'fixture_admin',
            'mail_address' => 'fixture_admin@example.test',
            'enabled' => true,
            'last_send' => '2026-07-01 00:00:00',
            'status' => 'webmaster',
        ]);
});
