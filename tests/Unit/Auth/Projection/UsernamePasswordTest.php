<?php

declare(strict_types=1);

use Piwigo\Auth\Projection\UsernamePassword;

test('constructs with the given username and password', function (): void {
    $found = new UsernamePassword('fixture_admin', '$2y$10$hash');

    expect($found->username)->toBe('fixture_admin')
        ->and($found->password)->toBe('$2y$10$hash');
});
