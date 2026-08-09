<?php

declare(strict_types=1);

use Piwigo\Auth\Projection\ApiKeyCreated;

test('toArray maps every property to its snake_case WS RPC key', function (): void {
    $created = new ApiKeyCreated(
        authKey: 'auth-key',
        apikeySecret: 'secret',
        apikeyName: 'my key',
        userId: 5,
        createdOn: '2026-08-01 12:00:00',
        duration: 30,
        keyType: 'standard',
        expiredOn: '2026-09-01 12:00:00',
    );

    expect($created->toArray())->toBe([
        'auth_key' => 'auth-key',
        'apikey_secret' => 'secret',
        'apikey_name' => 'my key',
        'user_id' => 5,
        'created_on' => '2026-08-01 12:00:00',
        'duration' => 30,
        'key_type' => 'standard',
        'expired_on' => '2026-09-01 12:00:00',
    ]);
});
