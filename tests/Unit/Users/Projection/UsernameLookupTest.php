<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\Projection\UsernameLookup;

test('constructs with distinct values for every property', function (): void {
    $row = new UsernameLookup(UserId::from(1), 'fixture_admin', 'fixture_admin@example.test');

    expect($row->id->value)->toBe(1)
        ->and($row->username)->toBe('fixture_admin')
        ->and($row->email)->toBe('fixture_admin@example.test');
});

test('accepts an empty email', function (): void {
    $row = new UsernameLookup(UserId::from(1), 'fixture_admin', '');

    expect($row->email)->toBe('');
});
