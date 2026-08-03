<?php

declare(strict_types=1);

use Piwigo\Mail\Projection\MailRecipient;
use Piwigo\Users\UserStatus;

/**
 * @return array<string, mixed>
 */
function fullMailRecipientRow(): array
{
    return [
        'user_id' => '3',
        'name' => 'regular_user',
        'email' => 'regular@example.test',
        // A real row's `status` is a UserStatus instance (Phase 5 Item 21:
        // DQL array hydration of an enumType-mapped field), not a raw
        // string -- matches what fromRow()'s real caller actually passes.
        'status' => UserStatus::Normal,
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $recipient = MailRecipient::fromRow(fullMailRecipientRow());

    expect($recipient->userId)->toBe(3)
        ->and($recipient->name)->toBe('regular_user')
        ->and($recipient->email)->toBe('regular@example.test')
        ->and($recipient->status)->toBe('normal');
});

test('fromRow defaults status to null when absent, matching findAdminsAndWebmasters()\'s own shape', function (): void {
    $row = fullMailRecipientRow();
    $row['status'] = null;

    $recipient = MailRecipient::fromRow($row);

    expect($recipient->status)->toBeNull();
    // The NOT NULL columns (user_id/name/email) fall back to their type's
    // zero value instead -- never actually null for a real fetched row.
});

test('fromRow defaults userId to 0 when user_id is missing or non-numeric', function (): void {
    $row = fullMailRecipientRow();
    unset($row['user_id']);

    $recipient = MailRecipient::fromRow($row);

    expect($recipient->userId)->toBe(0);
});

test('fromRow defaults name to an empty string when name is missing or non-string', function (): void {
    $row = fullMailRecipientRow();
    unset($row['name']);

    $recipient = MailRecipient::fromRow($row);

    expect($recipient->name)->toBe('');
});

test('fromRow defaults email to an empty string when email is missing or non-string', function (): void {
    $row = fullMailRecipientRow();
    unset($row['email']);

    $recipient = MailRecipient::fromRow($row);

    expect($recipient->email)->toBe('');
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = MailRecipient::fromRow(fullMailRecipientRow())->toArray();

    expect($roundTripped)->toBe([
        'user_id' => 3,
        'name' => 'regular_user',
        'email' => 'regular@example.test',
        'status' => 'normal',
    ]);
});
