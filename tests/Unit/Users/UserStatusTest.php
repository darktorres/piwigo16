<?php

declare(strict_types=1);

use Piwigo\Users\UserStatus;

test('cases match the origin user_infos.status ENUM exactly', function (): void {
    expect(UserStatus::Webmaster->value)->toBe('webmaster')
        ->and(UserStatus::Admin->value)->toBe('admin')
        ->and(UserStatus::Normal->value)->toBe('normal')
        ->and(UserStatus::Generic->value)->toBe('generic')
        ->and(UserStatus::Guest->value)->toBe('guest');
});

test('tryFrom resolves a known DB value and returns null for an unknown one', function (): void {
    expect(UserStatus::tryFrom('admin'))->toBe(UserStatus::Admin)
        ->and(UserStatus::tryFrom('bogus'))->toBeNull();
});
