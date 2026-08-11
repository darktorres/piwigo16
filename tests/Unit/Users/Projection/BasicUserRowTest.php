<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\Projection\BasicUserRow;

test('constructs with distinct values for every property', function (): void {
    $row = new BasicUserRow(UserId::from(3), 'fixture_admin', 'hash', 'admin@example.com');

    expect($row->id->value)
        ->toBe(3)
        ->and($row->username)
        ->toBe('fixture_admin')
        ->and($row->password)
        ->toBe('hash')
        ->and($row->email)
        ->toBe('admin@example.com');
});

test('toArray round-trips to the snake_case shape, unwrapping id to a plain int', function (): void {
    $row = new BasicUserRow(UserId::from(3), 'fixture_admin', 'hash', 'admin@example.com');

    expect($row->toArray())
        ->toBe([
            'id' => 3,
            'username' => 'fixture_admin',
            'password' => 'hash',
            'email' => 'admin@example.com',
        ]);
});

test('accepts a null password/email', function (): void {
    $row = new BasicUserRow(UserId::from(3), 'fixture_admin', null, null);

    expect($row->password)
        ->toBeNull()
        ->and($row->email)
        ->toBeNull();
});
