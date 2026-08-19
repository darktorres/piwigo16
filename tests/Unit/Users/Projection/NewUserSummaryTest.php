<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\Projection\NewUserSummary;

test('constructs with distinct values for every property', function (): void {
    $summary = new NewUserSummary(
        id: UserId::from(7),
        username: 'alice',
        email: 'alice@example.com',
    );

    expect($summary->id->value)
        ->toBe(7)
        ->and($summary->username)
        ->toBe('alice')
        ->and($summary->email)
        ->toBe('alice@example.com');
});

test('accepts a null email', function (): void {
    $summary = new NewUserSummary(
        id: UserId::from(7),
        username: 'alice',
        email: null,
    );

    expect($summary->email)
        ->toBeNull();
});
