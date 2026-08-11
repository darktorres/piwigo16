<?php

declare(strict_types=1);

use Piwigo\Auth\Projection\ApiKeySummary;

test('toArray maps every property to its snake_case WS RPC key, nulls included', function (): void {
    $summary = new ApiKeySummary(
        authKey: 'auth-key',
        apikeySecret: 'secret',
        apikeyName: 'my key',
        createdOn: '2026-08-01 12:00:00',
        duration: 30,
        expiredOn: '2026-09-01 12:00:00',
        revokedOn: null,
        lastUsedOn: '2026-08-05 09:00:00',
        lastNotifiedOn: null,
        createdOnFormat: 'August 1, 2026',
        expiredOnFormat: 'September 1, 2026',
        lastUsedOnSince: '3 days ago',
        isExpired: false,
        expiration: 'in 27 days',
        expiredOnSince: 'in 27 days',
        revokedOnSince: null,
        revokedOnMessage: null,
    );

    expect($summary->toArray())
        ->toBe([
            'auth_key' => 'auth-key',
            'apikey_secret' => 'secret',
            'apikey_name' => 'my key',
            'created_on' => '2026-08-01 12:00:00',
            'duration' => 30,
            'expired_on' => '2026-09-01 12:00:00',
            'revoked_on' => null,
            'last_used_on' => '2026-08-05 09:00:00',
            'last_notified_on' => null,
            'created_on_format' => 'August 1, 2026',
            'expired_on_format' => 'September 1, 2026',
            'last_used_on_since' => '3 days ago',
            'is_expired' => false,
            'expiration' => 'in 27 days',
            'expired_on_since' => 'in 27 days',
            'revoked_on_since' => null,
            'revoked_on_message' => null,
        ]);
});
