<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\Projection\UsernameById;

test('constructs with distinct values for every property', function (): void {
    $row = new UsernameById(UserId::from(5), 'fixture_user');

    expect($row->id)
        ->toBeInstanceOf(UserId::class)
        ->and($row->id?->value)
        ->toBe(5)
        ->and($row->username)
        ->toBe('fixture_user');
});

test('accepts a null id/username', function (): void {
    $row = new UsernameById(null, null);

    expect($row->id)
        ->toBeNull()
        ->and($row->username)
        ->toBeNull();
});
