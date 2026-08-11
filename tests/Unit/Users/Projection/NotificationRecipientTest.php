<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\Projection\NotificationRecipient;

test('constructs with distinct values for every property', function (): void {
    $row = new NotificationRecipient(UserId::from(5), 'normal', 'en_UK', 'user@example.com', 'fixture_user');

    expect($row->userId)
        ->toBeInstanceOf(UserId::class)
        ->and($row->userId?->value)
        ->toBe(5)
        ->and($row->status)
        ->toBe('normal')
        ->and($row->language)
        ->toBe('en_UK')
        ->and($row->email)
        ->toBe('user@example.com')
        ->and($row->username)
        ->toBe('fixture_user');
});

test('accepts a null userId/status/language/email/username', function (): void {
    $row = new NotificationRecipient(null, null, null, null, null);

    expect($row->userId)
        ->toBeNull()
        ->and($row->status)
        ->toBeNull()
        ->and($row->language)
        ->toBeNull()
        ->and($row->email)
        ->toBeNull()
        ->and($row->username)
        ->toBeNull();
});
